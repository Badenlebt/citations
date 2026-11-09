<?php

declare(strict_types=1);

namespace Citations\Repository;

use DateTimeImmutable;
use PDO;

/**
 * PDO/MariaDB implementation of {@see LoginAttemptRepositoryInterface} feeding
 * the rate limiter (Auth-API, Requirement 6.5; design PDO data layer,
 * Requirement 16.2).
 *
 * All access uses prepared statements with bound parameters (16.2). Attempts
 * are stored with their timestamp and success flag; the window queries filter
 * to failures for one username whose `attempted_at` is at or after `$since`
 * (inclusive lower bound, matching the ">= since" contract).
 * {@see self::failedAttemptsSince()} returns those timestamps oldest-first so
 * the limiter can locate the fifth failure of a burst, mirroring the in-memory
 * double. The 5-failures-per-15-minutes policy itself is pure logic in the
 * auth layer, not here.
 *
 * DATETIME values round-trip through the fixed {@see self::DATETIME_FORMAT}
 * ('Y-m-d H:i:s'), so a written timestamp re-reads as an equal instant.
 */
final class PdoLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    /** Canonical DATETIME text format used for lossless round-tripping. */
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $username, DateTimeImmutable $attemptedAt, bool $success): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempt (username, attempted_at, success)'
            . ' VALUES (:username, :attempted_at, :success)',
        );
        $stmt->execute([
            ':username' => $username,
            ':attempted_at' => $attemptedAt->format(self::DATETIME_FORMAT),
            ':success' => $success ? 1 : 0,
        ]);
    }

    public function countFailedSince(string $username, DateTimeImmutable $since): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempt'
            . ' WHERE username = :username AND success = 0'
            . ' AND attempted_at >= :since',
        );
        $stmt->execute([
            ':username' => $username,
            ':since' => $since->format(self::DATETIME_FORMAT),
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function failedAttemptsSince(string $username, DateTimeImmutable $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT attempted_at FROM login_attempt'
            . ' WHERE username = :username AND success = 0'
            . ' AND attempted_at >= :since'
            . ' ORDER BY attempted_at ASC, id ASC',
        );
        $stmt->execute([
            ':username' => $username,
            ':since' => $since->format(self::DATETIME_FORMAT),
        ]);

        $result = [];
        foreach ($stmt as $row) {
            $result[] = self::toDateTime((string) $row['attempted_at']);
        }

        return $result;
    }

    /**
     * Parse a DATETIME string from the DB using the same fixed format used on
     * write, so reads mirror writes exactly.
     */
    private static function toDateTime(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $value);
        if ($parsed === false) {
            return new DateTimeImmutable($value);
        }

        return $parsed;
    }
}
