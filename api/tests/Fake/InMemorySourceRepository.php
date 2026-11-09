<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Repository\SourceRepositoryInterface;
use Citations\TextNormalizer;

/**
 * Interface-identical in-memory {@see SourceRepositoryInterface} for use as a
 * test double in property-based and example tests of the mutation layer
 * (task 2.3), without a real database.
 *
 * Reference behaviour modelled here:
 *  - The Unbekannt-Quelle is pre-initialized by {@see InMemoryStore} (id 1,
 *    name 'unbekannt', isUnknown = true) and is always readable via
 *    {@see self::findUnknown()} (Requirement 13.1).
 *  - {@see self::existsByNormalizedName()} compares against a per-source
 *    normalized name derived on write (like the PDO layer's `name_normalized`
 *    column), backing the reserved-"unbekannt" check (13.2).
 *
 * Business invariants (unknown source undeletable, has-quotes conflict,
 * reserved name) are enforced by the mutation layer under test, not here; this
 * double only provides raw persistence, mirroring the real interface contract.
 */
final class InMemorySourceRepository implements SourceRepositoryInterface
{
    public function __construct(private InMemoryStore $store)
    {
    }

    public function findById(int $id): ?array
    {
        $row = $this->store->sources[$id] ?? null;

        return $row === null ? null : self::toRecord($row);
    }

    public function findUnknown(): array
    {
        return self::toRecord($this->store->sources[InMemoryStore::UNKNOWN_SOURCE_ID]);
    }

    public function existsByNormalizedName(string $normalizedName): bool
    {
        foreach ($this->store->sources as $row) {
            if ($row['nameNormalized'] === $normalizedName) {
                return true;
            }
        }

        return false;
    }

    public function listAll(int $page, int $pageSize): array
    {
        $total = count($this->store->sources);

        // Mirror the PDO ORDER BY id ASC so the same page addresses the same
        // sources as the real repository (19.3). Store keys are source ids;
        // sorting them yields the stable ascending-id ordering.
        $ids = array_keys($this->store->sources);
        sort($ids);

        // Floor a defensively negative offset to 0; the handler clamps page >= 1
        // and 1 <= pageSize <= 100, so this is a guard, not the normal path.
        $offset = max(0, ($page - 1) * $pageSize);
        $pageIds = array_slice($ids, $offset, $pageSize);

        $items = [];
        foreach ($pageIds as $id) {
            $row = $this->store->sources[$id];
            $items[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'isUnknown' => $row['isUnknown'],
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    public function insert(string $name, array $optional = []): int
    {
        $id = $this->store->allocateSourceId();
        $this->store->sources[$id] = [
            'id' => $id,
            'name' => $name,
            'nameNormalized' => TextNormalizer::normalize($name),
            'birthDate' => $optional['birthDate'] ?? null,
            'deathDate' => $optional['deathDate'] ?? null,
            'description' => $optional['description'] ?? null,
            'linkUrl' => $optional['linkUrl'] ?? null,
            'isUnknown' => false,
        ];

        return $id;
    }

    public function update(int $id, string $name, array $optional = []): bool
    {
        if (!isset($this->store->sources[$id])) {
            return false;
        }

        $existing = $this->store->sources[$id];
        $this->store->sources[$id] = [
            'id' => $id,
            'name' => $name,
            'nameNormalized' => TextNormalizer::normalize($name),
            'birthDate' => $optional['birthDate'] ?? null,
            'deathDate' => $optional['deathDate'] ?? null,
            'description' => $optional['description'] ?? null,
            'linkUrl' => $optional['linkUrl'] ?? null,
            // The unknown flag is intrinsic to the row and never flipped by an
            // ordinary edit; preserve it across updates.
            'isUnknown' => $existing['isUnknown'],
        ];

        return true;
    }

    public function delete(int $id): bool
    {
        if (!isset($this->store->sources[$id])) {
            return false;
        }

        unset($this->store->sources[$id]);

        return true;
    }

    /**
     * Project an internal row onto the public SourceRecord shape (drops the
     * internal `nameNormalized` helper).
     *
     * @param array{id: int, name: string, nameNormalized: string, birthDate: string|null, deathDate: string|null, description: string|null, linkUrl: string|null, isUnknown: bool} $row
     *
     * @return array{id: int, name: string, birthDate: string|null, deathDate: string|null, description: string|null, linkUrl: string|null, isUnknown: bool}
     */
    private static function toRecord(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'birthDate' => $row['birthDate'],
            'deathDate' => $row['deathDate'],
            'description' => $row['description'],
            'linkUrl' => $row['linkUrl'],
            'isUnknown' => $row['isUnknown'],
        ];
    }
}
