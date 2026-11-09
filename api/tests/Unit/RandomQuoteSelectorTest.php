<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\RandomNumberGenerator;
use Citations\RandomQuoteSelector;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
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
        $selector = new RandomQuoteSelector($this->quotes, $this->fixedRng(0.5));

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
        $first = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.0)))->pick();
        $last = (new RandomQuoteSelector($this->quotes, $this->fixedRng(0.999999)))->pick();

        self::assertNotNull($first);
        self::assertNotNull($last);
        self::assertSame($firstId, $first['id']);
        self::assertSame($lastId, $last['id']);
    }

    public function testEmptyStockReturnsNoQuoteSignal(): void
    {
        // No quotes inserted: count() == 0.
        $selector = new RandomQuoteSelector($this->quotes, $this->fixedRng(0.5));

        self::assertNull($selector->pick(), 'empty stock must signal NO_QUOTES (3.3)');
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
