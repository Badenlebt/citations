<?php

declare(strict_types=1);

namespace Citations\Repository;

use Closure;
use PDO;

/**
 * A {@see LanguageRepositoryInterface} that defers opening the database
 * connection until the first language access (design "PHP Front-Controller",
 * Requirements 15.7; mirrors {@see LazySessionRepository}).
 *
 * The Search-API handler (task 12.5) needs the existing language ids to apply
 * the language filter (1.10–1.12). Connecting eagerly in `index.php` would both
 * do needless I/O and let a config/DB failure escape the request's error
 * boundary at bootstrap time. This wrapper builds the real
 * {@see PdoLanguageRepository} on first use via the injected factory, invoked
 * from inside {@see \Citations\Http\FrontController::run()}'s try/catch, so any
 * config or connection failure maps to a generic 500 that leaks no credentials,
 * DSN, or SQL (15.7). The real repository is built at most once per request.
 */
final class LazyLanguageRepository implements LanguageRepositoryInterface
{
    private ?LanguageRepositoryInterface $delegate = null;

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

    public function exists(int $id): bool
    {
        return $this->delegate()->exists($id);
    }

    public function existsByNormalizedName(string $normalizedName): bool
    {
        return $this->delegate()->existsByNormalizedName($normalizedName);
    }

    public function findAll(): array
    {
        return $this->delegate()->findAll();
    }

    public function insert(string $name, string $normalizedName): int
    {
        return $this->delegate()->insert($name, $normalizedName);
    }

    /**
     * Resolve (once) the real PDO-backed repository, building the connection via
     * the injected factory on first access. Any failure propagates to the
     * front-controller error boundary (15.7).
     */
    private function delegate(): LanguageRepositoryInterface
    {
        if ($this->delegate === null) {
            $this->delegate = new PdoLanguageRepository(($this->pdoFactory)());
        }

        return $this->delegate;
    }
}
