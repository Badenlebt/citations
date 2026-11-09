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
 * Feature: citations-management, Property 36: Umhängen bewahrt alle Zitate und
 * entfernt die Quelle.
 *
 * Für jede Quelle mit mindestens einem zugeordneten Zitat gilt: Wird ihr
 * Löschen mit der Strategie "reassign" (Umhängen) angefordert, so werden alle
 * zugeordneten Zitate der Unbekannt-Quelle zugeordnet und anschließend die
 * Quelle entfernt; kein Zitat geht verloren oder wird darüber hinaus verändert.
 * Code under test: {@see SourceDeleter::delete()} mit
 * {@see SourceDeletionStrategy::REASSIGN} gegen die in-memory Repositories
 * (design "`DELETE /api/sources/{id}`", transaktionale Integrität).
 *
 * The property seeds a source with a generated number of quotes plus a
 * distractor source with its own quotes, records the set of quote ids assigned
 * to the target, reassigns-and-deletes, then asserts: the target source is
 * gone, every one of its quotes still exists and is now assigned to the
 * Unbekannt-Quelle, the total quote count is unchanged, and the distractor's
 * quotes keep their original source (11.3).
 *
 * Validates: Requirements 11.3
 */
final class SourceDeleterReassignPreservesQuotesPropertyTest extends PropertyTestCase
{
    public function testReassignMovesAllQuotesToUnknownAndRemovesSource(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(1, 6),
            Generator\choose(0, 3)
        )->then(function (int $quoteCount, int $distractorQuotes): void {
            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $quotes = new InMemoryQuoteRepository($store);
            $languages = new InMemoryLanguageRepository($store);
            $sourceCreator = new SourceCreator($sources);
            $quoteCreator = new QuoteCreator($quotes, $sources, $languages);

            $languageId = $languages->insert('Deutsch', 'deutsch');
            $unknownId = $sources->findUnknown()['id'];

            $targetId = $sourceCreator->create('Seneca')->id;
            $targetQuoteIds = [];
            for ($i = 0; $i < $quoteCount; $i++) {
                $targetQuoteIds[] = $quoteCreator->create('Zitat ' . $i, $targetId, $languageId)->id;
            }

            // A distractor source with its own quotes that must be untouched.
            $otherId = $sourceCreator->create('Cicero')->id;
            $otherQuoteIds = [];
            for ($i = 0; $i < $distractorQuotes; $i++) {
                $otherQuoteIds[] = $quoteCreator->create('Anderes ' . $i, $otherId, $languageId)->id;
            }

            $totalQuotesBefore = count($store->quotes);

            $deleter = new SourceDeleter($sources, $quotes);
            $result = $deleter->delete($targetId, SourceDeletionStrategy::REASSIGN);

            self::assertTrue($result->isSuccess, 'Reassign delete must succeed.');

            // The target source is removed, the Unbekannt-Quelle still exists.
            self::assertNull($sources->findById($targetId), 'The source must be removed after reassign.');
            self::assertNotNull($sources->findById($unknownId), 'The Unbekannt-Quelle must survive.');

            // No quote is lost: total count unchanged.
            self::assertCount($totalQuotesBefore, $store->quotes, 'No quote may be lost on reassign.');

            // Every former target quote still exists and now points at unknown.
            foreach ($targetQuoteIds as $qid) {
                $record = $quotes->findById($qid);
                self::assertNotNull($record, 'Each reassigned quote must still exist.');
                self::assertSame(
                    $unknownId,
                    $record['source']['id'],
                    'Each quote of the deleted source must be reassigned to the Unbekannt-Quelle.'
                );
            }

            // Distractor quotes keep their original source assignment.
            foreach ($otherQuoteIds as $qid) {
                $record = $quotes->findById($qid);
                self::assertNotNull($record);
                self::assertSame($otherId, $record['source']['id'], 'Unrelated quotes must keep their source.');
            }
        });
    }
}
