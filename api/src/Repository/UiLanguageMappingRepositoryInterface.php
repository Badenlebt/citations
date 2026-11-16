<?php

declare(strict_types=1);

namespace Citations\Repository;

/**
 * Persistence boundary for the 1:1 mapping from UI language ('de'|'en') to a
 * quote language (random-quote-homepage, Requirements 9.1-9.4, 9.7).
 *
 * At most one entry per UI language (9.1) and each quote language is mapped
 * to at most one UI language (9.2); the database enforces both via PRIMARY KEY
 * and UNIQUE constraints.
 */
interface UiLanguageMappingRepositoryInterface
{
    /**
     * All existing mappings (Requirement 9.3).
     *
     * @return array<string, int> 'de'|'en' => languageId, only existing entries
     */
    public function findAll(): array;

    /**
     * The UI language a quote language is mapped to, or null when unmapped.
     */
    public function findUiLanguageByLanguageId(int $languageId): ?string;

    /**
     * Assign (or replace, 9.4) the mapping for a UI language.
     *
     * @return bool false when the quote language is already mapped to another
     *              UI language (uniqueness violation, e.g. a race); the stock
     *              stays unchanged
     */
    public function assign(string $uiLanguage, int $languageId): bool;

    /**
     * Remove the mapping of a UI language (9.7). Idempotent: removing a
     * non-existing mapping has no effect.
     */
    public function remove(string $uiLanguage): void;
}
