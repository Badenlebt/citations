<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\QuoteCreator;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 29: Operationen auf nicht vorhandenen
 * Zitaten ändern nichts.
 *
 * Für jede Kennung, die zu keinem vorhandenen Zitat gehört, gilt sowohl beim
 * Bearbeiten als auch beim Löschen: Die Operation wird mit NOT_FOUND
 * abgewiesen, und der gesamte Datenstand bleibt unverändert.
 *
 * Each iteration builds a fresh world seeded with a random number of valid
 * quotes, then derives an id that is guaranteed NOT to belong to any stored
 * quote and runs both {@see QuoteCreator::edit()} and {@see QuoteCreator::delete()}
 * against it. Non-existence is guaranteed by construction in two complementary
 * ways (driven by the generator): either an id at or below zero (ids are always
 * positive, auto-increment) or an id strictly greater than every allocated id.
 * Because the store allocates ids monotonically and never reuses them, such an
 * id can never match a seeded quote. The valid create/edit arguments (source,
 * language, text) are supplied so the edit passes input validation and the
 * NOT_FOUND outcome stems purely from the missing id, not from invalid input.
 *
 * The "no state change" claim is asserted byte-for-byte against a snapshot of
 * the entire quote store taken before the operations, proving neither edit nor
 * delete added, removed or mutated any quote.
 *
 * Validates: Requirements 9.5
 */
final class QuoteMutationUnknownIdNoStateChangePropertyTest extends PropertyTestCase
{
    /**
     * A full snapshot of every stored quote row, keyed by id, so a "no state
     * change" assertion is exact (it detects added, removed and mutated rows).
     *
     * @return array<int, array|null>
     */
    private function snapshot(InMemoryStore $store, InMemoryQuoteRepository $quotes): array
    {
        $snapshot = [];
        foreach (array_keys($store->quotes) as $id) {
            $snapshot[$id] = $quotes->findById($id);
        }

        return $snapshot;
    }

    public function testUnknownIdRejectsEditAndDeleteWithoutStateChange(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // How many valid quotes to seed the world with (0 is allowed: an
            // empty store trivially has no matching id either).
            Generator\choose(0, 6),
            // Which way to make the id non-existent: 0 => id <= 0 (never
            // allocated, ids are positive), 1 => id strictly above the max.
            Generator\choose(0, 1),
            // A positive offset used to push the id past the last allocated id
            // (branch 1) or, negated, below zero (branch 0).
            Generator\choose(0, 50)
        )->then(function (int $seedCount, int $branch, int $offset): void {
            // A fresh, consistent world.
            $store = new InMemoryStore();
            $quotes = new InMemoryQuoteRepository($store);
            $sources = new InMemorySourceRepository($store);
            $languages = new InMemoryLanguageRepository($store);
            $sourceId = $sources->insert('Seneca', []);
            $languageId = $languages->insert('Deutsch', 'deutsch');
            $creator = new QuoteCreator($quotes, $sources, $languages);

            // Seed the store with $seedCount valid quotes.
            for ($i = 0; $i < $seedCount; $i++) {
                $created = $creator->create('Zitat Nummer ' . $i, $sourceId, $languageId);
                self::assertTrue($created->isSuccess, 'seed quotes must be created');
            }

            // Derive an id that belongs to no stored quote, by construction.
            $existingIds = array_keys($store->quotes);
            $maxId = $existingIds === [] ? 0 : max($existingIds);
            $unknownId = $branch === 0 ? -$offset : $maxId + $offset + 1;
            self::assertArrayNotHasKey(
                $unknownId,
                $store->quotes,
                'generated id must not belong to any seeded quote'
            );

            $before = $this->snapshot($store, $quotes);

            // (9.5) Editing a missing quote is NOT_FOUND and changes nothing.
            $edited = $creator->edit($unknownId, 'Ein gültiges Zitat', $sourceId, $languageId);
            self::assertFalse($edited->isSuccess);
            self::assertSame(ErrorCode::NOT_FOUND, $edited->error);
            self::assertNull($edited->id);
            self::assertSame(
                $before,
                $this->snapshot($store, $quotes),
                'edit of an unknown id must leave the whole quote store unchanged'
            );

            // (9.5) Deleting a missing quote is NOT_FOUND and changes nothing.
            $deleted = $creator->delete($unknownId);
            self::assertFalse($deleted->isSuccess);
            self::assertSame(ErrorCode::NOT_FOUND, $deleted->error);
            self::assertNull($deleted->id);
            self::assertSame(
                $before,
                $this->snapshot($store, $quotes),
                'delete of an unknown id must leave the whole quote store unchanged'
            );
        });
    }
}
