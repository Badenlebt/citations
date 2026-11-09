<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure, side-effect-free validation of the raw search input `q` before any
 * search work runs (Requirements 1.9, 1.13; design "`GET /api/search`" and
 * "Validierungsreihenfolge").
 *
 * This gate sits in front of tokenization ({@see SearchTokenizer::tokenize()})
 * and the result computation. It guarantees the two rejection rules of the
 * search endpoint, in this exact order:
 *
 *   1. Length (1.13): if the input is longer than 200 characters it is rejected
 *      with QUERY_TOO_LONG *before* the input is split into search terms, so no
 *      tokenization and no result computation happens. The limit is measured in
 *      Unicode characters (mb_strlen), consistent with the design's "1–200
 *      Zeichen" wording, so multibyte input is not penalized by its byte size.
 *   2. Emptiness (1.9): otherwise, if the input is empty or consists solely of
 *      whitespace, it is rejected with MISSING_QUERY without running the search.
 *      Whitespace is detected the same way tokenization splits (any run of
 *      Unicode whitespace), so an input that would yield zero search terms is
 *      exactly the input rejected here.
 *
 * The length check deliberately precedes the emptiness check: an input that is
 * both over-long and whitespace-only is rejected as QUERY_TOO_LONG, matching
 * "vor der Zerlegung" in the design.
 *
 * Determinism: the outcome depends only on the input string. No I/O, no globals.
 */
final class SearchQueryValidator
{
    /**
     * Maximum allowed length of the search input in Unicode characters (1.13).
     * Inputs with 1..=200 characters pass the length check; 201+ are rejected.
     */
    public const MAX_LENGTH = 200;

    /**
     * Validate the raw search input.
     *
     * @param string $query The raw, un-normalized search input `q`.
     * @return ErrorCode|null QUERY_TOO_LONG when the input exceeds
     *         {@see self::MAX_LENGTH} characters (1.13); MISSING_QUERY when the
     *         input is empty or whitespace-only (1.9); null when the input is
     *         valid and the caller may proceed to tokenize and search.
     */
    public static function validate(string $query): ?ErrorCode
    {
        // Step 1 (1.13): length gate, measured in Unicode characters and applied
        // before tokenization. A whitespace-only but over-long input is rejected
        // here as QUERY_TOO_LONG, not MISSING_QUERY.
        if (mb_strlen($query, 'UTF-8') > self::MAX_LENGTH) {
            return ErrorCode::QUERY_TOO_LONG;
        }

        // Step 2 (1.9): emptiness gate. An input that is empty or consists
        // solely of (Unicode) whitespace produces no search terms, so the
        // request is rejected without executing a search.
        if (SearchTokenizer::tokenize($query) === []) {
            return ErrorCode::MISSING_QUERY;
        }

        return null;
    }
}
