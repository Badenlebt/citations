<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\Http\Request;
use Citations\Http\SearchHandler;
use Citations\RandomNumberGenerator;
use Citations\RandomQuoteSelector;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\InMemoryUiLanguageMappingRepository;
use Eris\Generator;

/**
 * Feature: random-quote-homepage, Property 4: Antwortformat der Zufalls-API bleibt unverändert
 *
 * For every chosen quote and every `uiLanguage` value (absent, valid, invalid,
 * with or without a stored mapping) the response of `GET /random`
 * (SearchHandler over in-memory repositories and a fixed RNG) has exactly the
 * fields id, text, languageId, languageName and source{id,name} with the
 * unchanged types (int, string, int, string, {int, string}).
 *
 * Validates: Requirements 8.2
 */
final class RandomResponseShapePropertyTest extends PropertyTestCase
{
    private function fixedRng(float $value): RandomNumberGenerator
    {
        return new class ($value) implements RandomNumberGenerator {
            public function __construct(private readonly float $value)
            {
            }

            public function next(): float
            {
                return $this->value;
            }
        };
    }

    /** Query value for `uiLanguage`; null means the parameter is absent. */
    private function uiLanguageGenerator(): Generator
    {
        return Generator\oneOf(
            Generator\constant(null),
            Generator\elements(['de', 'en', ' de ', ' en', 'DE', 'En', '', '   ', 'fr', 'deutsch', '0']),
            Generator\string(),
        );
    }

    public function testRandomResponseShapeIsUnchanged(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // Quotes as [languageIndex, sourceIndex, text].
            Generator\seq(Generator\tuple(
                Generator\choose(0, 2),
                Generator\choose(0, 2),
                Generator\string(),
            )),
            // Mapping: language index (or -1 = none) for 'de' and 'en'.
            Generator\choose(-1, 2),
            Generator\choose(-1, 2),
            Generator\choose(0, 999),
            $this->uiLanguageGenerator()
        )->then(function (array $quoteSpecs, int $deIdx, int $enIdx, int $drawInt, ?string $ui): void {
            $store = new InMemoryStore();
            $languages = new InMemoryLanguageRepository($store);
            $sources = new InMemorySourceRepository($store);
            $quotes = new InMemoryQuoteRepository($store);
            $mappings = new InMemoryUiLanguageMappingRepository($store);

            $languageIds = [
                $languages->insert('Deutsch', 'deutsch'),
                $languages->insert('English', 'english'),
                $languages->insert('Latina', 'latina'),
            ];
            $sourceIds = [
                $sources->insert('Seneca', []),
                $sources->insert('Cicero', []),
                $sources->insert('Goethe', []),
            ];

            // Always at least one quote so the response is a 200 quote.
            if ($quoteSpecs === []) {
                $quoteSpecs = [[0, 0, 'Fallback']];
            }
            foreach ($quoteSpecs as [$langIdx, $srcIdx, $text]) {
                $quotes->insert((string) $text, $sourceIds[$srcIdx], $languageIds[$langIdx]);
            }

            if ($deIdx >= 0) {
                $mappings->assign('de', $languageIds[$deIdx]);
            }
            if ($enIdx >= 0) {
                $mappings->assign('en', $languageIds[$enIdx]);
            }

            $handler = new SearchHandler(
                $quotes,
                $languages,
                new RandomQuoteSelector($quotes, $this->fixedRng($drawInt / 1000), $mappings)
            );

            $query = $ui === null ? [] : ['uiLanguage' => $ui];
            $response = $handler->handle(new Request('GET', 'random', $query));

            self::assertSame(200, $response->status);
            $body = $response->body;
            self::assertIsArray($body);

            self::assertEqualsCanonicalizing(
                ['id', 'text', 'languageId', 'languageName', 'source'],
                array_keys($body)
            );
            self::assertIsInt($body['id']);
            self::assertIsString($body['text']);
            self::assertIsInt($body['languageId']);
            self::assertIsString($body['languageName']);
            self::assertIsArray($body['source']);
            self::assertEqualsCanonicalizing(['id', 'name'], array_keys($body['source']));
            self::assertIsInt($body['source']['id']);
            self::assertIsString($body['source']['name']);

            // The shaped values are those of the stored quote.
            $stored = $quotes->findById($body['id']);
            self::assertNotNull($stored);
            self::assertSame($stored['text'], $body['text']);
            self::assertSame($stored['languageId'], $body['languageId']);
            self::assertSame($stored['languageName'], $body['languageName']);
            self::assertSame($stored['source']['id'], $body['source']['id']);
            self::assertSame($stored['source']['name'], $body['source']['name']);
        });
    }
}
