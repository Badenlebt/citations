<?php

declare(strict_types=1);

namespace Citations\Repository;

use DateTimeImmutable;

/**
 * Persistence boundary for authentication sessions (Auth-API, Requirements 6,
 * 7). Storage-agnostic so the PDO and in-memory implementations are
 * interchangeable.
 *
 * A session is valid while its row exists and the check time is before
 * `expiresAt` (Requirements 6.4); logout deletes the row so the token grants no
 * further access (6.6). Expiry comparison itself is pure logic in the session
 * layer (task 6) — this interface only stores and retrieves the timestamps.
 *
 * Session records are returned as plain associative arrays:
 *   array{token: string, operatorId: int, createdAt: DateTimeImmutable, expiresAt: DateTimeImmutable}
 *
 * @phpstan-type SessionRecord array{token: string, operatorId: int, createdAt: DateTimeImmutable, expiresAt: DateTimeImmutable}
 */
interface SessionRepositoryInterface
{
    /**
     * Read the session for a token, or null when no row matches (token missing,
     * unknown, or already ended). Supports token classification per 6.11.
     *
     * @return SessionRecord|null
     */
    public function findByToken(string $token): ?array;

    /**
     * Persist a new session for the given operator with its creation and expiry
     * timestamps (Requirements 6.1, 6.4). `$expiresAt` is derived by the caller
     * from the configured duration.
     */
    public function insert(string $token, int $operatorId, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): void;

    /**
     * Delete the session for the token (logout, Requirement 6.6). Returns false
     * when no such session exists. After deletion the token is unassignable and
     * grants no access.
     */
    public function delete(string $token): bool;
}
