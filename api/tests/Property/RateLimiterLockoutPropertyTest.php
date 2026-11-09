<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\RateLimiter;
use Citations\Tests\Fake\InMemoryLoginAttemptRepository;
use Citations\Tests\Fake\InMemoryStore;
use DateInterval;
use DateTimeImmutable;
use Eris\Generator;

/**
 * Feature: citations-management, Property 18: Rate-Limit sperrt nach fünf
 * Fehlversuchen für 15 Minuten.
 *
 * For any sequence of failed login attempts for one username, the account is
 * locked EXACTLY WHEN a run of five failures fits inside a 15-minute window and
 * the check time lies in [fifth failure, fifth failure + 15min). The lock is
 * anchored to the fifth failure of the burst, so it holds from that instant up
 * to (but not including) fifth + 15 minutes, regardless of how the five
 * failures are spaced inside their window. Code under test:
 * {@see RateLimiter::isLocked()} over the in-memory attempt store
 * (design.md "Property 18").
 *
 * Validates: Requirements 6.5
 */
final class RateLimiterLockoutPropertyTest extends PropertyTestCase
{
    /** One minute in seconds, for translating minute offsets into instants. */
    private const SECONDS_PER_MINUTE = 60;

    /** Window / lock length in seconds (15 minutes, mirrors the policy). */
    private const WINDOW_SECONDS = RateLimiter::WINDOW_MINUTES * self::SECONDS_PER_MINUTE;

    /**
     * Arbitrary base instants spread across a wide epoch range so the property
     * never depends on a particular absolute time. Range stays well inside the
     * 32-bit-safe band and keeps counterexamples human-readable.
     */
    private function baseTimeGenerator(): Generator
    {
        // 2000-01-01 .. ~2065 in epoch seconds.
        return Generator\choose(946_684_800, 3_000_000_000);
    }

    /**
     * Build a RateLimiter backed by a fresh in-memory store and record one
     * failed attempt for $username at each of the given instants.
     *
     * @param list<DateTimeImmutable> $failureTimes
     */
    private function limiterWithFailures(string $username, array $failureTimes): RateLimiter
    {
        $limiter = new RateLimiter(new InMemoryLoginAttemptRepository(new InMemoryStore()));
        foreach ($failureTimes as $at) {
            $limiter->record($username, $at, false);
        }

        return $limiter;
    }

