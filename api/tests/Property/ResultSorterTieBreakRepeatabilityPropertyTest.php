<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ResultSorter;
use Eris\Generator;

/**
 * Feature: citations-management, Property 13: Wiederholbare, eindeutige
 * Reihenfolge bei Relevanzgleichstand.
 *
 * Requirement 2.7 demands that whenever two or more quotes share the SAME
 * relevance value, the search returns them in an order that is both UNIQUE and
 * REPEATABLE for the same data and search term. {@see ResultSorter::sortIds()}
 * realizes this by breaking ties on the unique quote id, ascending. This test
 * pins down the two halves of that guarantee:
 *
 *   (a) UNIQUE tie-break — among any run of results that all carry the same
 *       relevance value, the quote ids are strictly ascending. Because ids are
 *       unique, this leaves no freedom in the ordering of tied results.
 *
 *   (b) REPEATABLE — the order depends only on the data, never on how the
 *       id => relevance map happened to be assembled. The same logical data,
 *       presented with its keys in a DIFFERENT insertion order, must yield the
 *       identical id sequence. This guards against any reliance on PHP's sort
 *       stability or on incidental input ordering.
 *
 * Generation deliberately draws relevance values from a TINY pool relative to
 * the number of ids, so most results collide on relevance and the tie-break
 * branch is exercised on long runs of equal values. Between the two sorts the
 * map's key insertion order is reversed (and, when seeds allow, otherwise
 * permuted) to prove the output is insertion-order independent.
 *
 * Validates: Requirements 2.7
 */
final class ResultSorterTieBreakRepeatabilityPropertyTest extends PropertyTestCase
{
    /**
     * Property 13: tied results are ordered by ascending unique id, and the
     * order is reproducible across different input insertion orders.
     */
    public function testTiesAreBrokenByAscendingIdAndOrderIsRepeatable(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // Raw id seeds; deduplicated into unique quote ids below.
            Generator\seq(Generator\choose(-500, 500)),
            // A deliberately small relevance pool so distinct ids collide on
            // the same relevance value very often (many ties).
            Generator\seq(Generator\choose(0, 3))
        )->then(function (array $idSeeds, array $relevanceSeeds): void {
            $relevanceById = $this->buildMap($idSeeds, $relevanceSeeds);

            $ordered = ResultSorter::sortIds($relevanceById);

            // Invariant A: within every maximal run of equal relevance values,
            // quote ids are STRICTLY ascending (unique, deterministic tie-break).
            for ($i = 0, $n = count($ordered); $i + 1 < $n; $i++) {
                $currentId = $ordered[$i];
                $nextId = $ordered[$i + 1];
                if ($relevanceById[$currentId] == $relevanceById[$nextId]) {
                    self::assertLessThan(
                        $nextId,
                        $currentId,
                        sprintf(
                            'Tie not broken by ascending id at positions %d/%d: '
                            . 'id %d and id %d share relevance %s. Full order: %s.',
                            $i,
                            $i + 1,
                            $currentId,
                            $nextId,
                            var_export($relevanceById[$currentId], true),
                            implode(',', $ordered)
                        )
                    );
                }
            }

            // Invariant B: repeatability / insertion-order independence. The
            // same logical data, with its keys inserted in a different order,
            // must produce the identical id sequence.
            $reordered = $this->reinsertInDifferentOrder($relevanceById);
            $orderedAgain = ResultSorter::sortIds($reordered);

            self::assertSame(
                $ordered,
                $orderedAgain,
                sprintf(
                    'Order is not repeatable across input insertion orders. '
                    . 'first=%s second=%s',
                    implode(',', $ordered),
                    implode(',', $orderedAgain)
                )
            );
        });
    }

    /**
     * Build an id => relevance map with UNIQUE integer ids drawn from the id
     * seeds (array keys are unique by nature) and relevance values taken
     * cyclically from the small relevance pool. Int and float representations
     * of the same magnitude are mixed so ties span both shapes; the pool
     * defaults to 0 when no relevance seeds are provided.
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
            $relevanceById[$id] = ($position % 2 === 0) ? $raw : $raw + 0.0;
            $position++;
        }

        return $relevanceById;
    }

    /**
     * Return a map with the SAME id => relevance entries but a different key
     * insertion order (reversed). Reversing guarantees a distinct order for any
     * map of size >= 2, which is what the repeatability invariant must survive.
     *
     * @param array<int, float|int> $relevanceById
     *
     * @return array<int, float|int>
     */
    private function reinsertInDifferentOrder(array $relevanceById): array
    {
        $reordered = [];
        foreach (array_reverse(array_keys($relevanceById)) as $id) {
            $reordered[$id] = $relevanceById[$id];
        }

        return $reordered;
    }
}
