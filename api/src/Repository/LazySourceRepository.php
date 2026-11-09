<?php

declare(strict_types=1);

namespace Citations\Repository;

use Closure;
use PDO;

/**
 * A {@see SourceRepositoryInterface} that defers opening the database
 * connection until the first source access (design "PHP Front-Controller",
 * Requirements 15.7; mirrors {@see LazyQuoteRepository} / {@see LazyLanguageRepository}).
 *
 * The Quote-API handler (task 12.6) composes a {@see \Citations\QuoteCreator}
 * which needs a source repository to resolve/validate the chosen source and to
 * fall back to the Unbekannt-Quelle (13.3). Connecting eagerly in `index.php`
 * would both do needless I/O and let a config/DB failure escape the request's
 * error boundary at bootstrap time. This wrapper builds the real
 * {@see PdoSourceRepository} on first use via the injected factory, invoked
 * from inside {@see \Citations\Http\FrontController::run()}'s try/catch, so any
 * config or connection failure maps to a generic 500 that leaks no credentials,
 * DSN, or SQL (15.7). The real repository is built at most once per request.
 */
final class LazySourceRepository implements SourceRepositoryInterface
{
    private ?SourceRepositoryInterface $delegate = null;

    /**
     * @param Closure(): PDO $pdoFactory Resolves the PDO connection on first
     *        use. Invoked lazily from inside the request's error boundary.
     */
    public function __construct(
        private readonly Closure $pdoFactory,
    ) {
    }

    public function findById(int $id): ?array
    {
        return $this->delegate()->findById($id);
    }

    public function findUnknown(): array
    {
        return $this->delegate()->findUnknown();
    }

    public function existsByNormalizedName(string $normalizedName): bool
    {
        return $this->delegate()->existsByNormalizedName($normalizedName);
    }

    public function listAll(int $page, int $pageSize): array
    {
        return $this->delegate()->listAll($page, $pageSize);
    }

    public function insert(string $name, array $optional = []): int
    {
        return $this->delegate()->insert($name, $optional);
    }

    public function update(int $id, string $name, array $optional = []): bool
    {
        return $this->delegate()->update($id, $name, $optional);
    }

    public function delete(int $id): bool
    {
        return $this->delegate()->delete($id);
    }

    /**
     * Resolve (once) the real PDO-backed repository, building the connection via
     * the injected factory on first access. Any failure propagates to the
     * front-controller error boundary (15.7).
     */
    private function delegate(): SourceRepositoryInterface
    {
        if ($this->delegate === null) {
            $this->delegate = new PdoSourceRepository(($this->pdoFactory)());
        }

        return $this->delegate;
    }
}
