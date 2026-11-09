<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\SourceCreator;
use Citations\SourceDeleter;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 34: Quelle ohne Zitate löschen
 * entfernt die Quelle.
 *
 * Für jede vorhandene, nicht als Unbekannt-Quelle markierte Quelle, der kein
 * Zitat zugeordnet ist, gilt: Das Löschen entfernt genau diese Quelle (sie ist
 * danach nicht mehr lesbar) und bestätigt den Erfolg; keine andere Quelle wird
 * entfernt. Code under test: {@see SourceDeleter::delete()} ohne Strategie
 * gegen die in-memory Repositories (design "`DELETE /api/sources/{id}`").
 *
 * The property seeds a number of ordinary (quote-less) sources plus the
 * always-present Unbekannt-Quelle, picks one ordinary source to delete, and
 * asserts the chosen source is gone while every other source — including the
 * Unbekannt-Quelle — remains.
 *
 * Validates: Requirements 11.1
 */
final class SourceDeleterNoQuotesRemovalPropertyTest extends PropertyTestCase
{
    public function testDeleteSourceWithoutQuotesRemovesExactlyThatSource(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // Number of ordinary sources to seed (at least one to delete).
            Generator\choose(1, 6),
            Generator\choose(0, 5)
        )->then(function (int $count, int $pick): void {
            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $quotes = new InMemoryQuoteRepository($store);
            $creator = new SourceCreator($sources);

            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $ids[] = $creator->create('Autor ' . $i)->id;
            }

            $targetId = $ids[$pick % $count];
            $allBefore = array_keys($store->sources);

            $deleter = new SourceDeleter($sources, $quotes);
            $result = $deleter->delete($targetId);

            // (11.1) Success, and the chosen source is gone.
            self::assertTrue($result->isSuccess, 'Deleting a quote-less source must succeed.');
            self::assertSame($targetId, $result->id);
            self::assertNull($sources->findById($targetId), 'The deleted source must no longer be readable.');

            // Every other source still exists (only the target was removed).
            $expectedRemaining = array_values(array_filter(
                $allBefore,
                static fn (int $id): bool => $id !== $targetId
            ));
            self::assertSame(
                $expectedRemaining,
                array_keys($store->sources),
                'only the targeted source may be removed'
            );
            // The Unbekannt-Quelle in particular survives.
            self::assertNotNull($sources->findUnknown());
        });
    }
}
