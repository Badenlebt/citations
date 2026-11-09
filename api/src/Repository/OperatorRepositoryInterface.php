<?php

declare(strict_types=1);

namespace Citations\Repository;

/**
 * Persistence boundary for operator accounts (Auth-API, Requirements 6, 17).
 * Storage-agnostic so the PDO and in-memory implementations are
 * interchangeable.
 *
 * Only the password hash is ever stored or returned; the plaintext password is
 * never persisted (Requirements 6.7, 17.4). Multiple accounts are supported,
 * initially one (6.8).
 *
 * Operator records are returned as plain associative arrays:
 *   array{id: int, username: string, passwordHash: string}
 *
 * @phpstan-type OperatorRecord array{id: int, username: string, passwordHash: string}
 */
interface OperatorRepositoryInterface
{
    /**
     * Read an operator by username, or null when none exists. The login flow
     * still runs a dummy hash comparison on null to stay timing-balanced
     * (Requirements 6.2, 6.10) — that is the caller's concern, not this
     * interface's.
     *
     * @return OperatorRecord|null
     */
    public function findByUsername(string $username): ?array;

    /**
     * Whether at least one operator account exists. Drives the idempotent
     * initial-account seeding (Requirements 17.2, 17.3).
     */
    public function hasAny(): bool;

    /**
     * Insert an operator with a precomputed password hash and return its
     * generated id (Requirements 17.2). The caller passes the hash produced via
     * password_hash(); no plaintext crosses this boundary (6.7, 17.4).
     */
    public function insert(string $username, string $passwordHash): int;
}
