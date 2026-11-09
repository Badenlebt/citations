<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure, side-effect-free relevance scoring for a single quote against a set of
 * search terms (Requirements 2.1–2.6, design Correctness Properties 9–12).
 *
 * The scorer produces EXACTLY ONE numeric relevance value per quote (2.1) by
 * summarising every match of that quote in the quote text AND the source name
 * jointly (2.5, Property 11). Comparison runs entirely on the Unicode normal
 * form via {@see TextNormalizer::normalize()}, so scoring is case- and
 * diacritics-invariant (2.6, Property 12). Search terms are the raw terms from
 * {@see SearchTokenizer::tokenize()}; the scorer normalizes each itself.
 *
 * Scoring model
 * -------------
 * For each distinct search term the scorer determines the BEST match type of
 * that term across the quote text and the source name combined:
 *
 *   standalone word      (STANDALONE = 3) — the term occurs as a whole word,
 *                         delimited by word boundaries on both sides
 *   word-prefix          (PREFIX     = 2) — the term occurs at the start of a
 *                         word (boundary before, more word chars after)
 *   infix substring      (INFIX      = 1) — the term occurs inside a word, not
 *                         at the word start
 *   no match             (            0)
 *
 * A "word character" is any Unicode letter or digit (\p{L}\p{N}); every other
 * character (including whitespace and punctuation) is a word boundary.
 *
 * The single relevance value is a lexicographic combination of two components
 * encoded into one number:
 *
 *   relevance = DISTINCT_WEIGHT * (number of distinct matched terms)
 *             + (sum over matched terms of that term's best match-type weight)
 *
 * with DISTINCT_WEIGHT chosen strictly larger than the largest possible
 * match-quality sum for the given query. This guarantees:
 *
 *   - Property 9 (hierarchy): for otherwise-alike quotes that match the SAME
 *     single term, the distinct-term count is equal, so the ordering is decided
 *     solely by the match-type weight — standalone > word-prefix > infix.
 *   - Property 10 (more matched words wins): a quote matching a strict superset
 *     of terms has a strictly higher distinct-term count; because DISTINCT_WEIGHT
 *     dominates the entire quality sum, the superset quote always outranks the
 *     subset quote regardless of individual match types.
 *   - Property 11 (text and source combined): because each term's contribution
 *     is the best type across text AND source, adding a source-name match can
 *     only raise (or keep) a term's best type or newly match a term, so the
 *     relevance never decreases.
 *   - Property 12 (invariance): all inputs pass through the normal form before
 *     any comparison, so case/diacritics variants yield identical scores.
 *
 * Determinism: the output depends only on the arguments. No I/O, no globals.
 */
final class RelevanceScorer
{
    /** Match-type weight: term occurs as a standalone whole word. */
    public const STANDALONE = 3;

    /** Match-type weight: term occurs at the start of a longer word. */
    public const PREFIX = 2;

    /** Match-type weight: term occurs inside a word, not at the word start. */
    public const INFIX = 1;

    /** Match-type weight: term does not occur at all. */
    private const NONE = 0;

    /**
     * Compute the single relevance value of one quote for the given raw search
     * terms. Higher means more relevant; 0 means the quote matches no term.
     *
     * @param string       $quoteText   Raw quote text (will be normalized).
     * @param string       $sourceName  Raw source name (will be normalized).
     * @param list<string> $searchTerms Raw search terms from the tokenizer.
     */
    public static function score(string $quoteText, string $sourceName, array $searchTerms): float
    {
        $normalizedText = TextNormalizer::normalize($quoteText);
        $normalizedSource = TextNormalizer::normalize($sourceName);

        // Collapse the terms to their distinct normal forms. Repeating a term
        // in the query must not inflate the score: the relevance reflects how
        // many distinct search words the quote hits (2.4) and how well.
        //
        // The normalized term is used BOTH as the dedup key and the stored
        // value: PHP coerces a numeric-string array key (e.g. "2024") to an
        // int, so relying on array_keys() here would hand matchType() an int
        // and break its string contract. Keeping the string as the value
        // preserves the type for a purely numeric search term.
        $distinctTerms = [];
        foreach ($searchTerms as $term) {
            $normalizedTerm = TextNormalizer::normalize($term);
            if ($normalizedTerm === '') {
                continue;
            }
            $distinctTerms[$normalizedTerm] = $normalizedTerm;
        }
        $terms = array_values($distinctTerms);

        if ($terms === []) {
            return 0.0;
        }

        $matchedCount = 0;
        $qualitySum = 0;
        foreach ($terms as $term) {
            // Best match type of this term across text AND source name (2.5).
            $best = max(
                self::matchType($normalizedText, $term),
                self::matchType($normalizedSource, $term)
            );
            if ($best > self::NONE) {
                $matchedCount++;
                $qualitySum += $best;
            }
        }

        if ($matchedCount === 0) {
            return 0.0;
        }

        // DISTINCT_WEIGHT must strictly exceed the largest possible quality sum
        // for this query so that one extra matched term always outweighs any
        // redistribution of match types (Property 10). The quality sum is at
        // most STANDALONE per distinct term, hence STANDALONE * |terms| + 1.
        $distinctWeight = self::STANDALONE * count($terms) + 1;

        return (float) ($distinctWeight * $matchedCount + $qualitySum);
    }

    /**
     * Determine the best match type of a single normalized term within a single
     * normalized haystack (quote text or source name).
     *
     * A term may occur several times; the strongest occurrence wins:
     * standalone word > word-prefix > infix. Returns {@see self::NONE} when the
     * term does not occur at all.
     */
    private static function matchType(string $haystack, string $term): int
    {
        if ($term === '' || $haystack === '') {
            return self::NONE;
        }

        $best = self::NONE;
        $offset = 0;

        while (($pos = mb_strpos($haystack, $term, $offset, 'UTF-8')) !== false) {
            $boundaryBefore = self::isBoundaryBefore($haystack, $pos);
            $boundaryAfter = self::isBoundaryAfter($haystack, $pos + mb_strlen($term, 'UTF-8'));

            if ($boundaryBefore && $boundaryAfter) {
                return self::STANDALONE; // strongest possible, stop early
            }
            if ($boundaryBefore) {
                $best = max($best, self::PREFIX);
            } else {
                $best = max($best, self::INFIX);
            }

            // Advance by one character to find further, possibly stronger,
            // occurrences (e.g. an infix hit followed by a standalone hit).
            $offset = $pos + 1;
        }

        return $best;
    }

    /**
     * True when the character immediately before $charPos is a word boundary
     * (i.e. not a Unicode letter or digit), or the match starts the haystack.
     */
    private static function isBoundaryBefore(string $haystack, int $charPos): bool
    {
        if ($charPos <= 0) {
            return true;
        }
        $prev = mb_substr($haystack, $charPos - 1, 1, 'UTF-8');

        return !self::isWordChar($prev);
    }

    /**
     * True when the character at $charPos is a word boundary (i.e. not a
     * Unicode letter or digit), or the match ends the haystack.
     */
    private static function isBoundaryAfter(string $haystack, int $charPos): bool
    {
        if ($charPos >= mb_strlen($haystack, 'UTF-8')) {
            return true;
        }
        $next = mb_substr($haystack, $charPos, 1, 'UTF-8');

        return !self::isWordChar($next);
    }

    /**
     * A word character is any single Unicode letter or digit. Everything else
     * (whitespace, punctuation, symbols) counts as a word boundary.
     */
    private static function isWordChar(string $char): bool
    {
        return preg_match('/^[\p{L}\p{N}]$/u', $char) === 1;
    }
}
