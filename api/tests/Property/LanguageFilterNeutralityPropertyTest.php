<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\LanguageFilter;
use Eris\Generator;

/**
 * Feature: citations-management, Property 8: Fehlender oder leerer Sprachfilter
 * ist sprachneutral.
 *
 * A missing/empty language filter must not restrict the result at all
 * (Requirement 1.11): every quote passes regardless of its assigned language.
 * Concretely, the empty filter is language-neutral iff the inclusion decision
 * for every quote equals the decision made with a filter that spans ALL
 * existing languages. We verify this equivalence over arbitrary quote language
 * IDs and arbitrary existing-language sets.
 *
 * Validates: Requirements 1.11
 */
final class LanguageFilterNeutralityPropertyTest extends PropertyTestCase
{
    /**
     * Build a filter that spans every existing language, deduplicated and
     * reindexed to a list<int> as LanguageFilter expects.
     *
     * @param list<int> $existingLanguageIds
     *
     * @return list<int>
     */
    private function filterSpanningAllExisting(array $existingLanguageIds): array
    {
        return array_values(array_unique($existingLanguageIds));
    }

    /**
     * The empty filter includes a quote iff a filter spanning all existing
     * languages includes it. Since the quote's assigned language is always an
     * existing language, both must accept every quote, establishing neutrality.
     */
    public function testEmptyFilterMatchesAllExistingLanguagesFilter(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // One guaranteed existing language ID, so the set is never empty.
            Generator\choose(1, 50),
            // Further arbitrary existing language IDs (may be empty / duplicate).
            Generator\seq(Generator\choose(1, 50)),
            // Index used to pick which existing language the quote carries.
            Generator\choose(0, 1000)
        )->then(function (int $baseLanguageId, array $extraLanguageIds, int $pick): void {
            $existing = array_values(array_unique(array_merge([$baseLanguageId], $extraLanguageIds)));
            // The quote's assigned language is always an existing language.
            $quoteLanguageId = $existing[$pick % count($existing)];

            $spanningFilter = $this->filterSpanningAllExisting($existing);

            $neutralDecision = LanguageFilter::includes($quoteLanguageId, [], $existing);
            $spanningDecision = LanguageFilter::includes($quoteLanguageId, $spanningFilter, $existing);

            // 1.11: empty filter is neutral -> always includes the quote.
            self::assertTrue(
                $neutralDecision,
                sprintf('Empty filter must include quote language %d.', $quoteLanguageId)
            );

            // Property 8: empty filter result equals all-languages filter result.
            self::assertSame(
                $spanningDecision,
                $neutralDecision,
                sprintf(
                    'Empty filter and all-existing-languages filter disagree for quote language %d (existing: %s).',
                    $quoteLanguageId,
                    implode(',', $existing)
                )
            );
        });
    }

    /**
     * Neutrality is independent of the quote's language: even when the quote
     * carries a language ID that does NOT exist in the stock, the empty filter
     * still includes it (missing/empty filter never restricts, 1.11).
     */
    public function testEmptyFilterIncludesRegardlessOfQuoteLanguage(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(1, 10_000),
            Generator\seq(Generator\choose(1, 50))
        )->then(function (int $quoteLanguageId, array $existingLanguageIds): void {
            $existing = array_values(array_unique($existingLanguageIds));

            self::assertTrue(
                LanguageFilter::isNeutral([]),
                'Empty filter must be reported as neutral.'
            );
            self::assertTrue(
                LanguageFilter::includes($quoteLanguageId, [], $existing),
                sprintf(
                    'Empty filter must include quote language %d regardless of existing set %s.',
                    $quoteLanguageId,
                    implode(',', $existing)
                )
            );
        });
    }
}
