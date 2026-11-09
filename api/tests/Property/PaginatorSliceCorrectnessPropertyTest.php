<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\Paginator;
use Eris\Generator;

/**
 * Feature: citations-management, Property 4: Pagination schneidet die sortierte
 * Gesamtliste korrekt.
 *
 * For every already-sorted total result list and every requested page number
 * and page size -- including the "no value requested" cases (null -> defaults
 * page 1 / pageSize 20), out-of-range page sizes (clamped into [1, 100]) and
 * page numbers below 1 (clamped to 1) -- {@see Paginator::paginate()} must
 * return exactly the window
 *   items[(effectivePage-1)*effectivePageSize .. effectivePage*effectivePageSize)
 * of the input list, that window must hold at most effectivePageSize elements,
 * the reported total must equal count($items), and the reported page/pageSize
 * must equal the normalized (clamped/defaulted) values. The expected window is
 * computed independently via array_slice on the raw input (Requirement 1.6).
 *
 * Validates: Requirements 1.6
 */
final class PaginatorSliceCorrectnessPropertyTest extends PropertyTestCase
{
    public function testPageEqualsExpectedSliceOfSortedTotalList(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // Arbitrary sorted total list of ints, length 0..60 to exercise
            // multiple pages against the default/clamped page sizes.
            Generator\seq(Generator\choose(-1000, 1000)),
            // Requested page number: null, below 1, and in-range values.
            Generator\oneOf(
                Generator\constant(null),
                Generator\choose(-5, 20)
            ),
            // Requested page size: null, below 1, in-range, and above 100.
            Generator\oneOf(
                Generator\constant(null),
                Generator\choose(-10, 150)
            )
        )->then(function (array $items, ?int $page, ?int $pageSize): void {
            // Re-index to a clean 0-based list (the generator may not already be).
            $items = array_values($items);

            $result = Paginator::paginate($items, $page, $pageSize);

            // Independently recompute the normalized parameters that the
            // contract (1.6) prescribes.
            $expectedPageSize = $pageSize === null
                ? 20
                : max(1, min(100, $pageSize));
            $expectedPage = $page === null ? 1 : max(1, $page);

            self::assertSame(
                $expectedPageSize,
                $result['pageSize'],
                sprintf(
                    'Reported pageSize %d does not match normalized value %d (requested %s).',
                    $result['pageSize'],
                    $expectedPageSize,
                    var_export($pageSize, true)
                )
            );
            self::assertSame(
                $expectedPage,
                $result['page'],
                sprintf(
                    'Reported page %d does not match normalized value %d (requested %s).',
                    $result['page'],
                    $expectedPage,
                    var_export($page, true)
                )
            );

            // total always equals the length of the complete input list.
            self::assertSame(
                count($items),
                $result['total'],
                'Reported total does not equal the length of the input list.'
            );

            // Expected window: slice [(page-1)*pageSize .. page*pageSize),
            // computed independently from the raw input.
            $offset = ($expectedPage - 1) * $expectedPageSize;
            $expectedItems = array_values(
                array_slice($items, $offset, $expectedPageSize)
            );

            self::assertSame(
                $expectedItems,
                $result['items'],
                sprintf(
                    'Returned window does not equal array_slice(items, %d, %d).',
                    $offset,
                    $expectedPageSize
                )
            );

            // The window holds at most pageSize elements.
            self::assertLessThanOrEqual(
                $expectedPageSize,
                count($result['items']),
                sprintf(
                    'Returned window holds %d elements, exceeding pageSize %d.',
                    count($result['items']),
                    $expectedPageSize
                )
            );
        });
    }
}
