<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ResultSorter;
use Eris\Generator;

/**
 * Feature: citations-management, Property 3: Ergebnisse sind absteigend nach
 * Relevanzwert sortiert.
 *
 * For every bag of (quote id, relevance value) pairs, the id sequence produced
 * by {@see ResultSorter::sortIds()} must be ordered so that the relevance value
 * at every position is greater than or equal to the relevance value at the next
 * position (non-increasing / descending). In other words, for all adjacent
 * positions i and i+1 the relevance at i is >= the relevance at i+1
 * (Requirements 1.5, 2.1; design.md Property 3).
 *
 * The test also asserts the output is a PERMUTATION of the input ids: sorting
 * only reorders results, it never drops or invents an id. Together these two
 * invariants pin down the ordering contract that the pagination and rendering
 * layers rely on.
 *
 * Generation: arbitrary maps are built from a set of UNIQUE integer ids (the
 * database primary keys) each paired with an arbitrary relevance value. The
 * relevance pool is deliberately small relative to the id count and mixes int
 * and float values so that TIES occur frequently, exercising the branch where
 * the descending-by-relevance invariant must still hold across equal values.
 *
 * Validates: Requirements 1.5, 2.1
 */
final class ResultSorterDescendingOrderPropertyTest extends PropertyTestCase
{
    /**
     * Property 3: sortIds() returns the ids in non-increasing relevance order
     * and reorders (never loses or adds) exactly the input ids.
     */
    public function testResultsAreOrderedDescendingByRelevance(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // A sequence of raw id seeds; deduplicated below into unique ids.
            Generator\seq(Generator\choose(-1000, 1000)),
            // A matching pool of relevance seeds drawn independently so that
            // distinct ids frequently share the same relevance value (ties).
            Generator\seq(Generator\choose(0, 20))
        )->then(function (array $idSeeds, array $relevanceSeeds): void {
            $relevanceById = $this->buildMap($idSeeds, $relevanceSeeds);

            $ordered = ResultSorter::sortIds($relevanceById);

            // Invariant A: the output is a permutation of the input ids.
            $expectedIds = array_keys($relevanceById);
            sort($expectedIds);
            $actualIds = $ordered;
            sort($actualIds);
            self::assertSame(
                $expectedIds,
                $actualIds,
                sprintf(
                    'Output is not a permutation of the input ids. in=%s out=%s',
                    implode(',', $expectedIds),
                    implode(',', $ordered)
                )
            );

            // Invariant B: relevance is non-increasing across adjacent
            // positions (descending by relevance value).
            for ($i = 0, $n = count($ordered); $i + 1 < $n; $i++) {
                $currentId = $ordered[$i];
                $nextId = $ordered[$i + 1];
                self::assertGreaterThanOrEqual(
                    $relevanceById[$nextId],
                    $relevanceById[$currentId],
                    sprintf(
                        'Relevance increased between adjacent positions %d and %d: '
                        . 'id %d (rel %s) sorted before id %d (rel %s). Full order: %s.',
                        $i,
                        $i + 1,
                        $currentId,
                        var_export($relevanceById[$currentId], true),
                        $nextId,
                        var_export($relevanceById[$nextId], true),
                        implode(',', $ordered)
                    )
                );
            }
        });
    }

    /**
     * Turn two arbitrary seed sequences into an id => relevance map with UNIQUE
     * integer ids. Ids are deduplicated (array keys are unique by nature) and
     * each surviving id is paired with a relevance value taken from the
     * relevance pool; the pool is reused cyclically (and defaults to 0 when
     * empty) so the map size is driven entirely by the number of distinct ids.
     *
     * Relevance values alternate between int and float representations of the
     * same magnitude so that equal-valued ties and the int|float input shape
     * are both exercised.
     *
     * @param list<int> $idSeeds
     * @param list<int> $relevanceSeeds
     *
     * @return array<int, float|int>
     */
    private function buildMap(array $idSeeds, array $relevanceSeeds): array
    {
        $relevanceById = [];
        $poolSize = count($relevanceSeeds);
        $position = 0;

        foreach (array_values(array_unique($idSeeds)) as $id) {
            if ($poolSize === 0) {
                $relevanceById[$id] = 0;
                continue;
            }

            $raw = $relevanceSeeds[$position % $poolSize];
            // Mix int and float values that share magnitudes to create ties
            // across both representations.
            $relevanceById[$id] = ($position % 2 === 0) ? $raw : $raw + 0.0;
            $position++;
        }

        return $relevanceById;
    }
}
