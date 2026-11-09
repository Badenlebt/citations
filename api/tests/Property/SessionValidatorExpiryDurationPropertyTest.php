<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\SessionValidator;
use DateInterval;
use DateTimeImmutable;
use Eris\Generator;

/**
 * Feature: citations-management, Property 17: Session-Gültigkeit folgt der
 * konfigurierten Ablaufdauer.
 *
 * For every creation time, every configured duration (clamped into [1, 168]
 * hours, default 24 when null) and every check time, a session is valid
 * EXACTLY WHEN the check time lies strictly before (creation time + effective
 * duration). Code under test: {@see SessionValidator::clampDurationHours()},
 * {@see SessionValidator::expiresAt()} and {@see SessionValidator::isValid()}
 * (design.md "Property 17").
 *
 * Validates: Requirements 6.1, 6.4
 */
final class SessionValidatorExpiryDurationPropertyTest extends PropertyTestCase
{
    /** One hour in seconds, used to translate hour offsets into instants. */
    private const SECONDS_PER_HOUR = 3600;

    /**
     * Arbitrary creation instants spread across a wide range of epoch seconds,
     * so the property does not depend on any particular absolute time.
     */
    private function createdAtGenerator(): Generator
    {
        // 2000-01-01 .. ~2065 in epoch seconds; any instant works, this keeps
        // counterexamples human-readable.
        return Generator\map(
            static fn (int $epoch): DateTimeImmutable =>
                (new DateTimeImmutable('@' . $epoch)),
            Generator\choose(946_684_800, 3_000_000_000)
        );
    }

    /**
     * Arbitrary configured durations, deliberately spanning below the minimum,
     * inside the range and above the maximum, plus null for the default. Eris
     * generators cannot yield null directly here, so null is modelled by the
     * sentinel below the smallest meaningful hour value and mapped afterwards.
     */
    private function configuredHoursGenerator(): Generator
    {
        // -2000 .. 2000 covers far-below-1, the whole [1,168] band and
        // far-above-168; the sentinel -10000 stands in for "null".
        return Generator\oneOf(
            Generator\choose(-2000, 2000),
            Generator\constant(-10000)
        );
    }

    /**
     * Map the generated sentinel/int to the real argument: -10000 -> null.
     *
     * @return int|null
     */
    private function toConfiguredHours(int $raw): ?int
    {
        return $raw === -10000 ? null : $raw;
    }

    /**
     * clampDurationHours clamps every configured value into [1, 168] and maps
     * null to the 24-hour default (Requirement 6.4).
     */
    public function testDurationIsClampedIntoRange(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->configuredHoursGenerator()
        )->then(function (int $raw): void {
            $configured = $this->toConfiguredHours($raw);

            $clamped = SessionValidator::clampDurationHours($configured);

            self::assertGreaterThanOrEqual(
                SessionValidator::MIN_DURATION_HOURS,
                $clamped,
                sprintf('Clamped duration %d below minimum for input %s.', $clamped, var_export($configured, true))
            );
            self::assertLessThanOrEqual(
                SessionValidator::MAX_DURATION_HOURS,
                $clamped,
                sprintf('Clamped duration %d above maximum for input %s.', $clamped, var_export($configured, true))
            );

            if ($configured === null) {
                self::assertSame(
                    SessionValidator::DEFAULT_DURATION_HOURS,
                    $clamped,
                    'Null configuration must fall back to the 24-hour default.'
                );
            } elseif ($configured < SessionValidator::MIN_DURATION_HOURS) {
                self::assertSame(SessionValidator::MIN_DURATION_HOURS, $clamped, 'Below-range value must clamp to the minimum.');
            } elseif ($configured > SessionValidator::MAX_DURATION_HOURS) {
                self::assertSame(SessionValidator::MAX_DURATION_HOURS, $clamped, 'Above-range value must clamp to the maximum.');
            } else {
                self::assertSame($configured, $clamped, 'In-range value must pass through unchanged.');
            }
        });
    }

    /**
     * Strictly before expiry the session is valid; at or after expiry it is
     * invalid. The check time is built as expiry + an arbitrary signed hour
     * offset so both sides of the boundary are exercised, with the exact-expiry
     * instant (offset 0) explicitly required to be invalid.
     */
    public function testValidityFollowsTheExpiryBoundary(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->createdAtGenerator(),
            $this->configuredHoursGenerator(),
            // Offset from the expiry instant, in seconds: negative = before
            // expiry (expect valid), zero/positive = at or after (expect
            // invalid). Range straddles the boundary tightly and widely.
            Generator\choose(-700_000, 700_000)
        )->then(function (DateTimeImmutable $createdAt, int $raw, int $offsetSeconds): void {
            $configured = $this->toConfiguredHours($raw);
            $clampedHours = SessionValidator::clampDurationHours($configured);
            $expiry = SessionValidator::expiresAt($createdAt, $configured);

            // Cross-check: expiry really is createdAt + clamped duration.
            $expectedExpiry = $createdAt->add(new DateInterval('PT' . $clampedHours . 'H'));
            self::assertEquals(
                $expectedExpiry,
                $expiry,
                'expiresAt must equal createdAt plus the clamped duration.'
            );

            $checkTime = $expiry->modify(
                ($offsetSeconds >= 0 ? '+' : '-') . abs($offsetSeconds) . ' seconds'
            );

            $isValid = SessionValidator::isValid($createdAt, $configured, $checkTime);

            if ($offsetSeconds < 0) {
                self::assertTrue(
                    $isValid,
                    sprintf(
                        'Session must be valid %d s before expiry (created %s, %dh).',
                        -$offsetSeconds,
                        $createdAt->format('c'),
                        $clampedHours
                    )
                );
            } else {
                self::assertFalse(
                    $isValid,
                    sprintf(
                        'Session must be invalid at/after expiry (offset %d s, created %s, %dh).',
                        $offsetSeconds,
                        $createdAt->format('c'),
                        $clampedHours
                    )
                );
            }
        });
    }

    /**
     * Boundary focus: exactly at the expiry instant the session is invalid,
     * and one second before it is valid, for every clamped duration.
     */
    public function testExactExpiryInstantIsInvalid(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->createdAtGenerator(),
            $this->configuredHoursGenerator()
        )->then(function (DateTimeImmutable $createdAt, int $raw): void {
            $configured = $this->toConfiguredHours($raw);
            $expiry = SessionValidator::expiresAt($createdAt, $configured);

            self::assertFalse(
                SessionValidator::isValid($createdAt, $configured, $expiry),
                'A session is never valid at its own expiry instant.'
            );
            self::assertTrue(
                SessionValidator::isValid(
                    $createdAt,
                    $configured,
                    $expiry->modify('-1 second')
                ),
                'A session is valid one second before expiry.'
            );
        });
    }
}
