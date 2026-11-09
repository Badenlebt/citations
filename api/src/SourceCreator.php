<?php

declare(strict_types=1);

namespace Citations;

use Citations\Repository\SourceRepositoryInterface;
use Citations\TextNormalizer;

/**
 * Create and edit mutations of the Autoren-API against the repository boundary
 * (Requirements 10.1, 10.3, 10.4, 10.6; design "`POST /api/sources`" and
 * "`PUT /api/sources/{id}`").
 *
 * This is the create/edit happy path: it trims the submitted name, normalizes
 * the optional free-text fields (birth date, death date, description) and the
 * optional link URL, then persists the source. Creating returns the generated
 * id (10.1); editing overwrites an already existing source with the new values
 * (10.6).
 *
 * Outcomes are returned as a {@see SourceMutationResult} rather than thrown, so
 * the endpoint maps a reason {@see ErrorCode} to its HTTP status uniformly.
 * Lengths are measured in Unicode characters (mb_strlen), matching the
 * "nach Entfernen umschließender Leerzeichen" wording and the length handling
 * used elsewhere (e.g. {@see QuoteCreator}), so multibyte text is not penalized
 * by its byte size.
 *
 * Scope: this class covers the valid-input create/edit path including the
 * length bounds (name 1–200, optional fields 0–500, link URL 0–2000), the
 * link-URL `http(s)://`-prefix rule (10.4) and the reserved-"unbekannt"-name
 * invariant (13.2): no create, and no edit of any OTHER source, may take the
 * Unbekannt-Quelle's name (case-/diacritics-insensitive) — such attempts are
 * rejected with UNKNOWN_SOURCE_RESERVED and change nothing, while editing the
 * Unbekannt-Quelle itself to keep that name stays allowed.
 */
final class SourceCreator
{
    /** Maximum source name length in Unicode characters after trimming (10.1). */
    public const MAX_NAME_LENGTH = 200;

    /** Maximum length of an optional free-text field in Unicode characters (10.3). */
    public const MAX_OPTIONAL_FIELD_LENGTH = 500;

    /** Maximum link-URL length in Unicode characters (10.4). */
    public const MAX_LINK_URL_LENGTH = 2000;

    public function __construct(
        private readonly SourceRepositoryInterface $sources,
    ) {
    }

    /**
     * Create a source and return its id, or a reason code on rejection.
     *
     * Semantics (in order):
     *   1. The name is trimmed of surrounding whitespace. An empty trimmed name
     *      is rejected with MISSING_NAME, and no source is created.
     *   2. A trimmed name longer than {@see self::MAX_NAME_LENGTH} characters,
     *      or an optional field / link URL over its bound, is rejected with
     *      INPUT_TOO_LONG, and no source is created (10.3, 10.4).
     *   3. A set link URL must start with `http://` or `https://`; otherwise the
     *      create is rejected with INVALID_LINK_URL (10.4), and no source is
     *      created.
     *   4. On success exactly one source is stored with the trimmed name and the
     *      normalized optional fields, and its generated id is returned (10.1).
     *
     * @param string      $name        Raw, un-trimmed source name as submitted.
     * @param string|null $birthDate   Optional birth-date free text (10.3).
     * @param string|null $deathDate   Optional death-date free text (10.3).
     * @param string|null $description Optional short-description free text (10.3).
     * @param string|null $linkUrl     Optional link URL (10.4).
     */
    public function create(
        string $name,
        ?string $birthDate = null,
        ?string $deathDate = null,
        ?string $description = null,
        ?string $linkUrl = null,
    ): SourceMutationResult {
        $prepared = $this->prepare($name, $birthDate, $deathDate, $description, $linkUrl);
        if (!$prepared->isSuccess) {
            return $prepared->error;
        }

        // (13.2) The name "unbekannt" (case-insensitive) is reserved for the
        // one Unbekannt-Quelle; no new source may take it.
        if ($this->isReservedName($prepared->name)) {
            return SourceMutationResult::failure(ErrorCode::UNKNOWN_SOURCE_RESERVED);
        }

        // (10.1) Persist exactly one source and return its generated id.
        $id = $this->sources->insert($prepared->name, $prepared->optional);

        return SourceMutationResult::success($id);
    }

    /**
     * Overwrite an existing source with valid values (10.6), or return a reason
     * code on rejection.
     *
     * Validation mirrors {@see self::create()} exactly (same name trim, length
     * bounds and link-URL prefix rule). When the id references no existing
     * source the edit is rejected with NOT_FOUND and nothing is changed; on
     * success the stored source carries the trimmed name and normalized
     * optional fields, and its id is returned.
     *
     * @param int         $id          Id of the source to overwrite (10.6).
     * @param string      $name        Raw, un-trimmed source name as submitted.
     * @param string|null $birthDate   Optional birth-date free text (10.3).
     * @param string|null $deathDate   Optional death-date free text (10.3).
     * @param string|null $description Optional short-description free text (10.3).
     * @param string|null $linkUrl     Optional link URL (10.4).
     */
    public function edit(
        int $id,
        string $name,
        ?string $birthDate = null,
        ?string $deathDate = null,
        ?string $description = null,
        ?string $linkUrl = null,
    ): SourceMutationResult {
        $prepared = $this->prepare($name, $birthDate, $deathDate, $description, $linkUrl);
        if (!$prepared->isSuccess) {
            return $prepared->error;
        }

        // (13.2) The reserved name "unbekannt" may only belong to the one
        // Unbekannt-Quelle. Editing the Unbekannt-Quelle itself to keep that
        // name is allowed; naming ANY OTHER source "unbekannt" is rejected and
        // changes nothing.
        if ($this->isReservedName($prepared->name) && !$this->isUnknownSource($id)) {
            return SourceMutationResult::failure(ErrorCode::UNKNOWN_SOURCE_RESERVED);
        }

        // (10.6) Overwrite the existing source; a missing id changes nothing.
        if (!$this->sources->update($id, $prepared->name, $prepared->optional)) {
            return SourceMutationResult::failure(ErrorCode::NOT_FOUND);
        }

        return SourceMutationResult::success($id);
    }

