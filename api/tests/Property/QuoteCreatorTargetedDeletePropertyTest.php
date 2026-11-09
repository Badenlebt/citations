<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\QuoteCreator;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 28: Zitat-Löschen entfernt genau das
 * Zitat.
 *
 * Für jeden Zitatbestand und jedes darin vorhandene Zitat gilt: Das Löschen
 * genau dieses Zitats entfernt es aus dem Bestand und lässt alle übrigen Zitate
 * unverändert. Code under test: {@see QuoteCreator::delete()} against the
 * in-memory repository doubles (design "`DELETE /api/quotes/{id}`").
 *
 * Each iteration seeds a shared {@see InMemoryStore} with several quotes
 * (distinct, non-empty texts over the always-present source/language), picks
 * one existing quote by index, deletes it and asserts three things together:
 * the count dropped by exactly one, the chosen quote is gone (findById => null),
 * and every other quote reads back byte-for-byte unchanged versus the snapshot
 * taken before the delete.
 *
 * Validates: Requirements 9.4
 */
final class QuoteCreatorTargetedDeletePropertyTest extends PropertyTestCase
{
    /** Snapshot every stored quote by id so an "unchanged" assertion is exact. */
    private function snapshot(InMemoryStore $store, InMemoryQuoteRepository $quotes): array
    {
        $snapshot = [];
        foreach (array_keys($store->quotes) as $id) {
            $snapshot[$id] = $quotes->findById($id);
        }

        return $snapshot;
    }

    /**
     * Property 28: deleting one existing quote removes exactly that quote and
     * leaves all others untouched.
     */
    public function testDeletingOneQuoteRemovesExactlyItAndLeavesOthersUnchanged(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // A sequence of extra quote texts to seed on top of one always-present
            // base quote (so the store is never empty); and a selector used
            // (modulo the number of seeded quotes) to pick the one to delete.
            Generator\seq(Generator\string()),
            Generator\nat()
        )->then(function (array $extraTexts, int $selector): void {
            $store = new InMemoryStore();
            $quotes = new InMemoryQuoteRepository($store);
            $sources = new InMemorySourceRepository($store);
            $languages = new InMemoryLanguageRepository($store);

            $sourceId = $sources->insert('Seneca');
            $languageId = $languages->insert('Deutsch', 'deutsch');
            $creator = new QuoteCreator($quotes, $sources, $languages);

            // Seed several quotes with guaranteed-valid texts. One base quote is
            // always present so the store is never empty; each text is made
            // distinct and non-empty regardless of the generated string so the
            // create precondition (non-empty after trim) always holds.
            $texts = array_merge([''], array_values($extraTexts));
            $createdIds = [];
            foreach ($texts as $index => $text) {
                $valid = 'Zitat #' . $index . ' ' . $text;
                $result = $creator->create($valid, $sourceId, $languageId);
                self::assertTrue(
                    $result->isSuccess,
                    'seed create must succeed for a guaranteed-valid text'
                );
                $createdIds[] = $result->id;
            }

            $countBefore = $quotes->count();
            self::assertSame(count($createdIds), $countBefore);

            $before = $this->snapshot($store, $quotes);

            // Pick one existing quote to delete.
            $target = $createdIds[$selector % count($createdIds)];

            $result = $creator->delete($target);

            // (9.4) The delete of an existing quote succeeds and reports its id.
            self::assertTrue($result->isSuccess, 'deleting an existing quote must succeed');
            self::assertSame($target, $result->id);

            // The count dropped by exactly one.
            self::assertSame(
                $countBefore - 1,
                $quotes->count(),
                'deleting one quote must drop the total by exactly one'
            );

            // The targeted quote is gone.
            self::assertNull(
                $quotes->findById($target),
                'the deleted quote must no longer be readable'
            );

            // Every other quote remains byte-for-byte unchanged, and no
            // unrelated quote was removed.
            $after = $this->snapshot($store, $quotes);
            unset($before[$target]);
            self::assertSame(
                $before,
                $after,
                'all quotes other than the deleted one must stay fully unchanged'
            );
        });
    }
}
