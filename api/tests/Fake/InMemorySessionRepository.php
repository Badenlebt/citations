<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Repository\SessionRepositoryInterface;
use DateTimeImmutable;

/**
 * Interface-identical in-memory {@see SessionRepositoryInterface} test double
 * (task 2.3). A session exists while its row is present;
 * {@see self::findByToken()} returns null once missing/unknown or after logout,
 * supporting token classification (6.11). {@see self::delete()} removes the row
 * so the token grants no further access (6.6). Expiry comparison itself is pure
 * logic in the session layer; this double only stores and retrieves timestamps.
 */
final class InMemorySessionRepository implements SessionRepositoryInterface
{
    public function __construct(private InMemoryStore $store)
    {
    }

    public function findByToken(string $token): ?array
    {
        return $this->store->sessions[$token] ?? null;
    }

    public function insert(string $token, int $operatorId, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): void
    {
        $this->store->sessions[$token] = [
            'token' => $token,
            'operatorId' => $operatorId,
            'createdAt' => $createdAt,
            'expiresAt' => $expiresAt,
        ];
    }

    public function delete(string $token): bool
    {
        if (!isset($this->store->sessions[$token])) {
            return false;
        }

        unset($this->store->sessions[$token]);

        return true;
    }
}
