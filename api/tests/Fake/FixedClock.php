<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Http\Clock;
use DateTimeImmutable;

/**
 * Deterministic {@see Clock} test double that always returns the instant it was
 * constructed with, so the session guard's expiry decisions (6.4/7.2) are
 * exercised without any wall-clock dependency or flakiness.
 */
final class FixedClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
