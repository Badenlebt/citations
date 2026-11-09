<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\SourceCreator;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 31: Ungültiger Quellenname verhindert
 * jede Zustandsänderung.
 *
 * Für jeden Quellennamen, der nach Trim leer ist, gilt sowohl beim Anlegen als
 * auch beim Bearbeiten: Die Operation wird mit MISSING_NAME abgewiesen; beim
 * Anlegen entsteht keine neue Quelle, beim Bearbeiten bleibt die bestehende
 * Quelle vollständig unverändert. Code under test:
 * {@see SourceCreator::create()} und {@see SourceCreator::edit()} gegen das
 * in-memory {@see InMemorySourceRepository}.
 *
 * The generator yields strings built only from characters that PHP's trim()
 * strips (plus the empty string), so every input is trim-empty by construction.
 * Each iteration checks both paths against a world that already holds one
 * ordinary source, proving the MISSING_NAME code and the absence of any state
 * change on both the create and the edit path (10.2).
 *
 * Validates: Requirements 10.2
 */
final class SourceCreatorInvalidNamePropertyTest extends PropertyTestCase
{
    /** Whitespace characters removed by PHP's trim() by default. */
    private const TRIM_WHITESPACE = [" ", "\t", "\n", "\r", "\0", "\x0B"];

    /** Snapshot every stored source so a "no state change" assertion is exact. */
    private function snapshot(InMemoryStore $store, InMemorySourceRepository $sources): array
    {
        $snapshot = [];
        foreach (array_keys($store->sources) as $id) {
            $snapshot[$id] = $sources->findById($id);
        }

        return $snapshot;
    }

    public function testTrimEmptyNameRejectsCreateAndEditWithoutStateChange(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\seq(Generator\choose(0, count(self::TRIM_WHITESPACE) - 1))
        )->then(function (array $picks): void {
            $name = '';
            foreach ($picks as $p) {
                $name .= self::TRIM_WHITESPACE[$p % count(self::TRIM_WHITESPACE)];
            }
            self::assertSame('', trim($name), 'generator must produce trim-empty name');

            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $creator = new SourceCreator($sources);

            // An existing ordinary source to attempt to edit.
            $existing = $creator->create('Seneca');
            self::assertTrue($existing->isSuccess);
            $before = $this->snapshot($store, $sources);

            // (10.2) Create with trim-empty name: MISSING_NAME, no new source.
            $created = $creator->create($name);
            self::assertFalse($created->isSuccess);
            self::assertSame(ErrorCode::MISSING_NAME, $created->error);
            self::assertNull($created->id);
            self::assertSame(
                $before,
                $this->snapshot($store, $sources),
                'create with trim-empty name must not add a source or change any state'
            );

            // (10.2) Edit the existing source with trim-empty name: MISSING_NAME,
            // the stored source stays fully unchanged.
            $edited = $creator->edit($existing->id, $name);
            self::assertFalse($edited->isSuccess);
            self::assertSame(ErrorCode::MISSING_NAME, $edited->error);
            self::assertNull($edited->id);
            self::assertSame(
                $before,
                $this->snapshot($store, $sources),
                'edit with trim-empty name must leave the existing source fully unchanged'
            );
        });
    }
}
