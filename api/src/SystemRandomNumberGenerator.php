<?php

declare(strict_types=1);

namespace Citations;

/**
 * Production {@see RandomNumberGenerator} backed by {@see random_int()}
 * (design "Zufallsauswahl mit injizierbarem RNG", Requirements 3.1, 3.2).
 *
 * It draws a uniform integer from the full range `[0, PHP_INT_MAX]` and
 * normalizes it into the half-open interval [0, 1) by dividing by the number of
 * distinct outcomes (`PHP_INT_MAX + 1`). Dividing by the count of outcomes
 * rather than by the maximum keeps the result strictly below 1.0 even for the
 * largest draw, so the value is always a valid injected randomness for
 * {@see RandomSelector::select()}. The actual index mapping stays in that pure
 * function; this class only supplies the entropy.
 */
final class SystemRandomNumberGenerator implements RandomNumberGenerator
{
    public function next(): float
    {
        // PHP_INT_MAX + 1 == number of distinct draws in [0, PHP_INT_MAX]; the
        // literal avoids the integer overflow of (PHP_INT_MAX + 1).
        $outcomes = 2.0 ** 63;

        return random_int(0, PHP_INT_MAX) / $outcomes;
    }
}
