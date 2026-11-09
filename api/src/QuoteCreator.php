<?php

declare(strict_types=1);

namespace Citations;

use Citations\Repository\LanguageRepositoryInterface;
use Citations\Repository\QuoteRepositoryInterface;
use Citations\Repository\SourceRepositoryInterface;

/**
 * Create-, edit- and delete-quote mutations of the Zitat-API against the
 * repository boundary (Requirements 8.1-8.6, 9.1-9.6, 13.3; design
 * "`POST /api/quotes`", "`PUT /api/quotes/{id}`" and "`DELETE /api/quotes/{id}`").
 *
 * Both paths trim the submitted text, enforce the 1–5000 character bound,
 * resolve the source (falling back to the always-present Unbekannt-Quelle when
 * none is chosen, 13.3) and verify the language exists; the shared validation
 * lives in {@see self::validate()} so create and edit reject identical invalid
 * input with the same codes and, on any failure, change no state. On success
 * create persists exactly one quote and returns its id (8.1); edit overwrites
 * an existing quote and returns its id (9.1), or NOT_FOUND when the id is
 * unknown (9.5). Neither path ever creates a new source — only an existing
 * source (or the Unbekannt fallback) may be referenced (8.5). Delete removes
 * exactly the quote with the given id (9.4), or returns NOT_FOUND when the id
 * is unknown, changing nothing (9.5).
 *
 * Outcomes are returned as a {@see QuoteMutationResult} rather than thrown, so
 * the endpoint maps a reason {@see ErrorCode} to its HTTP status uniformly.
 * The character count is measured in Unicode characters (mb_strlen), matching
 * the "nach Entfernen umschließender Leerzeichen" wording and the length
 * handling used elsewhere (e.g. {@see SearchQueryValidator}), so multibyte
 * text is not penalized by its byte size.
 */
final class QuoteCreator
{
    /** Maximum quote text length in Unicode characters after trimming (8.1, 8.6). */
    public const MAX_TEXT_LENGTH = 5000;

    public function __construct(
        private readonly QuoteRepositoryInterface $quotes,
        private readonly SourceRepositoryInterface $sources,
        private readonly LanguageRepositoryInterface $languages,
    ) {
    }

    /**
     * Create a quote and return its id, or a reason code on rejection.
     *
     * Semantics (in order):
     *   1. The text is trimmed of surrounding whitespace. An empty trimmed text
     *      is rejected with MISSING_TEXT, and no quote is created (8.2).
     *   2. A trimmed text longer than {@see self::MAX_TEXT_LENGTH} characters is
     *      rejected with INPUT_TOO_LONG, and no quote is created (8.6).
     *   3. The source is resolved: a null `$sourceId` falls back to the
     *      Unbekannt-Quelle (13.3); a non-null id that references no existing
     *      source is rejected with INVALID_SOURCE (8.3). No new source is ever
     *      created (8.5).
     *   4. The language must reference an existing language; otherwise the
     *      create is rejected with INVALID_LANGUAGE (8.4).
     *   5. On success exactly one quote is stored with the trimmed text, the
     *      resolved source and the language, and its generated id is returned
     *      (8.1).
     *
     * @param string   $text     Raw, un-trimmed quote text as submitted.
     * @param int|null $sourceId Chosen source id, or null to use the
     *                           Unbekannt-Quelle (13.3).
     * @param int      $languageId Chosen language id (must exist, 8.4).
     */
    public function create(string $text, ?int $sourceId, int $languageId): QuoteMutationResult
    {
        $prepared = $this->validate($text, $sourceId, $languageId);
        if ($prepared->error !== null) {
            return $prepared->error;
        }

        // (8.1) Persist exactly one quote and return its generated id.
        $id = $this->quotes->insert($prepared->text, $prepared->sourceId, $languageId);

        return QuoteMutationResult::success($id);
    }

