<?php

declare(strict_types=1);

namespace Citations\Repository;

use PDO;

/**
 * PDO/MariaDB implementation of {@see OperatorRepositoryInterface} (Auth-API,
 * Requirements 6, 17; design PDO data layer, Requirement 16.2).
 *
 * All access uses prepared statements with bound parameters (16.2). Only the
 * password hash is ever stored or returned; the plaintext password never
 * crosses this boundary (6.7, 17.4). {@see self::hasAny()} drives the
 * idempotent initial-account seeding (17.2, 17.3) and {@see self::insert()}
 * persists a precomputed hash supplied by the caller.
 */
final class PdoOperatorRepository implements OperatorRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash FROM operator WHERE username = :username',
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'passwordHash' => (string) $row['password_hash'],
        ];
    }

    public function hasAny(): bool
    {
        $stmt = $this->pdo->query('SELECT 1 FROM operator LIMIT 1');

        return $stmt->fetchColumn() !== false;
    }

    public function insert(string $username, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO operator (username, password_hash) VALUES (:username, :hash)',
        );
        $stmt->execute([
            ':username' => $username,
            ':hash' => $passwordHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