    private function at(int $epochSeconds): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $epochSeconds);
    }

    /**
     * (a) + (b): Five failures packed into a 15-minute window lock the account
     * from the fifth failure up to (but not including) fifth + 15 minutes, and
     * the lock is released at/after that instant.
     *
     * The five failures are placed at the base time plus four non-negative
     * gaps whose sum never exceeds the window, so the burst always fits. The
     * check time is the fifth failure plus a signed offset straddling the
     * fifth+15min boundary tightly and widely.
     */
    public function testBurstLocksFromFifthFailureForFifteenMinutes(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->baseTimeGenerator(),
            // four inter-failure gaps in seconds; their sum is clamped to the
            // window below so the five failures always share one 15-min span.
            Generator\choose(0, self::WINDOW_SECONDS),
            Generator\choose(0, self::WINDOW_SECONDS),
            Generator\choose(0, self::WINDOW_SECONDS),
            Generator\choose(0, self::WINDOW_SECONDS),
            // check-time offset from the fifth failure, in seconds: negative =
            // before fifth (never locked yet), [0, window) = locked,
            // >= window = released. Range straddles the fifth+15min boundary.
            Generator\choose(-120, self::WINDOW_SECONDS + 120)
        )->then(function (
            int $base,
            int $g1,
            int $g2,
            int $g3,
            int $g4,
            int $checkOffset
        ): void {
            // Scale the four raw gaps so their total never exceeds the window:
            // the five failures then always fit inside one 15-minute span.
            $rawTotal = $g1 + $g2 + $g3 + $g4;
            if ($rawTotal > self::WINDOW_SECONDS) {
                $g1 = intdiv($g1 * self::WINDOW_SECONDS, $rawTotal);
                $g2 = intdiv($g2 * self::WINDOW_SECONDS, $rawTotal);
                $g3 = intdiv($g3 * self::WINDOW_SECONDS, $rawTotal);
                $g4 = intdiv($g4 * self::WINDOW_SECONDS, $rawTotal);
            }

            $t1 = $base;
            $t2 = $t1 + $g1;
            $t3 = $t2 + $g2;
            $t4 = $t3 + $g3;
            $t5 = $t4 + $g4; // fifth failure anchors the lock

            self::assertLessThanOrEqual(
                $t1 + self::WINDOW_SECONDS,
                $t5,
                'Test setup invariant: the five failures must share one window.'
            );

            $failures = array_map(fn (int $s): DateTimeImmutable => $this->at($s), [$t1, $t2, $t3, $t4, $t5]);
            $limiter = $this->limiterWithFailures('alice', $failures);

            $checkTime = $this->at($t5 + $checkOffset);
            $locked = $limiter->isLocked('alice', $checkTime);

            if ($checkOffset < 0) {
                // Before the fifth failure, that fifth failure is in the future
                // and cannot yet start a lock (and no earlier run of five
                // exists), so the account is not locked.
                self::assertFalse(
                    $locked,
                    sprintf('Not locked before the fifth failure (offset %d s).', $checkOffset)
                );
            } elseif ($checkOffset < self::WINDOW_SECONDS) {
                self::assertTrue(
                    $locked,
                    sprintf(
                        'Must be locked within 15 min of the fifth failure (offset %d s, base %d).',
                        $checkOffset,
                        $base
                    )
                );
            } else {
                self::assertFalse(
                    $locked,
                    sprintf(
                        'Lock must be released at/after fifth + 15 min (offset %d s, base %d).',
                        $checkOffset,
                        $base
                    )
                );
            }
        });
    }

    /**
     * Boundary focus for (a)/(b): at exactly fifth + 15 minutes the lock is
     * released, and one second earlier it is still active, for any packed
     * burst.
     */
    public function testLockBoundaryIsExactlyFifthPlusFifteenMinutes(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->baseTimeGenerator(),
            // single uniform gap between failures, kept small enough that five
            // failures (4 gaps) always fit inside the 15-minute window.
            Generator\choose(0, intdiv(self::WINDOW_SECONDS, 4))
        )->then(function (int $base, int $gap): void {
            $times = [];
            for ($i = 0; $i < RateLimiter::MAX_FAILED_ATTEMPTS; $i++) {
                $times[] = $this->at($base + $i * $gap);
            }
            $fifth = $base + (RateLimiter::MAX_FAILED_ATTEMPTS - 1) * $gap;

            $limiter = $this->limiterWithFailures('bob', $times);

            self::assertTrue(
                $limiter->isLocked('bob', $this->at($fifth)),
                'Locked at the exact instant of the fifth failure.'
            );
            self::assertTrue(
                $limiter->isLocked('bob', $this->at($fifth + self::WINDOW_SECONDS - 1)),
                'Still locked one second before fifth + 15 min.'
            );
            self::assertFalse(
                $limiter->isLocked('bob', $this->at($fifth + self::WINDOW_SECONDS)),
                'Released exactly at fifth + 15 min.'
            );
        });
    }

    /**
     * (c): Fewer than five failures never lock the account, at any check time,
     * no matter how the failures are spaced.
     */
    public function testFewerThanFiveFailuresNeverLock(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->baseTimeGenerator(),
            // 0..4 failures only; stays strictly below the threshold.
            Generator\choose(0, RateLimiter::MAX_FAILED_ATTEMPTS - 1),
            // spacing between consecutive failures in seconds (any spread).
            Generator\choose(0, self::WINDOW_SECONDS),
            // check-time offset from the base time in seconds.
            Generator\choose(-self::WINDOW_SECONDS, self::WINDOW_SECONDS * 3)
        )->then(function (int $base, int $count, int $gap, int $checkOffset): void {
            $times = [];
            for ($i = 0; $i < $count; $i++) {
                $times[] = $this->at($base + $i * $gap);
            }
            $limiter = $this->limiterWithFailures('carol', $times);

            self::assertFalse(
                $limiter->isLocked('carol', $this->at($base + $checkOffset)),
                sprintf('%d failure(s) must never lock the account.', $count)
            );
        });
    }

    /**
     * (d): Five failures spread so that no run of five fits inside a 15-minute
     * window do not constitute a locking burst — the account is never locked,
     * at any check time. The failures are placed strictly more than 15 minutes
     * apart cumulatively (each gap at least a quarter-window plus one second,
     * so first..fifth spans strictly over 15 minutes), so the burst condition
     * (fifth <= first + 15min) is always violated.
     */
    public function testFiveFailuresBeyondWindowDoNotLock(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->baseTimeGenerator(),
            // four gaps, each large enough that the total strictly exceeds the
            // window: minimum gap = quarter-window + 1s guarantees
            // sum > 15 min, so no five-failure run ever fits one window.
            Generator\choose(0, self::WINDOW_SECONDS),
            Generator\choose(0, self::WINDOW_SECONDS),
            Generator\choose(0, self::WINDOW_SECONDS),
            Generator\choose(0, self::WINDOW_SECONDS),
            // check-time offset from the LAST (fifth) failure, in seconds.
            Generator\choose(-self::WINDOW_SECONDS, self::WINDOW_SECONDS * 2)
        )->then(function (
            int $base,
            int $e1,
            int $e2,
            int $e3,
            int $e4,
            int $checkOffset
        ): void {
            // Floor every gap to at least quarter-window + 1 second. Four such
            // gaps sum to at least window + 4 seconds > window, so the span from
            // first to fifth failure strictly exceeds 15 minutes and the burst
            // can never fit a single window.
            $minGap = intdiv(self::WINDOW_SECONDS, 4) + 1;
            $g1 = max($minGap, $e1);
            $g2 = max($minGap, $e2);
            $g3 = max($minGap, $e3);
            $g4 = max($minGap, $e4);

            $t1 = $base;
            $t2 = $t1 + $g1;
            $t3 = $t2 + $g2;
            $t4 = $t3 + $g3;
            $t5 = $t4 + $g4;

            self::assertGreaterThan(
                $t1 + self::WINDOW_SECONDS,
                $t5,
                'Test setup invariant: the five failures must NOT fit one window.'
            );

            $failures = array_map(fn (int $s): DateTimeImmutable => $this->at($s), [$t1, $t2, $t3, $t4, $t5]);
            $limiter = $this->limiterWithFailures('dave', $failures);

            self::assertFalse(
                $limiter->isLocked('dave', $this->at($t5 + $checkOffset)),
                sprintf(
                    'Five failures spread beyond a 15-min window must not lock (base %d, check offset %d).',
                    $base,
                    $checkOffset
                )
            );
        });
    }
}
