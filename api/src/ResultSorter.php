<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure, side-effect-free deterministic ordering of scored search results
 * (Requirements 1.5, 2.1, 2.7; design "Such-API" and Correctness Properties 3
 * and 13).
 *
 * The search produces, per quote, EXACTLY ONE relevance value via
 * {@see RelevanceScorer::score()} (2.1). This class turns a bag of
 * (quote id, relevance value) pairs into the final result order:
 *
 *   - PRIMARY   descending by relevance value (1.5, 2.1, Property 3): a result
 *               with a higher relevance value is placed before one with a lower
 *               value.
 *   - TIE-BREAK ascending by quote id (2.7, Property 13): results that share
 *               the SAME relevance value are ordered by their quote id, smallest
 *               first.
 *
 * Because quote ids are unique, the (relevance desc, id asc) comparator is a
 * TOTAL order: no two distinct results ever compare equal. The resulting order
 * is therefore fully determined by the data alone — running the same search
 * twice over the same data yields the identical sequence (Property 13,
 * "wiederholbar und eindeutig"). The comparator never falls back on input order,
 * so it does not rely on PHP's sort stability and is reproducible regardless of
 * how the unsorted results happened to be collected.
 *
 * Determinism: the output depends only on the arguments. No I/O, no globals.
 */
final class ResultSorter
{
    /**
     * Order scored results descending by relevance, ties broken ascending by
     * quote id, and return the ordered list of quote ids.
     *
     * The input maps each quote id to its single relevance value. Quote ids are
     * integers (the database primary key) and are assumed unique within one
     * search, which is what makes the order total and reproducible (2.7).
     *
     * @param array<int, float|int> $relevanceById Relevance value keyed by the
     *                                             unique quote id. An empty map
     *                                             yields an empty list.
     *
     * @return list<int> The quote ids in final result order.
     */
    public static function sortIds(array $relevanceById): array
    {
        $ids = array_keys($relevanceById);

        usort($ids, static function (int $a, int $b) use ($relevanceById): int {
            // PRIMARY: descending by relevance value (1.5, 2.1, Property 3).
            $byRelevance = $relevanceById[$b] <=> $relevanceById[$a];
            if ($byRelevance !== 0) {
                return $byRelevance;
            }

            // TIE-BREAK: ascending by unique quote id (2.7, Property 13).
            // Since ids are unique this branch fully resolves every tie, so the
            // comparator is a strict total order and the result is reproducible.
            return $a <=> $b;
        });

        return $ids;
    }

    /**
     * Compare two scored results for the final result order.
     *
     * Returns a negative number when result $a sorts before $b, a positive
     * number when it sorts after, and 0 only when both the relevance value AND
     * the quote id are equal (i.e. the same result). Exposed so callers that
     * already hold result objects can reuse the exact same total order without
     * rebuilding an id-to-relevance map.
     *
     * @param float|int $relevanceA Relevance value of the first result.
     * @param int       $idA        Unique quote id of the first result.
     * @param float|int $relevanceB Relevance value of the second result.
     * @param int       $idB        Unique quote id of the second result.
     */
    public static function compare(float|int $relevanceA, int $idA, float|int $relevanceB, int $idB): int
    {
        // PRIMARY: descending by relevance value (1.5, 2.1, Property 3).
        $byRelevance = $relevanceB <=> $relevanceA;
        if ($byRelevance !== 0) {
            return $byRelevance;
        }

        // TIE-BREAK: ascending by unique quote id (2.7, Property 13).
        return $idA <=> $idB;
    }
}
