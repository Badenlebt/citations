<?php

declare(strict_types=1);

namespace Citations;

use DateInterval;
use DateTimeImmutable;

/**
 * Pure, side-effect-free session-expiry logic (Requirements 6.1, 6.4, design
 * Correctness Property 17 "Session-Gültigkeit folgt der konfigurierten
 * Ablaufdauer").
 *
 * A session is valid EXACTLY WHEN the check time lies strictly before its
 * expiry, where expiry is the creation time plus the configured duration
 * (6.1, 6.4). The duration is configurable between 1 and 168 hours with a
 * default of 24 hours; any configured value outside that range is clamped into
 * it (and a missing value falls back to the default) before the expiry is
 * derived.
 *
 * All timestamps and the duration are passed in as arguments, so the decision
 * is deterministic and testable without a clock, a database, or any globals.
 * The I/O boundary {@see \Citations\Repository\SessionRepositoryInterface}
 * stores and retrieves the timestamps; the comparison itself lives here.
 */
final class SessionValidator
{
    /** Smallest permitted session duration, in hours (Requirement 6.4). */
    public const MIN_DURATION_HOURS = 1;

    /** Largest permitted session duration, in hours (Requirement 6.4). */
    public const MAX_DURATION_HOURS = 168;

    /** Default session duration, in hours, when none is configured (6.4). */
    public const DEFAULT_DURATION_HOURS = 24;

    /**
     * Clamp a configured session duration (in hours) into the permitted
     * [1, 168] range, substituting the default when no value is configured
     * (Requirement 6.4).
     *
     * A null value yields the 24-hour default. A value below 1 is raised to 1
     * and a value above 168 is lowered to 168, so the effective duration is
     * always within the allowed bounds.
     *
     * @param int|null $configuredHours The configured duration, or null for the default.
     * @return int The effective duration in hours, within [1, 168].
     */
    public static function clampDurationHours(?int $configuredHours): int
    {
        if ($configuredHours === null) {
            return self::DEFAULT_DURATION_HOURS;
        }

        return max(
            self::MIN_DURATION_HOURS,
            min(self::MAX_DURATION_HOURS, $configuredHours)
        );
    }

    /**
     * Derive the expiry timestamp of a session from its creation time and the
     * configured duration (Requirements 6.1, 6.4).
     *
     * The duration is clamped into [1, 168] hours (default 24) via
     * {@see self::clampDurationHours()} before being added, so the expiry is
     * always between createdAt + 1h and createdAt + 168h.
     *
     * @param DateTimeImmutable $createdAt       When the session was created.
     * @param int|null          $configuredHours The configured duration in hours, or null for the default.
     * @return DateTimeImmutable createdAt plus the effective duration.
     */
    public static function expiresAt(DateTimeImmutable $createdAt, ?int $configuredHours): DateTimeImmutable
    {
        $hours = self::clampDurationHours($configuredHours);

        return $createdAt->add(new DateInterval('PT' . $hours . 'H'));
    }

    /**
     * Decide whether a session is still valid at a given check time
     * (Requirements 6.1, 6.4, Property 17).
     *
     * Returns true EXACTLY WHEN the check time is strictly before the derived
     * expiry (createdAt + clamped duration). At or after the expiry instant the
     * session is invalid, so a session is never valid at its own expiry moment.
     *
     * @param DateTimeImmutable $createdAt       When the session was created.
     * @param int|null          $configuredHours The configured duration in hours, or null for the default.
     * @param DateTimeImmutable $checkTime       The instant the validity is checked.
     * @return bool True if checkTime < createdAt + duration, false otherwise.
     */
    public static function isValid(
        DateTimeImmutable $createdAt,
        ?int $configuredHours,
        DateTimeImmutable $checkTime
    ): bool {
        return $checkTime < self::expiresAt($createdAt, $configuredHours);
    }
}
