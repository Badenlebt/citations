<?php

declare(strict_types=1);

namespace Citations\Repository;

/**
 * Persistence boundary for languages (Sprach-API, Requirement 12).
 * Storage-agnostic so the PDO and in-memory implementations are
 * interchangeable.
 *
 * The stock is dynamically extendable and not fixed to DE/EN (12.2).
 * Uniqueness is case-insensitive via the normalized name (12.5); the caller
 * supplies the already normalized (lowercased) form for lookups and the
 * implementation persists it alongside the display name (`name_normalized`,
 * design Data Models).
 *
 * Language records are returned as plain associative arrays:
 *   array{id: int, name: string}
 *
 * @phpstan-type LanguageRecord array{id: int, name: string}
 */
interface LanguageRepositoryInterface
{
    /**
     * Read a single language, or null when none exists with the id. Supports
     * the INVALID_LANGUAGE check when assigning a quote (Requirements 8.4, 9.3).
     *
     * @return LanguageRecord|null
     */
    public function findById(int $id): ?array;

    /**
     * Whether a language with the given id exists (reference check for quote
     * create/edit, Requirements 8.4, 9.3) without materializing the record.
     */
    public function exists(int $id): bool;

    /**
     * Whether a language with the given normalized name already exists. Backs
     * the case-insensitive duplicate check (Requirement 12.5); the caller
     * passes the already normalized name.
     */
    public function existsByNormalizedName(string $normalizedName): bool;

    /**
     * All languages, for assignment and the public filter list
     * (Requirements 12.7, 1.10). Order is stable and repeatable.
     *
     * @return list<LanguageRecord>
     */
    public function findAll(): array;

    /**
     * Insert a new language and return its generated id (Requirement 12.3).
     * The caller guarantees `$name` is trimmed and length-valid and that
     * `$normalizedName` is its normal form; the new language is afterwards part
     * of the available set.
     */
    public function insert(string $name, string $normalizedName): int;
}
