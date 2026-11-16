<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\RandomNumberGenerator;
use Citations\RandomQuoteSelector;
use Citations\UiLanguage;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\InMemoryUiLanguageMappingRepository;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see RandomQuoteSelector}, the composition the
 * `GET /api/random` endpoint calls (Requirements 3.1, 3.3). The randomness is
 * injected through a fixed {@see RandomNumberGenerator} so the selected index
 * is deterministic; the distribution/full-reachability property (3.2) is
 * covered by the pure {@see \Citations\RandomSelector} property test.
 */
final class RandomQuoteSelectorTest extends TestCase
{
    private InMemoryStore $store;
    private InMemorySourceRepository $sources;
    private InMemoryQuoteRepository $quotes;
    private InMemoryLanguageRepository $languages;
    private int $languageId;
    private int $sourceId;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->sources = new InMemorySourceRepository($this->store);
        $this->quotes = new InMemoryQuoteRepository($this->store);
        $this->languages = new InMemoryLanguageRepository($this->store);
        $this->languageId = $this->languages->insert('Deutsch', 'deutsch');
        $this->sourceId = $this->sources->insert('Seneca', ['linkUrl' => 'https://example.test/seneca']);
    }

    public function testReturnsQuoteAtSelectedIndexWithFullTextLanguageAndSource(): void
    {
        // Three quotes in ascending-id order: indices 0, 1, 2.
        $this->quotes->insert('Erstes Zitat', $this->sourceId, $this->languageId);
        $secondId = $this->quotes->insert('Zweites Zitat', $this->sourceId, $this->languageId);
        $this->quotes->insert('Drittes Zitat', $this->sourceId, $this->languageId);

        // r = 0.5 over n = 3 maps to floor(0.5 * 3) = index 1 (the second quote).
        $selector = new RandomQuoteSelector($this->quotes, $this->fixedRng(0.5), new InMemoryUiLanguageMappingRepository($this->store));

        $quote = $selector->pick();

        self::assertNotNull($quote, 'non-empty stock must yield exactly one quote (3.1)');
        self::assertSame($secondId, $quote['id']);
        self::assertSame('Zweites Zitat', $quote['text']);
        self::assertSame($this->languageId, $quote['languageId']);
        self::assertSame('Deutsch', $quote['languageName']);
        self::assertSame($this->sourceId, $quote['source']['id']);
        self::assertSame('Seneca', $quote['source']['name']);
        self::assertSame('https://example.test/seneca', $quote['source']['linkUrl']);
    }

    public function testLowestDrawSelectsFirstQuoteAndHighestDrawSelectsLastQuote(): void
    {
        $firstId = $this->quotes->insert('Erstes Zitat', $this->sourceId, $this->languageId);
        $this->quotes->insert('Zweites Zitat', $this->sourceId, $this->languageId);
        $lastId = $this->quotes->insert('Drittes Zitat', $this->sourceId, $this->languageId);

        // r = 0.0 -> index 0; r just below 1.0 -> index n - 1.
        $first = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.0), new InMemoryUiLanguageMappingRepository($this->store)))->pick();
        $last = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.999999), new InMemoryUiLanguageMappingRepository($this->store)))->pick();

        self::assertNotNull($first);
        self::assertNotNull($last);
        self::assertSame($firstId, $first['id']);
        self::assertSame($lastId, $last['id']);
    }

    public function testEmptyStockReturnsNoQuoteSignal(): void
    {
        // No quotes inserted: count() == 0.
        $selector = new RandomQuoteSelector($this->quotes, $this->fixedRng(0.5), new InMemoryUiLanguageMappingRepository($this->store));

        self::assertNull($selector->pick(), 'empty stock must signal NO_QUOTES (3.3)');
    }

    public function testMappedUiLanguageSelectsFromMappedLanguageOnly(): void
    {
        $en = $this->languages->insert('English', 'english');
        $this->quotes->insert('Deutsch eins', $this->sourceId, $this->languageId);
        $englishFirst = $this->quotes->insert('English one', $this->sourceId, $en);
        $englishLast = $this->quotes->insert('English two', $this->sourceId, $en);
        $this->quotes->insert('Deutsch zwei', $this->sourceId, $this->languageId);
        $mappings = new InMemoryUiLanguageMappingRepository($this->store);
        $mappings->assign('en', $en);

        $first = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.0), $mappings))->pick(UiLanguage::EN);
        $last = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.999999), $mappings))->pick(UiLanguage::EN);

        self::assertNotNull($first);
        self::assertNotNull($last);
        self::assertSame($englishFirst, $first['id']);
        self::assertSame($englishLast, $last['id']);
    }

    public function testUiLanguageWithoutQuotesFallsBackToEnglishMapping(): void
    {
        $en = $this->languages->insert('English', 'english');
        $this->quotes->insert('Deutsch eins', $this->sourceId, $this->languageId);
        $englishId = $this->quotes->insert('English one', $this->sourceId, $en);
        $mappings = new InMemoryUiLanguageMappingRepository($this->store);
        // 'de' is mapped to a language without quotes; 'en' has quotes.
        $emptyDe = $this->languages->insert('Leer', 'leer');
        $mappings->assign('de', $emptyDe);
        $mappings->assign('en', $en);

        $quote = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.0), $mappings))->pick(UiLanguage::DE);

        self::assertNotNull($quote);
        self::assertSame($englishId, $quote['id']);
    }

    public function testUnmappedUiLanguageFallsBackToEnglishMapping(): void
    {
        $en = $this->languages->insert('English', 'english');
        $this->quotes->insert('Deutsch eins', $this->sourceId, $this->languageId);
        $englishId = $this->quotes->insert('English one', $this->sourceId, $en);
        $mappings = new InMemoryUiLanguageMappingRepository($this->store);
        $mappings->assign('en', $en);

        $quote = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.0), $mappings))->pick(UiLanguage::DE);

        self::assertNotNull($quote);
        self::assertSame($englishId, $quote['id']);
    }

    public function testNoUsableMappingFallsBackToWholeStock(): void
    {
        $firstId = $this->quotes->insert('Erstes', $this->sourceId, $this->languageId);
        $lastId = $this->quotes->insert('Letztes', $this->sourceId, $this->languageId);
        $emptyLang = $this->languages->insert('Leer', 'leer');
        $mappings = new InMemoryUiLanguageMappingRepository($this->store);
        $mappings->assign('en', $emptyLang);

        // No mapping at all, and a mapping onto a language without quotes.
        foreach ([new InMemoryUiLanguageMappingRepository(new InMemoryStore()), $mappings] as $repo) {
            $first = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.0), $repo))->pick(UiLanguage::DE);
            $last = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.999999), $repo))->pick(UiLanguage::DE);
            self::assertSame($firstId, $first['id'] ?? null);
            self::assertSame($lastId, $last['id'] ?? null);
        }
    }

    public function testEmptyStockWithUiLanguageReturnsNull(): void
    {
        $mappings = new InMemoryUiLanguageMappingRepository($this->store);
        $mappings->assign('de', $this->languageId);

        self::assertNull((new RandomQuoteSelector($this->quotes, $this->fixedRng(0.5), $mappings))->pick(UiLanguage::DE));
    }

    public function testWithoutUiLanguageMappingIsIgnored(): void
    {
        $en = $this->languages->insert('English', 'english');
        $firstId = $this->quotes->insert('Deutsch eins', $this->sourceId, $this->languageId);
        $this->quotes->insert('English one', $this->sourceId, $en);
        $mappings = new InMemoryUiLanguageMappingRepository($this->store);
        $mappings->assign('en', $en);

        $quote = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.0), $mappings))->pick();

        self::assertNotNull($quote);
        self::assertSame($firstId, $quote['id']);
    }

    /**
     * A deterministic {@see RandomNumberGenerator} that always returns the same
     * injected draw, so the selected index is fully reproducible.
     */
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
}
