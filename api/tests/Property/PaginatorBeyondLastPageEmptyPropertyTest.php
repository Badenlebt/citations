<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\Paginator;
use Eris\Generator;

/**
 * Feature: citations-management, Property 5: Seiten jenseits der letzten Seite
 * sind leer bei unveränderter Gesamtanzahl.
 *
 * For every total result list and every requested page number that lies
 * strictly beyond the last existing result page --
 *   lastPage = max(1, ceil(total / effectivePageSize))
 * -- {@see Paginator::paginate()} must return an EMPTY item list while the
 * reported total still equals count($items). The reported page/pageSize must
 * be the normalized (clamped/defaulted) values. This includes the empty-list
 * case, where lastPage is 1 and any page >= 2 must yield an empty window.
 *
 * The last page and the "beyond" page number are computed independently from
 * the implementation, directly from the contract in design.md (Property 5).
 *
 * Validates: Requirements 1.8
 */
final class PaginatorBeyondLastPageEmptyPropertyTest extends PropertyTestCase
{
    public function testPageBeyondLastPageIsEmptyWithUnchangedTotal(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // Arbitrary total list, length 0..60 (incl. the empty-list case).
            Generator\seq(Generator\choose(-1000, 1000)),
            // Requested page size: null (-> 20), below 1, in-range, above 100.
            Generator\oneOf(
                Generator\constant(null),
                Generator\choose(-10, 150)
            ),
            // How far beyond the last page to jump (>= 1 guarantees "strictly
            // greater than the last page").
            Generator\choose(0, 100)
        )->then(function (array $items, ?int $pageSize, int $beyond): void {
            // Re-index to a clean 0-based list.
            $items = array_values($items);

            // Normalized page size, recomputed independently (1.6).
            $expectedPageSize = $pageSize === null
                ? 20
                : max(1, min(100, $pageSize));

            $total = count($items);

            // Last existing result page, computed independently (Property 5).
            // At least 1 even for an empty total list.
            $lastPage = max(1, (int) ceil($total / $expectedPageSize));

            // A page number strictly greater than the last page.
            $requestedPage = $lastPage + 1 + $beyond;

            $result = Paginator::paginate($items, $requestedPage, $expectedPageSize);

            // The returned window must be empty.
            self::assertSame(
                [],
                $result['items'],
                sprintf(
                    'Page %d lies beyond the last page %d (total %d, pageSize %d) '
                    . 'but the returned window was not empty.',
                    $requestedPage,
                    $lastPage,
                    $total,
                    $expectedPageSize
                )
            );

            // The total is unchanged and equals the length of the input list.
            self::assertSame(
                $total,
                $result['total'],
                'Reported total does not equal the unchanged length of the input list.'
            );

            // The reported parameters are the normalized values.
            self::assertSame(
                $expectedPageSize,
                $result['pageSize'],
                'Reported pageSize does not match the normalized value.'
            );
            self::assertSame(
                max(1, $requestedPage),
                $result['page'],
                'Reported page does not match the normalized requested page.'
            );
        });
    }
}
