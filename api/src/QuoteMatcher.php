<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure, side-effect-free matching of search terms against a quote
 * (Requirements 1.1, 1.2, 1.3, 1.4; design "Such-API" and Property 1/2).
 *
 * A quote is a match when its NORMALIZED quote text OR its NORMALIZED source
 * name contains at least one normalized search term as a substring. Matching
 * considers ONLY those two fields: the source's birth date, death date and
 * short description are structurally excluded because they are never passed in
 * (1.3, 1.4). Comparison is case- and diacritics-insensitive because both the
 * stored values and the search terms are run through the SAME
 * {@see TextNormalizer::normalize()} before the substring check (2.6).
 *
 * The term list is OR-combined: a single matching term is enough (1.1, 1.2).
 * An empty term list (e.g. whitespace-only input) never matches; term-level
 * validation of the raw input (MISSING_QUERY / QUERY_TOO_LONG) is the caller's
 * concern and lives in the search endpoint, not here.
 *
 * Determinism: the output depends only on the arguments. No I/O, no globals.
 */
final class QuoteMatcher
{
    /**
     * Decide whether a quote matches the given search terms.
     *
     * Both the quote text and the source name are normalized here so the
     * contract holds for raw stored values; passing pre-normalized values is
     * also safe because {@see TextNormalizer::normalize()} is idempotent. Each
     * search term is normalized the same way, keeping the comparison symmetric.
     *
     * @param string       $quoteText  The raw quote text (searchable field, 1.1).
     * @param string       $sourceName The raw name of the assigned source
     *                                  (searchable field, 1.2). Dates and the
     *                                  short description are intentionally not
     *                                  parameters (1.3, 1.4).
     * @param list<string> $searchTerms The search terms (raw tokens from
     *                                  {@see SearchTokenizer::tokenize()});
     *                                  OR-combined, empty list never matches.
     */
    public static function matches(string $quoteText, string $sourceName, array $searchTerms): bool
    {
        $haystackText = TextNormalizer::normalize($quoteText);
        $haystackName = TextNormalizer::normalize($sourceName);

        foreach ($searchTerms as $term) {
            $needle = TextNormalizer::normalize($term);

            // A term normalizing to the empty string (pure whitespace / only
            // combining marks) is skipped rather than matching everything.
            if ($needle === '') {
                continue;
            }

            if (str_contains($haystackText, $needle) || str_contains($haystackName, $needle)) {
                return true;
            }
        }

        return false;
    }
}
