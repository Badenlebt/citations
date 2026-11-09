<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\QuoteCreator;
use Citations\SourceCreator;
use Citations\SourceDeleter;
use Citations\SourceDeletionStrategy;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 38: Die Unbekannt-Quelle ist
 * unlöschbar.
 *
 * Für die Unbekannt-Quelle gilt, unabhängig davon ob die Strategie "reassign",
 * "cancel" oder keine Strategie angegeben wird: Das Löschen wird mit
 * UNKNOWN_SOURCE_UNDELETABLE abgelehnt, und die Unbekannt-Quelle sowie alle ihr
 * zugeordneten Zitate bleiben unverändert. Code under test:
 * {@see SourceDeleter::delete()} auf der Unbekannt-Quelle gegen die in-memory
 * Repositories (design "`DELETE /api/sources/{id}`").
 *
 * The property varies the strategy (none / reassign / cancel) and the number of
 * quotes assigned directly to the Unbekannt-Quelle, snapshots the world, and
 * asserts the delete is rejected with UNKNOWN_SOURCE_UNDELETABLE and nothing
 * changed in every case (11.5, 13.1).
 *
 * Validates: Requirements 11.5
 */
final class SourceDeleterUnknownUndeletablePropertyTest extends PropertyTestCase
{
    public function testUnknownSourceCannotBeDeletedUnderAnyStrategy(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // 0 = no strategy, 1 = reassign, 2 = cancel.
            Generator\choose(0, 2),
            Generator\choose(0, 4)
        )->then(function (int $strategyKind, int $quoteCount): void {
            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $quotes = new InMemoryQuoteRepository($store);
            $languages = new InMemoryLanguageRepository($store);
            $quoteCreator = new QuoteCreator($quotes, $sources, $languages);

            $languageId = $languages->insert('Deutsch', 'deutsch');
            $unknownId = $sources->findUnknown()['id'];

            // Quotes assigned directly to the Unbekannt-Quelle.
            for ($i = 0; $i < $quoteCount; $i++) {
                $quoteCreator->create('Zitat ' . $i, $unknownId, $languageId);
            }

            $strategy = match ($strategyKind) {
                1 => SourceDeletionStrategy::REASSIGN,
                2 => SourceDeletionStrategy::CANCEL,
                default => null,
            };

            $sourcesBefore = $store->sources;
            $quotesBefore = $store->quotes;

            $deleter = new SourceDeleter($sources, $quotes);
            $result = $deleter->delete($unknownId, $strategy);

            // (11.5 / 13.1) Rejected, nothing changed, Unbekannt-Quelle intact.
            self::assertFalse($result->isSuccess, 'The Unbekannt-Quelle must never be deletable.');
            self::assertSame(ErrorCode::UNKNOWN_SOURCE_UNDELETABLE, $result->error);
            self::assertNull($result->id);
            self::assertSame($sourcesBefore, $store->sources, 'no source may change on an undeletable attempt');
            self::assertSame($quotesBefore, $store->quotes, 'no quote may change on an undeletable attempt');
            self::assertNotNull($sources->findUnknown(), 'the Unbekannt-Quelle must still exist');
            self::assertTrue($sources->findById($unknownId)['isUnknown']);
        });
    }
}
