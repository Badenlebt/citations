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
 * Feature: citations-management, Property 42: Genau eine Unbekannt-Quelle
 * bleibt erhalten.
 *
 * Für jede Folge von Mutationsoperationen der Autoren- und Zitat-API gilt:
 * Zu jedem Zeitpunkt existiert genau eine Unbekannt-Quelle mit dem Namen
 * "unbekannt". Code under test: {@see SourceCreator}, {@see SourceDeleter} und
 * {@see QuoteCreator} gegen die in-memory Repositories, deren
 * {@see InMemoryStore} genau eine Unbekannt-Quelle vorinitialisiert
 * (Requirement 13.1).
 *
 * The property drives a randomized program of mutations — create ordinary
 * sources, attempt a reserved-name create, create quotes, delete sources with
 * reassign/cancel, attempt to delete the Unbekannt-Quelle — and after every
 * step asserts the count of sources flagged `isUnknown` is exactly one and that
 * `findUnknown()` still resolves to a source named "unbekannt".
 *
 * Validates: Requirements 13.1
 */
final class SourceExactlyOneUnknownPropertyTest extends PropertyTestCase
{
    /** Count the sources currently flagged as the Unbekannt-Quelle. */
    private function unknownCount(InMemoryStore $store): int
    {
        $count = 0;
        foreach ($store->sources as $row) {
            if ($row['isUnknown']) {
                $count++;
            }
        }

        return $count;
    }

    public function testExactlyOneUnknownSourceAcrossAllOperations(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // A sequence of opcodes driving different mutations.
            Generator\seq(Generator\choose(0, 5))
        )->then(function (array $ops): void {
            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $quotes = new InMemoryQuoteRepository($store);
            $languages = new InMemoryLanguageRepository($store);
            $sourceCreator = new SourceCreator($sources);
            $sourceDeleter = new SourceDeleter($sources, $quotes);
            $quoteCreator = new QuoteCreator($quotes, $sources, $languages);
            $languageId = $languages->insert('Deutsch', 'deutsch');

            $assertInvariant = function () use ($store, $sources): void {
                self::assertSame(1, $this->unknownCount($store), 'exactly one Unbekannt-Quelle must exist');
                self::assertSame('unbekannt', $sources->findUnknown()['name']);
                self::assertTrue($sources->findUnknown()['isUnknown']);
            };

            // Invariant holds from the start (seeded world).
            $assertInvariant();

            $ordinaryIds = [];
            $counter = 0;
            foreach ($ops as $op) {
                switch ($op % 6) {
                    case 0: // create an ordinary source
                        $r = $sourceCreator->create('Autor ' . $counter++);
                        if ($r->isSuccess) {
                            $ordinaryIds[] = $r->id;
                        }
                        break;
                    case 1: // attempt a reserved-name create (must be rejected)
                        $sourceCreator->create('UnBeKannt');
                        break;
                    case 2: // create a quote on an ordinary source (or unknown)
                        $target = $ordinaryIds[0] ?? $sources->findUnknown()['id'];
                        $quoteCreator->create('Zitat ' . $counter++, $target, $languageId);
                        break;
                    case 3: // delete an ordinary source with reassign
                        if ($ordinaryIds !== []) {
                            $id = array_shift($ordinaryIds);
                            $sourceDeleter->delete($id, SourceDeletionStrategy::REASSIGN);
                        }
                        break;
                    case 4: // delete an ordinary source with cancel
                        if ($ordinaryIds !== []) {
                            $sourceDeleter->delete($ordinaryIds[0], SourceDeletionStrategy::CANCEL);
                        }
                        break;
                    case 5: // attempt to delete the Unbekannt-Quelle (rejected)
                        $sourceDeleter->delete($sources->findUnknown()['id'], SourceDeletionStrategy::REASSIGN);
                        break;
                }

                $assertInvariant();
            }
        });
    }
}
