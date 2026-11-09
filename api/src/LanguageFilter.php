<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure, side-effect-free language filtering for the search logic
 * (Requirements 1.10, 1.11, 1.12; design "Such-API" and Properties 7/8).
 *
 * A search may carry an optional language filter: a set of language IDs the
 * caller wants to restrict the results to. This class decides, per quote,
 * whether the quote's assigned language passes that filter, and distinguishes
 * the two "empty filter" situations that the requirements treat differently:
 *
 *   - 1.11 — a MISSING or genuinely EMPTY filter is language-neutral: every
 *     quote passes regardless of its assigned language. The result set equals
 *     the one obtained with a filter spanning all existing languages
 *     (Property 8).
 *   - 1.10 — a NON-EMPTY filter restricts the result to quotes whose assigned
 *     language is one of the selected languages (Property 7).
 *   - 1.12 — a non-empty filter that references ONLY non-existing languages
 *     yields the empty result set: nothing passes.
 *
 * The distinction between 1.11 and 1.12 is why the raw filter alone is not
 * enough: both can end up "effectively empty" after intersecting with the
 * existing languages, but a neutral filter must pass everything while a filter
 * of only unknown languages must pass nothing. {@see self::isNeutral()} and
 * {@see self::includes()} keep those cases apart by taking the set of existing
 * language IDs into account.
 *
 * A quote's assigned language is referenced by its integer language ID, matched
 * against the integer IDs in the filter and in the existing-languages set.
 *
 * Determinism: the output depends only on the arguments. No I/O, no globals.
 */
final class LanguageFilter
{
    /**
     * Whether the requested filter is language-neutral, i.e. it must not
     * restrict the result at all (1.11, Property 8).
     *
     * A filter is neutral exactly when it is missing/empty. A non-empty filter
     * is never neutral — not even when none of its IDs refer to an existing
     * language; that case is a real restriction that yields no results (1.12),
     * handled by {@see self::includes()}.
     *
     * @param list<int> $filterLanguageIds The requested language IDs; an empty
     *                                     list means "no filter" (1.11).
     */
    public static function isNeutral(array $filterLanguageIds): bool
    {
        return $filterLanguageIds === [];
    }

    /**
     * Decide whether a single quote passes the language filter.
     *
     * Semantics (per quote):
     *   - Neutral filter (missing/empty) → always true (1.11): the quote is
     *     included regardless of its assigned language.
     *   - Non-empty filter → true only when the quote's assigned language is
     *     one of the filtered languages that actually exists (1.10). A filter
     *     ID that refers to no existing language can never match any quote's
     *     language, so a filter made up solely of such IDs excludes every
     *     quote and produces the empty result set (1.12).
     *
     * Intersecting the requested filter with the existing languages is what
     * makes 1.12 fall out naturally: a non-existing language ID is dropped from
     * the set of acceptable languages, so no quote's (necessarily existing)
     * language can equal it.
     *
     * @param int       $quoteLanguageId    The ID of the quote's assigned
     *                                      language (always an existing language).
     * @param list<int> $filterLanguageIds  The requested language IDs; empty
     *                                      means neutral (1.11).
     * @param list<int> $existingLanguageIds The IDs of all currently existing
     *                                      languages, used to drop filter IDs
     *                                      that reference no existing language (1.12).
     */
    public static function includes(
        int $quoteLanguageId,
        array $filterLanguageIds,
        array $existingLanguageIds
    ): bool {
        // 1.11: a neutral (missing/empty) filter never restricts anything.
        if (self::isNeutral($filterLanguageIds)) {
            return true;
        }

        // 1.10 / 1.12: restrict to the requested languages that actually
        // exist. Filter IDs referencing no existing language are discarded,
        // so a filter of only unknown languages accepts no quote at all.
        $acceptable = array_intersect($filterLanguageIds, $existingLanguageIds);

        return in_array($quoteLanguageId, $acceptable, true);
    }
}
