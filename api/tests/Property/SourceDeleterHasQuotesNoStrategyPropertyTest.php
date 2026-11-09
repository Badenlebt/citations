<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\QuoteCreator;
use Citations\SourceCreator;
use Citations\SourceDeleter;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 35: Löschen einer Quelle mit Zitaten
 * ohne Strategie ändert nichts.
 *
 * Für jede Quelle, der mindestens ein Zitat zugeordnet ist, gilt: Wird ihr
 * Löschen ohne Strategie angefordert, so wird der Vorgang mit SOURCE_HAS_QUOTES
 * angehalten; die Quelle und alle zugeordneten Zitate bleiben vollständig
 * unverändert. Code under test: {@see SourceDeleter::delete()} mit strategy
 * null gegen die in-memory Repositories (design "`DELETE /api/sources/{id}`").
 *
 * The property seeds a source with a generated number of quotes (>= 1) and a
 * few distractor sources/quotes, snapshots the whole source and quote world,
 * requests the delete without a strategy and asserts the SOURCE_HAS_QUOTES
 * conflict and a byte-for-byte unchanged store (11.2).
 *
 * Validates: Requirements 11.2
 */
final class SourceDeleterHasQuotesNoStrategyPropertyTest extends PropertyTestCase
{
    public function testDeleteWithQuotesAndNoStrategyChangesNothing(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(1, 5),
            Generator\choose(0, 3)
        )->then(function (int $quoteCount, int $distractorQuotes): void {
            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $quotes = new InMemoryQuoteRepository($store);
            $languages = new InMemoryLanguageRepository($store);
            $sourceCreator = new SourceCreator($sources);
            $quoteCreator = new QuoteCreator($quotes, $sources, $languages);

            $languageId = $languages->insert('Deutsch', 'deutsch');
            $targetId = $sourceCreator->create('Seneca')->id;
            for ($i = 0; $i < $quoteCount; $i++) {
                $quoteCreator->create('Zitat ' . $i, $targetId, $languageId);
            }

            // A distractor source carrying its own quotes, to prove nothing
            // else is touched either.
            $otherId = $sourceCreator->create('Cicero')->id;
            for ($i = 0; $i < $distractorQuotes; $i++) {
                $quoteCreator->create('Anderes ' . $i, $otherId, $languageId);
            }

            $sourcesBefore = $store->sources;
            $quotesBefore = $store->quotes;

            $deleter = new SourceDeleter($sources, $quotes);
            $result = $deleter->delete($targetId, null);

            // (11.2) Halted with the conflict code; nothing changed.
            self::assertFalse($result->isSuccess, 'Deleting a source with quotes and no strategy must be halted.');
            self::assertSame(ErrorCode::SOURCE_HAS_QUOTES, $result->error);
            self::assertNull($result->id);
            self::assertSame($sourcesBefore, $store->sources, 'no source may change on a halted delete');
            self::assertSame($quotesBefore, $store->quotes, 'no quote may change on a halted delete');
        });
    }
}
