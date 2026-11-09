<?php

declare(strict_types=1);

namespace Citations;

use Normalizer;

/**
 * Pure, side-effect-free Unicode normalization used for diacritics- and
 * case-insensitive comparison (Requirement 2.6, design "Abgeleitete /
 * normalisierte Werte").
 *
 * The SAME {@see self::normalize()} is applied identically to stored values
 * (quote text, source name, language name) AND to search input (and each of
 * its search terms), so comparison is symmetric: a stored value and a matching
 * search term share the same normal form.
 *
 * The normal form is produced by:
 *   1. Unicode canonical decomposition (NFD) via the intl extension, which
 *      splits precomposed characters such as "Ä" into a base letter plus a
 *      combining diacritical mark.
 *   2. Removing combining diacritical marks (Unicode category Mn, which covers
 *      the combining range U+0300–U+036F produced by step 1).
 *   3. Unicode-aware lowercasing via mb_strtolower.
 *
 * Determinism: the output depends only on the input string. No I/O, no globals.
 */
final class TextNormalizer
{
    /**
     * Produce the diacritics-free, lowercased normal form of a string.
     *
     * Examples:
     *   "Ä", "ä" and "a" + combining diaeresis all normalize to "a".
     *   "É" normalizes to "e".
     *
     * The function is idempotent: normalize(normalize($s)) === normalize($s).
     */
    public static function normalize(string $value): string
    {
        // Step 1: canonical decomposition (NFD). On the extremely unlikely
        // event the intl normalizer rejects the input, fall back to the raw
        // string so later steps still run deterministically.
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
        if ($decomposed === false) {
            $decomposed = $value;
        }

        // Step 2: strip combining marks (category Mn) left behind by step 1.
        $withoutMarks = preg_replace('/\p{Mn}+/u', '', $decomposed);
        if ($withoutMarks === null) {
            // preg_replace returns null only on an internal PCRE error; keep
            // the decomposed form rather than losing data.
            $withoutMarks = $decomposed;
        }

        // Step 3: Unicode-aware lowercasing.
        return mb_strtolower($withoutMarks, 'UTF-8');
    }
}
