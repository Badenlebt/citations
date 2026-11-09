<?php

declare(strict_types=1);

namespace Citations\Repository;

/**
 * Persistence boundary for quotes (Zitat-API, Requirements 8, 9, 13.3).
 *
 * The interface is deliberately storage-agnostic so the PDO/MariaDB
 * implementation (task 11.2) and the in-memory test double (task 2.3) are
 * interchangeable behind it. All text persisted here is expected to be the
 * already-trimmed value; the normalized form (`text_normalized`, design Data
 * Models) is derived by the implementation on write, not supplied by callers.
 *
 * Quote records are returned as plain associative arrays with the public shape
 * used by the Zitat-API single view (design "Zitat-API"):
 *   array{
 *     id: int,
 *     text: string,
 *     languageId: int,
 *     languageName: string,
 *     source: array{id: int, name: string, linkUrl: string|null}
 *   }
 *
 * The management list view ({@see self::listAll()}, Zitat-API `GET /api/quotes`,
 * Requirement 18) returns a trimmed per-row shape that drops the source
 * `linkUrl` the single view carries (design "Zitat-API"): only the id, text,
 * language (id + name) and source (id + name) per row (18.2).
 *   array{
 *     id: int,
 *     text: string,
 *     languageId: int,
 *     languageName: string,
 *     source: array{id: int, name: string}
 *   }
 *
 * @phpstan-type QuoteRecord array{id: int, text: string, languageId: int, languageName: string, source: array{id: int, name: string, linkUrl: string|null}}
 * @phpstan-type QuoteListItem array{id: int, text: string, languageId: int, languageName: string, source: array{id: int, name: string}}
 */
interface QuoteRepositoryInterface
{
    /**
     * Read a single quote joined with its language and source, or null when no
     * quote with the given id exists (Requirements 4.1, 4.5).
     *
     * @return QuoteRecord|null
     */
    public function findById(int $id): ?array;

    /**
     * Read a single quote by its zero-based position in a stable, deterministic
     * ordering of the whole stock, joined with its language and source
     * (Requirement 3.1; supports the random-quote selection, 3.2/3.3).
     *
     * The ordering is fixed (ascending id) so the same index always addresses
     * the same quote for a given stock; this is the counterpart that turns an
     * index chosen by {@see \Citations\RandomSelector::select()} into a concrete
     * record. Returns the same joined QuoteRecord shape as {@see self::findById()}.
     * An index outside `[0, count())` yields null, so an out-of-range (or
     * empty-stock) lookup never fabricates a record.
     *
     * @param int $index Zero-based position in the ascending-id ordering.
     *
     * @return QuoteRecord|null
     */
    public function findByIndex(int $index): ?array;

    /**
     * Read every quote joined with its language and source, in a stable,
     * deterministic ascending-id ordering, as the candidate set the search
     * scores and filters in the application layer (Requirements 1.1–1.5, 2.1,
     * design "normalisierter Vergleich in der Anwendungsschicht").
     *
     * The search endpoint ({@see \Citations\Http\SearchHandler}) matches,
     * scores, language-filters and paginates this candidate set purely in PHP;
     * the repository therefore returns the SAME joined QuoteRecord shape as
     * {@see self::findById()} so each hit can be shaped for the response
     * without a second lookup. A `sourceId` restriction (5.3) narrows the
     * candidate set to one source's quotes.
     *
     * @param int|null $sourceId When non-null, restrict the candidates to
     *                           quotes of that source (5.3); null returns the
     *                           whole stock.
     *
     * @return list<QuoteRecord> All matching candidate quotes in ascending-id
     *                           order; empty when the stock (or the restricted
     *                           source) holds no quotes.
     */
    public function findAllForSearch(?int $sourceId = null): array;

    /**
     * Whether a quote with the given id exists (supports 9.5 not-found paths
     * without materializing the full record).
     */
    public function exists(int $id): bool;

    /**
     * Total number of stored quotes; 0 enables the NO_QUOTES path (3.3) and the
     * empty-result total (1.7).
     */
    public function count(): int;

    /**
     * One page of the whole quote stock for the management list view, joined
     * with language and source, in a stable, deterministic ascending-id
     * ordering, alongside the total count of all quotes (Zitat-API
     * `GET /api/quotes`, Requirement 18).
     *
     * Each item carries the trimmed QuoteListItem shape — id, text, language
     * (id + name) and source (id + name), without the single view's source
     * `linkUrl` (18.2). The ascending-id ordering makes the paging repeatable
     * for a given stock (18.3).
     *
     * `$page` and `$pageSize` are 1-based; the implementation reads the page at
     * `offset = ($page - 1) * $pageSize`. The caller (handler) clamps `$page`
     * to `>= 1` and `$pageSize` to `[1, 100]`; a defensively negative offset is
     * floored to 0. A page past the end yields empty `items` with the total
     * unchanged (18.4); an empty stock yields empty `items` with total 0 (18.5).
     *
     * @return array{items: list<QuoteListItem>, total: int}
     */
    public function listAll(int $page, int $pageSize): array;

    /**
     * Insert a new quote and return its generated id (Requirements 8.1, 13.3).
     * The caller guarantees `$text` is the trimmed, length-valid text and that
     * `$sourceId`/`$languageId` reference existing rows.
     */
    public function insert(string $text, int $sourceId, int $languageId): int;

    /**
     * Overwrite the mutable fields of an existing quote (Requirement 9.1).
     * Returns false when no quote with the id exists, leaving storage unchanged
     * (Requirement 9.5).
     */
    public function update(int $id, string $text, int $sourceId, int $languageId): bool;

    /**
     * Remove the quote with the given id (Requirement 9.4). Returns false when
     * it does not exist, leaving all other quotes untouched (9.5).
     */
    public function delete(int $id): bool;

    /**
     * Number of quotes currently assigned to the given source. Drives the
     * source-delete conflict decision (Requirements 11.1 vs 11.2).
     */
    public function countBySource(int $sourceId): int;

    /**
     * Reassign every quote of `$fromSourceId` to `$toSourceId` and return how
     * many rows were moved. Used by the reassign strategy before a source is
     * deleted (Requirement 11.3).
     */
    public function reassignSource(int $fromSourceId, int $toSourceId): int;
}
