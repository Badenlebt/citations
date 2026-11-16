<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\Http\Request;
use Citations\Http\SearchHandler;
use Citations\RandomNumberGenerator;
use Citations\RandomQuoteSelector;
use Citations\RandomSelector;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\InMemoryUiLanguageMappingRepository;
use Citations\UiLanguage;
use Eris\Generator;

/**
 * Feature: random-quote-homepage, Property 1: Ungültiger uiLanguage verändert die Auswahl nicht
 *
 * For every stock of quotes, every language mapping, every injected draw r in
 * [0, 1) and every `uiLanguage` value that is neither "de" nor "en" (missing,
 * empty, arbitrary string or non-string), the random selection returns the same
 * quote as `RandomSelector::select(count, r)` over the whole stock - both via
 * `UiLanguage::tryFromInput()` + `RandomQuoteSelector::pick()` and through the
 * `SearchHandler` random endpoint.
 *
 * Validates: Requirements 6.7, 8.2
 */
final class RandomQuoteInvalidUiLanguagePropertyTest extends PropertyTestCase
{
    public function testInvalidUiLanguageDoesNotChangeSelection(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\vector(3, Generator\choose(0, 3)),       // language of each potential quote slot (0 = none)
            Generator\choose(0, 12),                            // number of quotes
            Generator\choose(0, 3),                             // mapping for "de" (0 = unmapped)
            Generator\choose(0, 3),                             // mapping for "en" (0 = unmapped)
            Generator\choose(0, 999_999),                       // r numerator
            Generator\choose(0, 5),                             // kind of invalid value
            Generator\string()
        )->then(function (
            array $langSeed,
            int $quoteCount,
            int $deMap,
            int $enMap,
            int $rNum,
            int $kind,
            string $randomString
        ): void {
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
            $sourceId = $sources->insert('Seneca', []);

            for ($i = 0; $i < $quoteCount; $i++) {
                $slot = $languageIds[($langSeed[$i % 3] + $i) % 3];
                $quotes->insert('Zitat ' . $i, $sourceId, $slot);
            }

            // Arbitrary (valid, 1:1) mapping; assign() may refuse a duplicate target.
            if ($deMap > 0) {
                $mappings->assign('de', $languageIds[$deMap - 1]);
            }
            if ($enMap > 0) {
                $mappings->assign('en', $languageIds[$enMap - 1]);
            }

            $r = $rNum / 1_000_000;

            // Invalid uiLanguage value: neither "de" nor "en" after trimming.
            $present = true;
            $value = null;
            switch ($kind) {
                case 0:
                    $present = false; // missing
                    break;
                case 1:
                    $value = '';
                    break;
                case 2:
                    $value = $randomString;
                    if (UiLanguage::tryFromInput($value) !== null) {
                        $value .= 'x';
                    }
                    break;
                case 3:
                    $value = 'DE';
                    break;
                case 4:
                    $value = 'fr';
                    break;
                default:
                    $value = 123; // non-string
                    break;
            }
            $query = $present ? ['uiLanguage' => $value] : [];

            $ui = UiLanguage::tryFromInput($query['uiLanguage'] ?? null);
            self::assertNull($ui, 'Generated uiLanguage must be invalid.');

            // Expected: plain selection over the whole stock.
            $index = RandomSelector::select($quotes->count(), $r);
            $expected = $index === null ? null : $quotes->findByIndex($index);

            $rng = new class ($r) implements RandomNumberGenerator {
                public function __construct(private readonly float $value)
                {
                }

                public function next(): float
                {
                    return $this->value;
                }
            };
            $selector = new RandomQuoteSelector($quotes, $rng, $mappings);

            self::assertSame($expected, $selector->pick($ui));

            $handler = new SearchHandler($quotes, $languages, $selector);
            $response = $handler->handle(new Request('GET', 'random', $query));

            if ($expected === null) {
                self::assertSame(ErrorCode::NO_QUOTES->httpStatus(), $response->status);
                self::assertSame('NO_QUOTES', $response->body['error']);
            } else {
                self::assertSame(200, $response->status);
                self::assertSame($expected['id'], $response->body['id']);
                self::assertSame($expected['text'], $response->body['text']);
            }
        });
    }
}