    /**
     * Trim and validate the shared create/edit input, producing either the
     * normalized name and optional-field map or the single failure code. Keeps
     * the create and edit paths behaviourally identical (10.2/10.5/10.7 wording
     * shared across both).
     *
     * @return object{isSuccess: bool, name: string, optional: array{birthDate: string|null, deathDate: string|null, description: string|null, linkUrl: string|null}, error: SourceMutationResult}
     */
    private function prepare(
        string $name,
        ?string $birthDate,
        ?string $deathDate,
        ?string $description,
        ?string $linkUrl,
    ): object {
        $trimmedName = trim($name);
        $fail = static fn (ErrorCode $code): object => (object) [
            'isSuccess' => false,
            'name' => '',
            'optional' => ['birthDate' => null, 'deathDate' => null, 'description' => null, 'linkUrl' => null],
            'error' => SourceMutationResult::failure($code),
        ];

        // Empty after trim: no name, nothing stored (10.2).
        if ($trimmedName === '') {
            return $fail(ErrorCode::MISSING_NAME);
        }

        // Name over its bound after trim: nothing stored (10.7).
        if (mb_strlen($trimmedName, 'UTF-8') > self::MAX_NAME_LENGTH) {
            return $fail(ErrorCode::INPUT_TOO_LONG);
        }

        // Optional free-text fields are trimmed; an empty value is stored as
        // null (unset), and each is bounded at 500 characters (10.3, 10.7).
        $normalizedBirth = $this->normalizeOptional($birthDate);
        $normalizedDeath = $this->normalizeOptional($deathDate);
        $normalizedDescription = $this->normalizeOptional($description);

        foreach ([$normalizedBirth, $normalizedDeath, $normalizedDescription] as $field) {
            if ($field !== null && mb_strlen($field, 'UTF-8') > self::MAX_OPTIONAL_FIELD_LENGTH) {
                return $fail(ErrorCode::INPUT_TOO_LONG);
            }
        }

        // The single optional link URL is trimmed; empty becomes null, is
        // bounded at 2000 characters and, when set, must carry an http(s)://
        // prefix (10.4, 10.7).
        $normalizedLink = $this->normalizeOptional($linkUrl);
        if ($normalizedLink !== null) {
            if (mb_strlen($normalizedLink, 'UTF-8') > self::MAX_LINK_URL_LENGTH) {
                return $fail(ErrorCode::INPUT_TOO_LONG);
            }

            if (!$this->hasHttpPrefix($normalizedLink)) {
                return $fail(ErrorCode::INVALID_LINK_URL);
            }
        }

        return (object) [
            'isSuccess' => true,
            'name' => $trimmedName,
            'optional' => [
                'birthDate' => $normalizedBirth,
                'deathDate' => $normalizedDeath,
                'description' => $normalizedDescription,
                'linkUrl' => $normalizedLink,
            ],
            'error' => SourceMutationResult::failure(ErrorCode::INTERNAL_ERROR),
        ];
    }

    /**
     * Whether the given (already trimmed) name is the reserved Unbekannt name
     * on its normal form (Requirement 13.2). The comparison is case- and
     * diacritics-insensitive via {@see TextNormalizer::normalize()}, measured
     * against the actual Unbekannt-Quelle's name so the reservation tracks that
     * row rather than a hard-coded literal.
     */
    private function isReservedName(string $trimmedName): bool
    {
        $reserved = TextNormalizer::normalize($this->sources->findUnknown()['name']);

        return TextNormalizer::normalize($trimmedName) === $reserved;
    }

    /**
     * Whether the given source id is the Unbekannt-Quelle. Lets an edit of the
     * Unbekannt-Quelle keep its reserved name while blocking any other source
     * from taking it (Requirement 13.2).
     */
    private function isUnknownSource(int $id): bool
    {
        return $id === $this->sources->findUnknown()['id'];
    }

    /**
     * Trim an optional field; an absent or whitespace-only value is treated as
     * unset (null), so optional fields are stored as null when empty
     * (Requirements 5.2, 10.3).
     */
    private function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Whether a set link URL carries the required `http://` or `https://`
     * prefix, compared case-insensitively on the scheme (10.4).
     */
    private function hasHttpPrefix(string $url): bool
    {
        $lower = strtolower($url);

        return str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://');
    }
}
