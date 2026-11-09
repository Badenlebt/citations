<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure, side-effect-free random selection of an index into a stock of n quotes
 * (Requirements 3.2, 3.3; design "Zufallsauswahl mit injizierbarem RNG",
 * Correctness Property 14).
 *
 * The randomness is INJECTED as a value in the half-open interval [0, 1): the
 * caller (the I/O-bound `GET /api/random` endpoint) draws the raw entropy
 * (e.g. via `random_int()`) and hands this function a normalized draw, while
 * the actual index mapping stays a deterministic, testable pure function. The
 * same injected value always maps to the same index, which keeps the selection
 * reproducible under test and lets property tests cover the full input space.
 *
 * Contract (Property 14):
 *   - For every non-empty stock of size n and every injected value r in [0, 1),
 *     the returned index lies in [0, n).
 *   - Every index in [0, n) is in principle reachable: the mapping partitions
 *     [0, 1) into n equal-width buckets via floor(r * n), so for each target
 *     index there exists an r that selects exactly it (every quote is selectable,
 *     3.2). The mapping does not impose a fixed, data-independent order.
 *   - The empty stock (n = 0) yields no selection: this function returns null,
 *     and the endpoint translates that into the NO_QUOTES response (3.3). No I/O
 *     is performed here.
 *
 * Determinism: the output depends only on (n, r). No globals, no clock, no I/O.
 */
final class RandomSelector
{
    /**
     * Map an injected random value to a quote index.
     *
     * @param int   $count       Number of available quotes (n); must be >= 0.
     * @param float $randomValue Injected randomness in the half-open interval
     *                           [0, 1). Values at or above 1.0 are clamped to
     *                           the last valid index so the result never leaves
     *                           [0, n); negative values are clamped to 0.
     *
     * @return int|null The selected index in [0, n), or null when the stock is
     *                  empty (n = 0), signalling "no selection" (NO_QUOTES).
     *
     * @throws \InvalidArgumentException If $count is negative.
     */
    public static function select(int $count, float $randomValue): ?int
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('Quote count must not be negative.');
        }

        // Empty stock: no index exists. The endpoint maps null to NO_QUOTES (3.3).
        if ($count === 0) {
            return null;
        }

        // Clamp the injected value into [0, 1) so the mapping cannot leave the
        // valid index range even for out-of-contract draws (e.g. r == 1.0).
        if ($randomValue < 0.0) {
            $randomValue = 0.0;
        }

        $index = (int) \floor($randomValue * $count);

        // Guard against floating-point rounding landing on n (only possible for
        // r at or just below 1.0); the highest valid index is n - 1.
        if ($index >= $count) {
            $index = $count - 1;
        }

        return $index;
    }
}
