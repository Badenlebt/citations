<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure, side-effect-free pagination of an already-sorted total result list
 * (Requirements 1.6, 1.7, 1.8; design "Pagination-Berechnung",
 * Correctness Properties 4 and 5).
 *
 * This logic operates on the fully sorted total match list produced upstream
 * (relevance-descending with a stable tie-break over quote id). It only cuts
 * the requested window out of that list and reports the total count; it never
 * reorders, filters, or performs I/O.
 *
 * Parameter handling (1.6):
 *   - pageSize is constrained to the inclusive range [1, 100]; the default 20
 *     is applied when no page size is requested (null). Out-of-range values are
 *     clamped into [1, 100].
 *   - page defaults to 1 when none is requested (null); values below 1 are
 *     clamped to 1.
 *
 * Contract:
 *   - Property 4: the returned items equal the slice of the sorted total list
 *     from index (page-1)*pageSize up to (but excluding) page*pageSize, hold at
 *     most pageSize elements, and the reported total equals the length of the
 *     total list (1.6).
 *   - Property 5 / 1.8: a page beyond the last page (page > ceil(total/pageSize))
 *     yields an empty item list while total stays unchanged.
 *   - 1.7: an empty total list yields an empty item list with total 0 for any
 *     page/pageSize (a special case of the slicing above).
 *
 * Determinism: the output depends only on ($items, $page, $pageSize). No
 * globals, no clock, no I/O.
 */
final class Paginator
{
    /** Default page size applied when none is requested (1.6). */
    public const DEFAULT_PAGE_SIZE = 20;

    /** Lower/upper bounds for the requestable page size (1.6). */
    public const MIN_PAGE_SIZE = 1;
    public const MAX_PAGE_SIZE = 100;

    /** Default page number applied when none is requested (1.6). */
    public const DEFAULT_PAGE = 1;

    /**
     * Cut the requested page out of the sorted total list.
     *
     * @param list<mixed> $items    The fully sorted total result list. Passed by
     *                              value; the input array is not mutated.
     * @param int|null    $page     Requested 1-based page number, or null for the
     *                              default (1). Values below 1 are clamped to 1.
     * @param int|null    $pageSize Requested page size, or null for the default
     *                              (20). Values are clamped into [1, 100].
     *
     * @return array{items: list<mixed>, total: int, page: int, pageSize: int}
     *         The page window, the unchanged total count, and the effective
     *         (normalized) page and pageSize actually applied.
     */
    public static function paginate(array $items, ?int $page = null, ?int $pageSize = null): array
    {
        $effectivePageSize = self::normalizePageSize($pageSize);
        $effectivePage = self::normalizePage($page);

        // The total is always the length of the complete list and never changes
        // with the requested window (1.6, 1.7, 1.8).
        $total = \count($items);

        $offset = ($effectivePage - 1) * $effectivePageSize;

        // array_slice yields an empty array when the offset is at or beyond the
        // end of the list, which covers both the empty-result case (1.7) and the
        // page-beyond-last-page case (1.8) without any special branching. Using
        // array_values re-indexes the window to a clean 0-based list.
        $pageItems = \array_values(\array_slice($items, $offset, $effectivePageSize));

        return [
            'items' => $pageItems,
            'total' => $total,
            'page' => $effectivePage,
            'pageSize' => $effectivePageSize,
        ];
    }

    /**
     * Apply the default and clamp the requested page size into [1, 100] (1.6).
     */
    public static function normalizePageSize(?int $pageSize): int
    {
        if ($pageSize === null) {
            return self::DEFAULT_PAGE_SIZE;
        }

        if ($pageSize < self::MIN_PAGE_SIZE) {
            return self::MIN_PAGE_SIZE;
        }

        if ($pageSize > self::MAX_PAGE_SIZE) {
            return self::MAX_PAGE_SIZE;
        }

        return $pageSize;
    }

    /**
     * Apply the default and clamp the requested page number to at least 1 (1.6).
     */
    public static function normalizePage(?int $page): int
    {
        if ($page === null) {
            return self::DEFAULT_PAGE;
        }

        if ($page < 1) {
            return 1;
        }

        return $page;
    }
}
