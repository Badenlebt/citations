<?php

declare(strict_types=1);

namespace Citations\Repository;

use Closure;
use PDO;

/**
 * A {@see QuoteRepositoryInterface} that defers opening the database connection
 * until the first quote access (design "PHP Front-Controller",
 * Requirements 15.7; mirrors {@see LazySessionRepository}).
 *
 * The Search-API handler (task 12.5) needs a quote repository for its read
 * endpoints. Connecting eagerly in `index.php` would both do needless I/O and,
 * worse, let a config/DB failure escape the request's error boundary at
 * bootstrap time. This wrapper holds a factory closure that builds the real
 * {@see PdoQuoteRepository} (resolving config + PDO) on first use, invoked from
 * within {@see \Citations\Http\FrontController::run()}'s try/catch, so any
 * {@see \Citations\ConfigException} or connection failure maps to a generic 500
 * and leaks no credentials, DSN, or SQL (15.7). The real repository is built at
 * most once per request.
 */
final class LazyQuoteRepository implements QuoteRepositoryInterface
{
    private ?QuoteRepositoryInterface $delegate = null;

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

    public function findByIndex(int $index): ?array
    {
        return $this->delegate()->findByIndex($index);
    }

    public function findAllForSearch(?int $sourceId = null): array
    {
        return $this->delegate()->findAllForSearch($sourceId);
    }

    public function exists(int $id): bool
    {
        return $this->delegate()->exists($id);
    }

    public function count(): int
    {
        return $this->delegate()->count();
    }

    public function listAll(int $page, int $pageSize): array
    {
        return $this->delegate()->listAll($page, $pageSize);
    }

    public function insert(string $text, int $sourceId, int $languageId): int
    {
        return $this->delegate()->insert($text, $sourceId, $languageId);
    }

    public function update(int $id, string $text, int $sourceId, int $languageId): bool
    {
        return $this->delegate()->update($id, $text, $sourceId, $languageId);
    }

    public function delete(int $id): bool
    {
        return $this->delegate()->delete($id);
    }

    public function countBySource(int $sourceId): int
    {
        return $this->delegate()->countBySource($sourceId);
    }

    public function reassignSource(int $fromSourceId, int $toSourceId): int
    {
        return $this->delegate()->reassignSource($fromSourceId, $toSourceId);
    }

    /**
     * Resolve (once) the real PDO-backed repository, building the connection via
     * the injected factory on first access. Any failure propagates to the
     * front-controller error boundary (15.7).
     */
    private function delegate(): QuoteRepositoryInterface
    {
        if ($this->delegate === null) {
            $this->delegate = new PdoQuoteRepository(($this->pdoFactory)());
        }

        return $this->delegate;
    }
}
