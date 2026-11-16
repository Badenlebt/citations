<?php

declare(strict_types=1);

namespace Citations\Repository;

use Closure;
use PDO;

/**
 * A {@see UiLanguageMappingRepositoryInterface} that defers opening the
 * database connection until first access (mirrors {@see LazyLanguageRepository}).
 * Connection/config failures surface inside the front controller's error
 * boundary (Requirement 15.7).
 */
final class LazyUiLanguageMappingRepository implements UiLanguageMappingRepositoryInterface
{
    private ?UiLanguageMappingRepositoryInterface $delegate = null;

    /**
     * @param Closure(): PDO $pdoFactory Resolves the PDO connection on first use.
     */
    public function __construct(
        private readonly Closure $pdoFactory,
    ) {
    }

    public function findAll(): array
    {
        return $this->delegate()->findAll();
    }

    public function findUiLanguageByLanguageId(int $languageId): ?string
    {
        return $this->delegate()->findUiLanguageByLanguageId($languageId);
    }

    public function assign(string $uiLanguage, int $languageId): bool
    {
        return $this->delegate()->assign($uiLanguage, $languageId);
    }

    public function remove(string $uiLanguage): void
    {
        $this->delegate()->remove($uiLanguage);
    }

    private function delegate(): UiLanguageMappingRepositoryInterface
    {
        if ($this->delegate === null) {
            $this->delegate = new PdoUiLanguageMappingRepository(($this->pdoFactory)());
        }

        return $this->delegate;
    }
}
