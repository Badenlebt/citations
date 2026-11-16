<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Repository\UiLanguageMappingRepositoryInterface;

/**
 * Interface-identical in-memory {@see UiLanguageMappingRepositoryInterface}
 * double. Models the schema constraints: one entry per UI language (keyed
 * array, replace on assign) and a quote language mapped to at most one UI
 * language (assign returns false, state unchanged, like the UNIQUE key).
 */
final class InMemoryUiLanguageMappingRepository implements UiLanguageMappingRepositoryInterface
{
    public function __construct(private InMemoryStore $store)
    {
    }

    public function findAll(): array
    {
        $result = $this->store->uiLanguageMappings;
        ksort($result);

        return $result;
    }

    public function findUiLanguageByLanguageId(int $languageId): ?string
    {
        foreach ($this->store->uiLanguageMappings as $uiLanguage => $mapped) {
            if ($mapped === $languageId) {
                return (string) $uiLanguage;
            }
        }

        return null;
    }

    public function assign(string $uiLanguage, int $languageId): bool
    {
        foreach ($this->store->uiLanguageMappings as $existingUi => $mapped) {
            if ($mapped === $languageId && (string) $existingUi !== $uiLanguage) {
                return false;
            }
        }

        $this->store->uiLanguageMappings[$uiLanguage] = $languageId;

        return true;
    }

    public function remove(string $uiLanguage): void
    {
        unset($this->store->uiLanguageMappings[$uiLanguage]);
    }
}
