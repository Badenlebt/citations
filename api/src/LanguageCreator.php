<?php

declare(strict_types=1);

namespace Citations;

use Citations\Repository\LanguageRepositoryInterface;

/**
 * Create-language mutation of the Sprach-API against the repository boundary
 * (Requirements 12.3, 12.4, 12.5, 12.6; design "`POST /api/languages`").
 *
 * This is the create path: it trims the submitted name, enforces the 1–100
 * character bound and the case-insensitive uniqueness of the stock, then
 * persists exactly one language and returns its generated id — afterwards the
 * language is part of the available set returned by
 * {@see LanguageRepositoryInterface::findAll()} (12.3). The stock is dynamically
 * extendable and not fixed to DE/EN (12.2).
 *
 * Uniqueness is case- and diacritics-insensitive: the trimmed name is reduced
 * to its normal form with {@see TextNormalizer::normalize()} (the same form the
 * repository persists as `name_normalized` and matches via
 * {@see LanguageRepositoryInterface::existsByNormalizedName()}), so e.g.
 * "Deutsch" and "deutsch" are the same language (12.5).
 *
 * Outcomes are returned as a {@see LanguageMutationResult} rather than thrown,
 * so the endpoint maps a reason {@see ErrorCode} to its HTTP status uniformly.
 * The character count is measured in Unicode characters (mb_strlen), matching
 * the "nach Entfernen umschließender Leerzeichen" wording and the length
 * handling used elsewhere (e.g. {@see QuoteCreator}), so multibyte text is not
 * penalized by its byte size.
 */
final class LanguageCreator
{
    /** Maximum language name length in Unicode characters after trimming (12.3, 12.6). */
    public const MAX_NAME_LENGTH = 100;

    public function __construct(
        private readonly LanguageRepositoryInterface $languages,
    ) {
    }

    /**
     * Create a language and return its id, or a reason code on rejection.
     *
     * Semantics (in order):
     *   1. The name is trimmed of surrounding whitespace. A name that is empty
     *      after trimming (missing, empty or whitespace-only) is rejected with
     *      MISSING_LANGUAGE_NAME, and no language is created (12.4).
     *   2. A trimmed name longer than {@see self::MAX_NAME_LENGTH} characters is
     *      rejected with INPUT_TOO_LONG, and no language is created (12.6).
     *   3. A name whose normal form already exists in the stock is rejected
     *      (case-insensitively) with LANGUAGE_EXISTS, and no language is created
     *      (12.5).
     *   4. On success exactly one language is stored with the trimmed display
     *      name and its normal form, and its generated id is returned; the
     *      language is afterwards part of the available set (12.3).
     *
     * @param string $name Raw, un-trimmed language name as submitted.
     */
    public function create(string $name): LanguageMutationResult
    {
        $trimmed = trim($name);

        // (12.4) Empty after trim: missing name, nothing stored.
        if ($trimmed === '') {
            return LanguageMutationResult::failure(ErrorCode::MISSING_LANGUAGE_NAME);
        }

        // (12.6) Over the length bound after trim: nothing stored.
        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_NAME_LENGTH) {
            return LanguageMutationResult::failure(ErrorCode::INPUT_TOO_LONG);
        }

        // (12.5) Case-insensitive duplicate: the normal form is already present.
        $normalized = TextNormalizer::normalize($trimmed);
        if ($this->languages->existsByNormalizedName($normalized)) {
            return LanguageMutationResult::failure(ErrorCode::LANGUAGE_EXISTS);
        }

        // (12.3) Persist exactly one language and return its generated id; it is
        // afterwards part of the available set (findAll()).
        $id = $this->languages->insert($trimmed, $normalized);

        return LanguageMutationResult::success($id);
    }
}
