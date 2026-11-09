<?php

declare(strict_types=1);

namespace Citations;

use Citations\Repository\LoginAttemptRepositoryInterface;
use DateInterval;
use DateTimeImmutable;

/**
 * Login rate-limiting logic for the Auth-API (Requirement 6.5, design
 * Correctness Property 18 "Rate-Limit sperrt nach fünf Fehlversuchen für 15
 * Minuten").
 *
 * Policy: once five failed login attempts for the same username fall inside a
 * 15-minute window, further login attempts for that username are rejected for
 * 15 minutes measured FROM THE FIFTH failed attempt — even if valid
 * credentials are supplied afterwards (6.5).
 *
 * The lock is anchored to the fifth failure of a burst, not to a plain trailing
 * count: a username is locked at the check time exactly when some run of five
 * failures lies within a 15-minute span AND the fifth failure of that run is no
 * more than 15 minutes before the check time. Anchoring to the fifth failure
 * matters when failures are spread unevenly — a trailing "how many failures in
 * the last 15 minutes" count would release the lock too early as the earliest
 * failures of the burst age out, before 15 minutes have elapsed since the fifth.
 *
 * The check time is injected and the attempt store is an interface, so the core
 * decision is deterministic and testable with an in-memory store and no real
 * clock. Window boundaries are inclusive of their start instant, matching
 * {@see LoginAttemptRepositoryInterface::failedAttemptsSince()}.
 */
final class RateLimiter
{
    /** Number of failed attempts within a window that triggers a lock (6.5). */
    public const MAX_FAILED_ATTEMPTS = 5;

    /** Length of both the burst window and the lock, in minutes (6.5). */
    public const WINDOW_MINUTES = 15;

    public function __construct(
        private readonly LoginAttemptRepositoryInterface $attempts
    ) {
    }

    /**
     * Earliest failure that can still influence the lock at a given check time.
     *
     * A fifth failure can keep the account locked up to 15 minutes after it,
     * and the burst's first failure can sit up to 15 minutes before that fifth
     * failure, so failures older than two windows (30 minutes) can never be
     * part of a burst that is still locking at $checkTime.
     */
    public static function historyStart(DateTimeImmutable $checkTime): DateTimeImmutable
    {
        $minutes = self::WINDOW_MINUTES * 2;

        return $checkTime->sub(new DateInterval('PT' . $minutes . 'M'));
    }

    /**
     * Whether login attempts for the username are currently locked out
     * (Requirement 6.5, Property 18).
     *
     * Returns true exactly when a run of five failed attempts for the username
     * fits inside a 15-minute span and the fifth failure of that run is at most
     * 15 minutes before $checkTime (and not in the future). While locked, a
     * login must be rejected before any credential verification, regardless of
     * whether the supplied credentials are valid.
     *
     * @param string            $username  The user identifier being checked.
     * @param DateTimeImmutable $checkTime The instant the attempt is evaluated.
     */
    public function isLocked(string $username, DateTimeImmutable $checkTime): bool
    {
        $failures = $this->attempts->failedAttemptsSince($username, self::historyStart($checkTime));

        $count = count($failures);
        if ($count < self::MAX_FAILED_ATTEMPTS) {
            return false;
        }

        $window = new DateInterval('PT' . self::WINDOW_MINUTES . 'M');

        // Inspect every run of five consecutive failures (the store returns
        // them oldest first). For such a run the fifth failure is the burst's
        // trigger: the account is locked when that fifth failure happened no
        // earlier than 15 minutes before the burst's first failure (the five
        // failures share one 15-minute window) and the lock it starts
        // (fifth + 15 minutes) still covers the check time.
        for ($i = self::MAX_FAILED_ATTEMPTS - 1; $i < $count; $i++) {
            $first = $failures[$i - (self::MAX_FAILED_ATTEMPTS - 1)];
            $fifth = $failures[$i];

            if ($fifth > $checkTime) {
                continue; // a failure in the future cannot start a lock yet
            }

            $burstFits = $fifth <= $first->add($window);
            $lockActive = $checkTime < $fifth->add($window);

            if ($burstFits && $lockActive) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record the outcome of one login attempt for the username at the given
     * time (Requirement 6.5). Failures recorded here feed the burst evaluated
     * by {@see self::isLocked()}; successes are stored too so the full attempt
     * history is available, but only failures count toward the lock.
     */
    public function record(string $username, DateTimeImmutable $attemptedAt, bool $success): void
    {
        $this->attempts->record($username, $attemptedAt, $success);
    }
}
