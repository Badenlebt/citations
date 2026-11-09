<?php

declare(strict_types=1);

namespace Citations\Http;

use DateTimeImmutable;

/**
 * Production {@see Clock} that returns the real wall-clock instant
 * (`new DateTimeImmutable('now')`). Wired into the request pipeline in
 * `api/index.php`; tests substitute a fixed clock instead.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
