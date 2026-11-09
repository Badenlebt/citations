<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Repository\OperatorRepositoryInterface;

/**
 * Interface-identical in-memory {@see OperatorRepositoryInterface} test double
 * (task 2.3). Only the password hash is stored; no plaintext ever crosses this
 * boundary (Requirements 6.7, 17.4). {@see self::hasAny()} drives the
 * idempotent initial-account seeding (17.2, 17.3), and
 * {@see self::findByUsername()} supports the login lookup (6.2, 6.10).
 */
final class InMemoryOperatorRepository implements OperatorRepositoryInterface
{
    public function __construct(private InMemoryStore $store)
    {
    }

    public function findByUsername(string $username): ?array
    {
        foreach ($this->store->operators as $row) {
            if ($row['username'] === $username) {
                return [
                    'id' => $row['id'],
                    'username' => $row['username'],
                    'passwordHash' => $row['passwordHash'],
                ];
            }
        }

        return null;
    }

    public function hasAny(): bool
    {
        return $this->store->operators !== [];
    }

    public function insert(string $username, string $passwordHash): int
    {
        $id = $this->store->allocateOperatorId();
        $this->store->operators[$id] = [
            'id' => $id,
            'username' => $username,
            'passwordHash' => $passwordHash,
        ];

        return $id;
    }
}
