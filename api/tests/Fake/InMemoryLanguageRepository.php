<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Repository\LanguageRepositoryInterface;

/**
 * Interface-identical in-memory {@see LanguageRepositoryInterface} test double
 * (task 2.3). Backs quote reference checks (INVALID_LANGUAGE, Requirements 8.4,
 * 9.3) and the case-insensitive duplicate check via the normalized name
 * (12.5). The caller supplies the already-normalized name on write and on the
 * duplicate lookup, matching the real contract.
 *
 * {@see self::findAll()} returns languages in ascending id order so iteration
 * is stable and repeatable (12.7, 1.10).
 */
final class InMemoryLanguageRepository implements LanguageRepositoryInterface
{
    public function __construct(private InMemoryStore $store)
    {
    }

    public function findById(int $id): ?array
    {
        $row = $this->store->languages[$id] ?? null;

        return $row === null ? null : ['id' => $row['id'], 'name' => $row['name']];
    }

    public function exists(int $id): bool
    {
        return isset($this->store->languages[$id]);
    }

    public function existsByNormalizedName(string $normalizedName): bool
    {
        foreach ($this->store->languages as $row) {
            if ($row['nameNormalized'] === $normalizedName) {
                return true;
            }
        }

        return false;
    }

    public function findAll(): array
    {
        $ids = array_keys($this->store->languages);
        sort($ids);

        $result = [];
        foreach ($ids as $id) {
            $row = $this->store->languages[$id];
            $result[] = ['id' => $row['id'], 'name' => $row['name']];
        }

        return $result;
    }

    public function insert(string $name, string $normalizedName): int
    {
        $id = $this->store->allocateLanguageId();
        $this->store->languages[$id] = [
            'id' => $id,
            'name' => $name,
            'nameNormalized' => $normalizedName,
        ];

        return $id;
    }
}
