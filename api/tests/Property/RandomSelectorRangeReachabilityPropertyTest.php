<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\RandomSelector;
use Eris\Generator;

/**
 * Feature: citations-management, Property 14: Zufallsauswahl bleibt im gültigen
 * Bereich und ist vollständig erreichbar.
 *
 * For every non-empty stock of size n and every injected random value r in
 * [0, 1), {@see RandomSelector::select()} must return an index inside [0, n)
 * (range), and every index in [0, n) must be reachable by SOME injected value
 * (completeness): the mapping partitions [0, 1) into n equal-width buckets via
 * floor(r * n), so each target index k is hit by the bucket midpoint
 * (k + 0.5) / n. Both halves together establish that every quote is in
 * principle selectable (Requirement 3.2).
 *
 * Validates: Requirements 3.2
 */
final class RandomSelectorRangeReachabilityPropertyTest extends PropertyTestCase
{
    /**
     * Range: for every count >= 1 and every injected value in [0, 1), the
     * returned index lies within the half-open interval [0, count).
     *
     * The injected value is reconstructed as num / den with 0 <= num < den so
     * it stays strictly below 1.0, covering the full half-open input space.
     */
    public function testReturnedIndexStaysWithinValidRange(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(1, 500),
            Generator\choose(0, 1_000_000),
            Generator\choose(1, 1_000_000)
        )->then(function (int $count, int $num, int $den): void {
            // Map (num, den) to a value in [0, 1): ensure numerator < denominator.
            $randomValue = ($num % $den) / $den;

            $index = RandomSelector::select($count, $randomValue);

            self::assertNotNull(
                $index,
                sprintf('Non-empty stock (n=%d) must yield an index.', $count)
            );
            self::assertGreaterThanOrEqual(
                0,
                $index,
                sprintf('Index %d for r=%.9f, n=%d is below 0.', $index, $randomValue, $count)
            );
            self::assertLessThan(
                $count,
                $index,
                sprintf('Index %d for r=%.9f, n=%d is outside [0, n).', $index, $randomValue, $count)
            );
        });
    }

    /**
     * Completeness / reachability: for every count >= 1, every target index in
     * [0, count) is reachable by some injected value. The bucket midpoint
     * (k + 0.5) / count lands strictly inside bucket k, so selecting across all
     * k yields exactly the full set {0, 1, ..., count - 1}.
     */
    public function testEveryIndexIsReachable(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(1, 500)
        )->then(function (int $count): void {
            $reached = [];
            for ($k = 0; $k < $count; $k++) {
                // Representative draw for bucket k: its midpoint, strictly in [0, 1).
                $representative = ($k + 0.5) / $count;

                $index = RandomSelector::select($count, $representative);

                self::assertSame(
                    $k,
                    $index,
                    sprintf(
                        'Midpoint r=%.9f of bucket %d (n=%d) selected %s instead of %d.',
                        $representative,
                        $k,
                        $count,
                        var_export($index, true),
                        $k
                    )
                );
                $reached[$index] = true;
            }

            ksort($reached);
            self::assertSame(
                range(0, $count - 1),
                array_keys($reached),
                sprintf('Reachable indices do not cover the full range [0, %d).', $count)
            );
        });
    }
}
