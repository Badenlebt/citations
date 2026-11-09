<?php

declare(strict_types=1);

namespace Citations\Repository;

use Closure;
use PDO;

/**
 * An {@see OperatorRepositoryInterface} that defers opening the database
 * connection until the first operator access (design "PHP Front-Controller",
 * Requirements 15.7; mirrors {@see LazySessionRepository}).
 *
 * The Auth-API login handler (task 12.12) needs to look up the operator by
 * username. Connecting eagerly in `index.php` would do needless I/O for every
 * request and let a config/DB failure escape the request's error boundary at
 * bootstrap. This wrapper builds the real {@see PdoOperatorRepository} on first
 * use via the injected factory, invoked from inside
 * {@see \Citations\Http\FrontController::run()}'s try/catch, so any config or
 * connection failure maps to a generic 500 that leaks no credentials, DSN, or
 * SQL (15.7). The real repository is built at most once per request.
 */
final class LazyOperatorRepository implements OperatorRepositoryInterface
{
    private ?OperatorRepositoryInterface $delegate = null;

    /**
     * @param Closure(): PDO $pdoFactory Resolves the PDO connection on first
     *        use. Invoked lazily from inside the request's error boundary.
     */
    public function __construct(
        private readonly Closure $pdoFactory,
    ) {
    }

    public function findByUsername(string $username): ?array
    {
        return $this->delegate()->findByUsername($username);
    }

    public function hasAny(): bool
    {
        return $this->delegate()->hasAny();
    }

    public function insert(string $username, string $passwordHash): int
    {
        return $this->delegate()->insert($username, $passwordHash);
    }

    /**
     * Resolve (once) the real PDO-backed repository, building the connection via
     * the injected factory on first access. Any failure propagates to the
     * front-controller error boundary (15.7).
     */
    private function delegate(): OperatorRepositoryInterface
    {
        if ($this->delegate === null) {
            $this->delegate = new PdoOperatorRepository(($this->pdoFactory)());
        }

        return $this->delegate;
    }
}
