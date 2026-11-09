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
 * Feature: citations-management, Property 26: Zitat-Anlegen erzeugt keine neue
 * Quelle.
 *
 * Für jede Anlege-Operation eines Zitats gilt: Die Menge der vorhandenen
 * Quellen ist vor und nach der Operation identisch. Das Anlegen darf
 * ausschließlich eine bereits vorhandene Quelle (oder die Unbekannt-Quelle als
 * Fallback) referenzieren und niemals selbst eine neue Quelle erzeugen — und
 * zwar unabhängig davon, ob der Aufruf erfolgreich ist (gültige Eingabe,
 * gewählte oder fehlende Quelle) oder mit einem beliebigen Fehlercode
 * abgewiesen wird (leerer/überlanger Text, ungültige Quelle, ungültige
 * Sprache). Code under test: {@see QuoteCreator::create()} über die
 * In-Memory-Repositories (design.md "Property 26").
 *
 * Validates: Requirements 8.5
 */
final class QuoteCreatorNoNewSourcePropertyTest extends PropertyTestCase
{
    /**
     * A fresh world seeded with the always-present Unbekannt-Quelle plus a
     * random number of ordinary sources and at least one language, returning
     * the wired-up creator together with its backing store.
     *
     * @return array{QuoteCreator, InMemoryStore, list<int>, int}
     */
    private function worldWith(int $extraSources, int $languageCount): array
    {
        $store = new InMemoryStore();
        $sources = new InMemorySourceRepository($store);
        $languages = new InMemoryLanguageRepository($store);
        $quotes = new InMemoryQuoteRepository($store);

        $sourceIds = [InMemoryStore::UNKNOWN_SOURCE_ID];
        for ($i = 0; $i < $extraSources; $i++) {
            $sourceIds[] = $sources->insert('Quelle ' . $i);
        }

        $languageIds = [];
        for ($i = 0; $i < max(1, $languageCount); $i++) {
            $languageIds[] = $languages->insert('Sprache ' . $i, 'sprache ' . $i);
        }

        $creator = new QuoteCreator($quotes, $sources, $languages);

        return [$creator, $store, $sourceIds, $languageIds[0]];
    }

    /**
     * Snapshot of the full source set (ids mapped to their public records) so
     * two snapshots can be compared for exact, element-wise equality.
     *
     * @return array<int, array{id: int, name: string, birthDate: string|null, deathDate: string|null, description: string|null, linkUrl: string|null, isUnknown: bool}>
     */
    private function snapshotSources(InMemoryStore $store): array
    {
        $sources = new InMemorySourceRepository($store);
        $snapshot = [];
        foreach (array_keys($store->sources) as $id) {
            $snapshot[$id] = $sources->findById($id);
        }
        ksort($snapshot);

        return $snapshot;
    }

    /**
     * For any create attempt — valid or invalid, with a chosen source, the
     * null (Unbekannt) fallback or a non-existent source id — the set of
     * sources is byte-for-byte identical before and after the call.
     */
    public function testCreateNeverChangesTheSourceSet(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // number of ordinary sources beyond the seeded Unbekannt-Quelle.
            Generator\choose(0, 5),
            // number of languages to seed (at least one is always created).
            Generator\choose(1, 3),
            // raw quote text: includes empty/whitespace (MISSING_TEXT) and
            // ordinary non-empty text (success) inputs.
            Generator\oneOf(
                Generator\elements('', '   ', "\t\n"),
                Generator\string()
            ),
            // source selector: an index into the existing source ids, null for
            // the Unbekannt fallback, or a deliberately out-of-range id to hit
            // the INVALID_SOURCE path.
            Generator\choose(-2, 10),
            // language selector: 0 picks the known language, anything else is
            // an out-of-range id to exercise the INVALID_LANGUAGE path.
            Generator\choose(0, 5)
        )->then(function (
            int $extraSources,
            int $languageCount,
            string $text,
            int $sourceSelector,
            int $languageSelector
        ): void {
            [$creator, $store, $sourceIds, $knownLanguageId] = $this->worldWith($extraSources, $languageCount);

            // Resolve the source argument: -1 => null (Unbekannt fallback),
            // an in-range selector => an existing id, otherwise a definitely
            // unknown id (INVALID_SOURCE).
            if ($sourceSelector === -1) {
                $sourceId = null;
            } elseif ($sourceSelector >= 0 && $sourceSelector < count($sourceIds)) {
                $sourceId = $sourceIds[$sourceSelector];
            } else {
                $sourceId = 10_000 + abs($sourceSelector);
            }

            $languageId = $languageSelector === 0 ? $knownLanguageId : 10_000 + $languageSelector;

            $before = $this->snapshotSources($store);
            $sourceCountBefore = count($store->sources);

            $creator->create($text, $sourceId, $languageId);

            $after = $this->snapshotSources($store);

            self::assertCount(
                $sourceCountBefore,
                $store->sources,
                'Create must not add or remove any source.'
            );
            self::assertSame(
                $before,
                $after,
                'The set of sources must be identical before and after a create.'
            );
        });
    }
}
