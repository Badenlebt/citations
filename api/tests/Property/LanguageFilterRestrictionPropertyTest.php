<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\LanguageFilter;
use Eris\Generator;

/**
 * Feature: citations-management, Property 7: Sprachfilter schränkt Ergebnisse
 * auf gewählte Sprachen ein.
 *
 * For any quote store, any valid search input and any NON-EMPTY language filter
 * the following holds: a quote passes the filter exactly when its assigned
 * language is one of the selected languages that actually exists. Because a
 * quote's assigned language is always an existing language and filter IDs that
 * reference no existing language can never match (1.12), the acceptable set is
 * the intersection of the requested filter with the existing languages.
 *
 * The test drives {@see LanguageFilter::includes()} with an arbitrary non-empty
 * filter, an arbitrary set of existing languages and an arbitrary quote
 * language, and asserts the equivalence in both directions:
 *   - every quote that passes (includes() === true) has its language in the
 *     filtered-and-existing set, and
 *   - every quote whose language is in that set passes.
 *
 * Validates: Requirements 1.10
 */
final class LanguageFilterRestrictionPropertyTest extends PropertyTestCase
{
    /**
     * Property 7: for a non-empty filter, includes() === true holds for a
     * quote iff the quote's assigned language is in the intersection of the
     * filter and the existing languages.
     */
    public function testNonEmptyFilterRestrictsToSelectedExistingLanguages(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // The quote's assigned language is always an existing language, so
            // pick it from the same id space the existing-languages set uses.
            Generator\choose(1, 20),
            // Set of existing language IDs (may repeat; dedup below).
            Generator\seq(Generator\choose(1, 20)),
            // Requested filter: one mandatory id plus an optional tail, so it is
            // always NON-EMPTY as Property 7 requires.
            Generator\choose(1, 20),
            Generator\seq(Generator\choose(1, 20))
        )->then(function (
            int $quoteLanguageId,
            array $existingSeq,
            int $filterHead,
            array $filterTail
        ): void {
            $existingLanguageIds = array_values(array_unique($existingSeq));
            $filterLanguageIds = array_values(
                array_unique(array_merge([$filterHead], $filterTail))
            );

            // Precondition of Property 7: the filter is non-empty.
            self::assertNotEmpty($filterLanguageIds);

            // The acceptable set the requirement describes: selected languages
            // that actually exist.
            $acceptable = array_values(
                array_intersect($filterLanguageIds, $existingLanguageIds)
            );
            $inAcceptable = in_array($quoteLanguageId, $acceptable, true);

            $passes = LanguageFilter::includes(
                $quoteLanguageId,
                $filterLanguageIds,
                $existingLanguageIds
            );

            // Equivalence: passing the non-empty filter <=> language is in the
            // filtered-and-existing set (both directions of Property 7).
            self::assertSame(
                $inAcceptable,
                $passes,
                sprintf(
                    'quote-lang=%d filter=[%s] existing=[%s]: expected pass=%s but got %s',
                    $quoteLanguageId,
                    implode(',', $filterLanguageIds),
                    implode(',', $existingLanguageIds),
                    $inAcceptable ? 'true' : 'false',
                    $passes ? 'true' : 'false'
                )
            );

            // Direction 1 (restriction): every passing quote's language is
            // among the selected languages.
            if ($passes) {
                self::assertContains(
                    $quoteLanguageId,
                    $filterLanguageIds,
                    'A passing quote must have its language in the non-empty filter.'
                );
            }
        });
    }
}
