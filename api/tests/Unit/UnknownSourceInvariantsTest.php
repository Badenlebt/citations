<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\SourceCreator;
use Citations\SourceDeleter;
use Citations\SourceDeletionStrategy;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for the Unbekannt-Quelle invariants (Requirements 11.5, 13.1,
 * 13.2): the Unbekannt-Quelle is undeletable, exactly one of it remains, and
 * the name "unbekannt" is reserved.
 */
final class UnknownSourceInvariantsTest extends TestCase
{
    private InMemoryStore $store;
    private InMemorySourceRepository $sources;
    private InMemoryQuoteRepository $quotes;
    private SourceCreator $creator;
    private SourceDeleter $deleter;
    private int $unknownId;
    private int $languageId;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->sources = new InMemorySourceRepository($this->store);
        $this->quotes = new InMemoryQuoteRepository($this->store);
        $this->creator = new SourceCreator($this->sources);
        $this->deleter = new SourceDeleter($this->sources, $this->quotes);
        $this->unknownId = $this->sources->findUnknown()['id'];
        $languages = new InMemoryLanguageRepository($this->store);
        $this->languageId = $languages->insert('Deutsch', 'deutsch');
    }

    // --- 11.5 / 13.1: Unbekannt-Quelle is undeletable ---

    public function testDeletingUnknownSourceWithoutStrategyIsRejectedAndChangesNothing(): void
    {
        $result = $this->deleter->delete($this->unknownId, null);

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::UNKNOWN_SOURCE_UNDELETABLE, $result->error);
        self::assertNotNull($this->sources->findById($this->unknownId));
    }

    public function testDeletingUnknownSourceWithReassignIsRejectedAndChangesNothing(): void
    {
        $result = $this->deleter->delete($this->unknownId, SourceDeletionStrategy::REASSIGN);

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::UNKNOWN_SOURCE_UNDELETABLE, $result->error);
        self::assertNotNull($this->sources->findById($this->unknownId));
    }

    public function testDeletingUnknownSourceWithCancelIsRejectedAndChangesNothing(): void
    {
        $result = $this->deleter->delete($this->unknownId, SourceDeletionStrategy::CANCEL);

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::UNKNOWN_SOURCE_UNDELETABLE, $result->error);
        self::assertNotNull($this->sources->findById($this->unknownId));
    }

    public function testUnknownSourceWithAssignedQuotesStaysUndeletable(): void
    {
        $quoteId = $this->quotes->insert('Anon', $this->unknownId, $this->languageId);

        $result = $this->deleter->delete($this->unknownId, SourceDeletionStrategy::REASSIGN);

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::UNKNOWN_SOURCE_UNDELETABLE, $result->error);
        self::assertNotNull($this->sources->findById($this->unknownId));
        self::assertSame($this->unknownId, $this->quotes->findById($quoteId)['source']['id']);
    }

    // --- 13.2: name "unbekannt" is reserved ---

    /**
     * @dataProvider reservedNameVariants
     */
    public function testCreatingSourceWithReservedNameIsRejectedAndChangesNothing(string $name): void
    {
        $countBefore = count($this->store->sources);

        $result = $this->creator->create($name);

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::UNKNOWN_SOURCE_RESERVED, $result->error);
        self::assertCount($countBefore, $this->store->sources, 'no source may be created');
    }

    /**
     * @dataProvider reservedNameVariants
     */
    public function testEditingAnotherSourceToReservedNameIsRejectedAndChangesNothing(string $name): void
    {
        $otherId = $this->sources->insert('Seneca', []);
        $before = $this->sources->findById($otherId);

        $result = $this->creator->edit($otherId, $name);

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::UNKNOWN_SOURCE_RESERVED, $result->error);
        self::assertSame($before, $this->sources->findById($otherId), 'the other source must be unchanged');
    }

    public static function reservedNameVariants(): array
    {
        return [
            'lowercase' => ['unbekannt'],
            'with surrounding whitespace' => ['  unbekannt  '],
            'uppercase' => ['UNBEKANNT'],
            'mixed case' => ['UnBeKaNnT'],
        ];
    }

    public function testEditingTheUnknownSourceItselfToKeepItsNameIsAllowed(): void
    {
        // Editing the Unbekannt-Quelle row itself to retain the reserved name
        // must not be blocked by the reservation (only OTHER sources are).
        $result = $this->creator->edit($this->unknownId, 'unbekannt');

        self::assertTrue($result->isSuccess);
        self::assertSame($this->unknownId, $result->id);
        self::assertSame('unbekannt', $this->sources->findById($this->unknownId)['name']);
    }

    public function testNonReservedNameIsStillAccepted(): void
    {
        $result = $this->creator->create('Unbekannter Autor');

        self::assertTrue($result->isSuccess, 'a different name containing the word must still be allowed');
    }
}
