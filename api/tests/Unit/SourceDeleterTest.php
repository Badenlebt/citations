<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\SourceDeleter;
use Citations\SourceDeletionStrategy;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see SourceDeleter} covering all four delete cases
 * (Requirements 11.1, 11.2, 11.3, 11.4).
 */
final class SourceDeleterTest extends TestCase
{
    private InMemoryStore $store;
    private InMemorySourceRepository $sources;
    private InMemoryQuoteRepository $quotes;
    private InMemoryLanguageRepository $languages;
    private SourceDeleter $deleter;
    private int $languageId;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->sources = new InMemorySourceRepository($this->store);
        $this->quotes = new InMemoryQuoteRepository($this->store);
        $this->languages = new InMemoryLanguageRepository($this->store);
        $this->deleter = new SourceDeleter($this->sources, $this->quotes);
        $this->languageId = $this->languages->insert('Deutsch', 'deutsch');
    }

    public function testDeleteSourceWithoutQuotesRemovesIt(): void
    {
        $id = $this->sources->insert('Seneca', []);

        $result = $this->deleter->delete($id);

        self::assertTrue($result->isSuccess);
        self::assertSame($id, $result->id);
        self::assertNull($this->sources->findById($id), 'source without quotes must be removed (11.1)');
    }

    public function testDeleteMissingSourceReturnsNotFound(): void
    {
        $result = $this->deleter->delete(9999);

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::NOT_FOUND, $result->error);
    }

    public function testDeleteSourceWithQuotesWithoutStrategyIsHaltedAndChangesNothing(): void
    {
        $id = $this->sources->insert('Cicero', []);
        $quoteId = $this->quotes->insert('Esse quam videri', $id, $this->languageId);

        $result = $this->deleter->delete($id, null);

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::SOURCE_HAS_QUOTES, $result->error);
        // (11.2) Source and its quote remain entirely unchanged.
        self::assertNotNull($this->sources->findById($id));
        $quote = $this->quotes->findById($quoteId);
        self::assertNotNull($quote);
        self::assertSame($id, $quote['source']['id']);
    }

    public function testReassignMovesQuotesToUnknownThenDeletesSource(): void
    {
        $id = $this->sources->insert('Cicero', []);
        $q1 = $this->quotes->insert('A', $id, $this->languageId);
        $q2 = $this->quotes->insert('B', $id, $this->languageId);
        $totalBefore = $this->quotes->count();
        $unknownId = $this->sources->findUnknown()['id'];

        $result = $this->deleter->delete($id, SourceDeletionStrategy::REASSIGN);

        self::assertTrue($result->isSuccess);
        // (11.3 / Property 36) Source removed, all quotes preserved and moved to Unbekannt.
        self::assertNull($this->sources->findById($id));
        self::assertSame($totalBefore, $this->quotes->count());
        self::assertSame($unknownId, $this->quotes->findById($q1)['source']['id']);
        self::assertSame($unknownId, $this->quotes->findById($q2)['source']['id']);
    }

    public function testCancelLeavesEverythingUnchanged(): void
    {
        $id = $this->sources->insert('Cicero', []);
        $quoteId = $this->quotes->insert('Esse quam videri', $id, $this->languageId);

        $result = $this->deleter->delete($id, SourceDeletionStrategy::CANCEL);

        // (11.4 / Property 37) Deliberate abort: no error, nothing changed.
        self::assertTrue($result->isSuccess);
        self::assertSame($id, $result->id);
        self::assertNotNull($this->sources->findById($id));
        $quote = $this->quotes->findById($quoteId);
        self::assertNotNull($quote);
        self::assertSame($id, $quote['source']['id']);
    }
}
