<?php

declare(strict_types=1);

namespace Citations\Repository;

use DateTimeImmutable;
use PDO;

/**
 * PDO/MariaDB implementation of {@see SessionRepositoryInterface} (Auth-API,
 * Requirements 6, 7; design PDO data layer, Requirement 16.2).
 *
 * All access uses prepared statements with bound parameters (16.2). A session
 * exists while its row is present; {@see self::findByToken()} returns null once
 * the row is missing/unknown or after logout, supporting token classification
 * (6.11). {@see self::delete()} removes the row so the token grants no further
 * access (6.6). Expiry comparison itself is pure logic in the session layer;
 * this class only stores and retrieves the timestamps.
 *
 * The `created_at`/`expires_at` DATETIME columns round-trip through the fixed
 * {@see self::DATETIME_FORMAT} ('Y-m-d H:i:s'): timestamps are written with
 * {@see DateTimeImmutable::format()} and read back with
 * {@see DateTimeImmutable::createFromFormat()}, so a stored value re-reads as
 * an equal instant.
 */
final class PdoSessionRepository implements SessionRepositoryInterface
{
    /** Canonical DATETIME text format used for lossless round-tripping. */
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT token, operator_id, created_at, expires_at'
            . ' FROM session WHERE token = :token',
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'token' => (string) $row['token'],
            'operatorId' => (int) $row['operator_id'],
            'createdAt' => self::toDateTime((string) $row['created_at']),
            'expiresAt' => self::toDateTime((string) $row['expires_at']),
        ];
    }

    public function insert(string $token, int $operatorId, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO session (token, operator_id, created_at, expires_at)'
            . ' VALUES (:token, :operator_id, :created_at, :expires_at)',
        );
        $stmt->execute([
            ':token' => $token,
            ':operator_id' => $operatorId,
            ':created_at' => $createdAt->format(self::DATETIME_FORMAT),
            ':expires_at' => $expiresAt->format(self::DATETIME_FORMAT),
        ]);
    }

    public function delete(string $token): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM session WHERE token = :token');
        $stmt->execute([':token' => $token]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Parse a DATETIME string from the DB back into an immutable instant using
     * the same fixed format used on write, so reads mirror writes exactly.
     */
    private static function toDateTime(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $value);
        if ($parsed === false) {
            // Fall back to the lenient constructor for any driver-specific
            // formatting (e.g. a fractional-seconds suffix); the stored value
            // is always a valid DATETIME written by this class.
            return new DateTimeImmutable($value);
        }

        return $parsed;
    }
}
