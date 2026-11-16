<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\RandomNumberGenerator;
use Citations\RandomQuoteSelector;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\InMemoryUiLanguageMappingRepository;
use Citations\UiLanguage;
use Eris\Generator;

/**
 * Feature: random-quote-homepage, Property 3: NO_QUOTES genau bei leerem Bestand
 *
 * For every language mapping and every uiLanguage value (valid or not, also
 * null), RandomQuoteSelector::pick returns null exactly when the whole stock
 * is empty; for a non-empty stock it always returns an existing quote.
 *
 * Validates: Requirements 6.10
 */
final class RandomQuoteEmptyStorePropertyTest extends PropertyTestCase
{
    public function testNullExactlyWhenStockIsEmpty(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // Language index (0..2) per stored quote; an empty list = empty stock.
            Generator\seq(Generator\choose(0, 2)),
            Generator\elements(null, 0, 1, 2),
            Generator\elements(null, 0, 1, 2),
            Generator\oneOf(
                Generator\elements(null, 'de', 'en', ' de ', 'DE', 'fr', ''),
                Generator\string()
            ),
            Generator\choose(0, 999_999)
        )->then(function (array $quoteLanguages, ?int $deMap, ?int $enMap, ?string $uiRaw, int $draw): void {
            $store = new InMemoryStore();
            $languages = new InMemoryLanguageRepository($store);
            $sources = new InMemorySourceRepository($store);
            $quotes = new InMemoryQuoteRepository($store);
            $mappings = new InMemoryUiLanguageMappingRepository($store);

            $languageIds = [
                $languages->insert('Deutsch', 'deutsch'),
                $languages->insert('English', 'english'),
                $languages->insert('Latein', 'latein'),
            ];
            $sourceId = $sources->insert('Quelle', ['linkUrl' => null]);

            $existingIds = [];
            foreach ($quoteLanguages as $i => $languageIndex) {
                $existingIds[] = $quotes->insert('Zitat ' . $i, $sourceId, $languageIds[$languageIndex]);
            }

            if ($deMap !== null) {
                $mappings->assign('de', $languageIds[$deMap]);
            }
            if ($enMap !== null) {
                $mappings->assign('en', $languageIds[$enMap]);
            }

            $rng = new class ($draw / 1_000_000) implements RandomNumberGenerator {
                public function __construct(private readonly float $value)
                {
                }

                public function next(): float
                {
                    return $this->value;
                }
            };

            $selector = new RandomQuoteSelector($quotes, $rng, $mappings);
            $result = $selector->pick(UiLanguage::tryFromInput($uiRaw));

            if ($existingIds === []) {
                self::assertNull($result, 'Empty stock must yield no quote (NO_QUOTES).');
            } else {
                self::assertNotNull($result, 'Non-empty stock must always yield a quote.');
                self::assertContains($result['id'], $existingIds, 'Returned quote must exist in the stock.');
            }
        });
    }
}