    /**
     * Overwrite an existing quote with validated values (Requirement 9.1), or
     * return a reason code on rejection.
     *
     * The validation is byte-for-byte identical to {@see self::create()}: a
     * trim-empty text is MISSING_TEXT (9.2), a trimmed text longer than
     * {@see self::MAX_TEXT_LENGTH} is INPUT_TOO_LONG (9.6), a non-existent
     * chosen source is INVALID_SOURCE and a non-existent language is
     * INVALID_LANGUAGE (9.3); a null source falls back to the Unbekannt-Quelle,
     * exactly as on create (13.3). On ANY invalid input nothing is changed.
     *
     * When the id references no existing quote the edit is rejected with
     * NOT_FOUND and nothing is changed (9.5). The existence check runs only
     * after the input itself is valid, so an invalid edit of a missing quote
     * still surfaces the input error and still changes nothing either way.
     *
     * @param int      $id         Id of the quote to overwrite (9.1).
     * @param string   $text       Raw, un-trimmed quote text as submitted.
     * @param int|null $sourceId   Chosen source id, or null for the Unbekannt-Quelle.
     * @param int      $languageId Chosen language id (must exist, 9.3).
     */
    public function edit(int $id, string $text, ?int $sourceId, int $languageId): QuoteMutationResult
    {
        $prepared = $this->validate($text, $sourceId, $languageId);
        if ($prepared->error !== null) {
            return $prepared->error;
        }

        // (9.5) A missing quote is not found; nothing is changed.
        if (!$this->quotes->update($id, $prepared->text, $prepared->sourceId, $languageId)) {
            return QuoteMutationResult::failure(ErrorCode::NOT_FOUND);
        }

        return QuoteMutationResult::success($id);
    }

    /**
     * Remove exactly the quote with the given id (Requirement 9.4), or return a
     * reason code when it does not exist.
     *
     * Löschen entfernt genau das eine Zitat mit dieser Kennung und lässt alle
     * übrigen Zitate unberührt (9.4). Verweist die Kennung auf kein vorhandenes
     * Zitat, wird mit NOT_FOUND abgewiesen und kein Datenstand verändert (9.5);
     * da das Entfernen die einzige Wirkung ist, bleibt bei NOT_FOUND der
     * gesamte Bestand exakt erhalten.
     *
     * Es gibt hier keine Eingabevalidierung — ein Löschvorgang trägt keine
     * Nutzlast, über die {@see self::validate()} entscheiden könnte; die
     * Existenzprüfung ist die einzige Bedingung. Das Ergebnis spiegelt bei
     * Erfolg die Kennung des entfernten Zitats wider, analog zu
     * {@see SourceDeleter::delete()}.
     *
     * @param int $id Id of the quote to remove (9.4).
     */
    public function delete(int $id): QuoteMutationResult
    {
        // (9.4 / 9.5) Remove exactly this quote; a missing id is NOT_FOUND and
        // leaves the whole store unchanged.
        if (!$this->quotes->delete($id)) {
            return QuoteMutationResult::failure(ErrorCode::NOT_FOUND);
        }

        return QuoteMutationResult::success($id);
    }

    /**
     * Trim and validate the shared create/edit input (text, source, language),
     * producing either the trimmed text and resolved source id, or the single
     * failure result. Keeps the create and edit paths behaviourally identical
     * (8.2/8.3/8.4/8.6 == 9.2/9.3/9.6) and performs no persistence itself, so a
     * failure here guarantees no state change.
     *
     * Order: text emptiness (MISSING_TEXT) -> text length (INPUT_TOO_LONG) ->
     * source resolution (null => Unbekannt-Quelle, else must exist =>
     * INVALID_SOURCE) -> language existence (INVALID_LANGUAGE).
     *
     * @return object{text: string, sourceId: int, error: QuoteMutationResult|null}
     */
    private function validate(string $text, ?int $sourceId, int $languageId): object
    {
        $fail = static fn (ErrorCode $code): object => (object) [
            'text' => '',
            'sourceId' => 0,
            'error' => QuoteMutationResult::failure($code),
        ];

        $trimmed = trim($text);

        // (8.2 / 9.2) Empty after trim: no text, nothing stored.
        if ($trimmed === '') {
            return $fail(ErrorCode::MISSING_TEXT);
        }

        // (8.6 / 9.6) Over the length bound after trim: nothing stored.
        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_TEXT_LENGTH) {
            return $fail(ErrorCode::INPUT_TOO_LONG);
        }

        // (13.3 / 8.3 / 9.3 / 8.5) Resolve the source. No chosen source falls
        // back to the always-present Unbekannt-Quelle; a chosen id must already
        // exist — we never create a new source here.
        if ($sourceId === null) {
            $resolvedSourceId = $this->sources->findUnknown()['id'];
        } elseif ($this->sources->findById($sourceId) !== null) {
            $resolvedSourceId = $sourceId;
        } else {
            return $fail(ErrorCode::INVALID_SOURCE);
        }

        // (8.4 / 9.3) The language must reference an existing language.
        if (!$this->languages->exists($languageId)) {
            return $fail(ErrorCode::INVALID_LANGUAGE);
        }

        return (object) [
            'text' => $trimmed,
            'sourceId' => $resolvedSourceId,
            'error' => null,
        ];
    }
}
