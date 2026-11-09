<?php

declare(strict_types=1);

namespace Citations\Repository;

/**
 * Persistence boundary for sources / authors (Autoren-API, Requirements 5, 10,
 * 11, 13). Storage-agnostic so the PDO and in-memory implementations are
 * interchangeable.
 *
 * Optional fields (`birthDate`, `deathDate`, `description`, `linkUrl`) are
 * stored and returned as null when unset (Requirements 5.2). Callers pass
 * already-trimmed, length-valid values; validation lives in the mutation layer
 * (task 8), not here.
 *
 * Source records are returned as plain associative arrays with the public
 * shape used by the Autoren-API single view (design "Autoren-API"):
 *   array{
 *     id: int,
 *     name: string,
 *     birthDate: string|null,
 *     deathDate: string|null,
 *     description: string|null,
 *     linkUrl: string|null,
 *     isUnknown: bool
 *   }
 *
 * The management list view ({@see self::listAll()}, Autoren-API
 * `GET /api/sources`, Requirement 19) returns a trimmed per-row shape carrying
 * only the id, name and the `isUnknown` flag (19.2):
 *   array{id: int, name: string, isUnknown: bool}
 *
 * @phpstan-type SourceRecord array{id: int, name: string, birthDate: string|null, deathDate: string|null, description: string|null, linkUrl: string|null, isUnknown: bool}
 * @phpstan-type SourceListItem array{id: int, name: string, isUnknown: bool}
 */
interface SourceRepositoryInterface
{
    /**
     * Read a single source, or null when none exists with the id
     * (Requirements 5.1, 5.4).
     *
     * @return SourceRecord|null
     */
    public function findById(int $id): ?array;

    /**
     * The always-present, non-deletable unknown source (Requirement 13.1).
     * Used to assign quotes without a chosen source (13.3) and as the reassign
     * target on source deletion (11.3).
     *
     * @return SourceRecord
     */
    public function findUnknown(): array;

    /**
     * Whether any source carries the given normalized name. Enables the
     * reserved-name check for "unbekannt" (Requirement 13.2); the caller passes
     * the already normalized (lowercased) name to compare case-insensitively.
     */
    public function existsByNormalizedName(string $normalizedName): bool;

    /**
     * One page of all sources for the management list view, in a stable,
     * deterministic ascending-id ordering, alongside the total count of all
     * sources (Autoren-API `GET /api/sources`, Requirement 19).
     *
     * Each item carries the trimmed SourceListItem shape — id, name and the
     * `isUnknown` flag (19.2). The ascending-id ordering makes the paging
     * repeatable for a given stock (19.3). Because the Unbekannt-Quelle always
     * exists (13.1), the total is at least 1 and the first page always holds at
     * least that source (19.5).
     *
     * `$page` and `$pageSize` are 1-based; the implementation reads the page at
     * `offset = ($page - 1) * $pageSize`. The caller (handler) clamps `$page`
     * to `>= 1` and `$pageSize` to `[1, 100]`; a defensively negative offset is
     * floored to 0. A page past the end yields empty `items` with the total
     * unchanged (19.4).
     *
     * @return array{items: list<SourceListItem>, total: int}
     */
    public function listAll(int $page, int $pageSize): array;

    /**
     * Insert a new source and return its generated id (Requirement 10.1).
     *
     * @param array{birthDate?: string|null, deathDate?: string|null, description?: string|null, linkUrl?: string|null} $optional
     */
    public function insert(string $name, array $optional = []): int;

    /**
     * Overwrite the fields of an existing source (Requirement 10.6). Returns
     * false when no source with the id exists, leaving storage unchanged.
     *
     * @param array{birthDate?: string|null, deathDate?: string|null, description?: string|null, linkUrl?: string|null} $optional
     */
    public function update(int $id, string $name, array $optional = []): bool;

    /**
     * Remove the source with the given id (Requirement 11.1). Returns false
     * when it does not exist. Enforcing the unknown-source invariant (11.5,
     * 13.1) and the has-quotes conflict (11.2) is the mutation layer's job.
     */
    public function delete(int $id): bool;
}
