<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure fallback-chain logic for choosing quote languages by UI language.
 */
final class UiLanguageResolver
{
    /**
     * @param array<string, int> $mapping 'de'|'en' => quote language id; only existing entries
     * @return list<int> Candidate language ids in fallback order, without
     *                   duplicates. Empty => no language preference (all quotes).
     */
    public static function candidateLanguageIds(?UiLanguage $ui, array $mapping): array
    {
        if ($ui === null) {
            return [];
        }

        $candidates = [];
        foreach ([$ui->value, UiLanguage::EN->value] as $key) {
            $id = $mapping[$key] ?? null;
            if ($id !== null && !in_array($id, $candidates, true)) {
                $candidates[] = $id;
            }
        }

        return $candidates;
    }
}
