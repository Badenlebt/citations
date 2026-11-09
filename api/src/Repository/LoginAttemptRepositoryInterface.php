<?php

declare(strict_types=1);

namespace Citations\Repository;

use DateTimeImmutable;

/**
 * Persistence boundary for login attempts used by the rate limiter (Auth-API,
 * Requirement 6.5). Storage-agnostic so the PDO and in-memory implementations
 * are interchangeable.
 *
 * The repository only records attempts per username and exposes their failure
 * history inside a time window; the 5-failures-per-15-minutes policy and the
 * resulting 15-minute lock measured from the fifth failure are pure logic in
 * the auth layer (task 6.6, {@see \Citations\RateLimiter}), which queries this
 * interface for the relevant failed attempts.
 */
interface LoginAttemptRepositoryInterface
{
    /**
     * Record one login attempt for a username at a point in time, flagged as a
     * success or failure (Requirement 6.5). Feeds the rate-limit window.
     */
    public function record(string $username, DateTimeImmutable $attemptedAt, bool $success): void;

    /**
     * Count failed attempts for the username whose timestamp is at or after
     * `$since` (the start of the 15-minute window). The caller derives `$since`
     * from the check time and compares the result against the 5-failure
     * threshold (Requirement 6.5).
     */
    public function countFailedSince(string $username, DateTimeImmutable $since): int;

    /**
     * Return the timestamps of the username's failed attempts at or after
     * `$since`, in ascending chronological order (Requirement 6.5).
     *
     * The rate limiter uses this to locate the fifth failure of a burst and
     * lock the account for 15 minutes measured from that fifth failure — a
     * count alone cannot express "15 minutes from the fifth failed attempt"
     * when failures are spread unevenly across the window.
     *
     * @return list<DateTimeImmutable> Failure timestamps, oldest first.
     */
    public function failedAttemptsSince(string $username, DateTimeImmutable $since): array;
}
