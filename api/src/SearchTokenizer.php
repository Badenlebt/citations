<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure, side-effect-free tokenization of a search input into search terms
 * (Requirement 1.1/1.2 wording "Suchbegriff", design "Abgeleitete /
 * normalisierte Werte").
 *
 * Contract:
 *   - Splits the input on runs of Unicode whitespace (\s with the /u modifier,
 *     which also covers non-breaking space, tabs, newlines, and other Unicode
 *     spacing). Consecutive separators never produce empty tokens.
 *   - Drops empty tokens. A string that is empty or consists solely of
 *     whitespace yields an empty array.
 *   - Returns the tokens RAW (not normalized). Tokenization stays a pure split;
 *     callers compose it with {@see TextNormalizer::normalize()} by applying
 *     normalize() to each returned term. Keeping the two concerns separate lets
 *     the same normalize() run over stored values and over each search term,
 *     so the comparison stays symmetric.
 *
 * Determinism: the output depends only on the input string. No I/O, no globals.
 */
final class SearchTokenizer
{
    /**
     * Split a search input into non-empty, whitespace-trimmed search terms.
     *
     * @return list<string> Raw (un-normalized) terms in input order; empty for
     *                      empty or whitespace-only input.
     */
    public static function tokenize(string $input): array
    {
        $parts = preg_split('/\s+/u', $input, -1, PREG_SPLIT_NO_EMPTY);

        // preg_split returns false only on an internal PCRE error; treat that
        // as "no usable terms" rather than propagating a non-array.
        if ($parts === false) {
            return [];
        }

        return array_values($parts);
    }
}
