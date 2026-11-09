<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

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
 * Feature: citations-management, Property 37: Löschabbruch lässt alles
 * unverändert.
 *
 * Für jede Quelle mit mindestens einem zugeordneten Zitat gilt: Wird ihr
 * Löschen mit der Strategie "cancel" (Löschabbruch) angefordert, so wird der
 * Vorgang beendet, ohne die Quelle oder eines der zugeordneten Zitate zu
 * verändern. Code under test: {@see SourceDeleter::delete()} mit
 * {@see SourceDeletionStrategy::CANCEL} gegen die in-memory Repositories
 * (design "`DELETE /api/sources/{id}`").
 *
 * The property seeds a source with a generated number of quotes plus a
 * distractor source and quotes, snapshots the complete source and quote world,
 * requests a cancel delete and asserts the store is byte-for-byte unchanged and
 * the target source is still present and readable (11.4).
 *
 * Validates: Requirements 11.4
 */
final class SourceDeleterCancelUnchangedPropertyTest extends PropertyTestCase
{
    public function testCancelLeavesSourceAndQuotesUnchanged(): void
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
            $otherId = $sourceCreator->create('Cicero')->id;
            for ($i = 0; $i < $distractorQuotes; $i++) {
                $quoteCreator->create('Anderes ' . $i, $otherId, $languageId);
            }

            $sourcesBefore = $store->sources;
            $quotesBefore = $store->quotes;

            $deleter = new SourceDeleter($sources, $quotes);
            $result = $deleter->delete($targetId, SourceDeletionStrategy::CANCEL);

            // (11.4) Cancelling is a deliberate no-op, reported as success, and
            // the whole world is left exactly as it was.
            self::assertTrue($result->isSuccess, 'Cancel is a deliberate no-op, not an error.');
            self::assertSame($sourcesBefore, $store->sources, 'cancel must not change any source');
            self::assertSame($quotesBefore, $store->quotes, 'cancel must not change any quote');
            self::assertNotNull($sources->findById($targetId), 'the source must still exist after cancel');
        });
    }
}
