<?php

declare(strict_types=1);

namespace Citations\Repository;

use DateTimeImmutable;
use PDO;

/**
 * A {@see SessionRepositoryInterface} that defers opening the database
 * connection until the first session access (design "PHP Front-Controller",
 * Requirements 7.1, 15.7).
 *
 * The front controller wires the {@see \Citations\Http\SessionGuard} with a
 * {@see \Citations\TokenAuthenticator}, which needs a session repository, which
 * needs a PDO connection. But the guard only touches the session store for
 * WRITE routes (7.1) — every read and auth route is forwarded without any
 * session lookup (7.3). Connecting eagerly in `index.php` would therefore both
 * do needless I/O for reads AND, worse, let a config/DB failure escape the
 * error boundary at bootstrap time, surfacing as an uncaught error instead of a
 * generic 500.
 *
 * This wrapper closes that gap: it holds a factory closure that builds the real
 * {@see PdoSessionRepository} (resolving config + PDO) on demand. The closure is
 * invoked from within the guarded dispatch, i.e. inside
 * {@see \Citations\Http\FrontController::run()}'s try/catch, so any
 * {@see \Citations\ConfigException} or connection failure maps to a generic 500
 * via the existing {@see \Citations\Http\ErrorMapper} and leaks no credentials,
 * DSN, or SQL (15.7). The real repository is built at most once per request.
 */
final class LazySessionRepository implements SessionRepositoryInterface
{
    private ?SessionRepositoryInterface $delegate = null;

    /**
     * @param (callable(): PDO) $pdoFactory Resolves the PDO connection on first
     *        use. Invoked lazily from inside the request's error boundary.
     */
    public function __construct(
        private readonly \Closure $pdoFactory,
    ) {
    }

    public function findByToken(string $token): ?array
    {
        return $this->delegate()->findByToken($token);
    }

    public function insert(string $token, int $operatorId, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): void
    {
        $this->delegate()->insert($token, $operatorId, $createdAt, $expiresAt);
    }

    public function delete(string $token): bool
    {
        return $this->delegate()->delete($token);
    }

    /**
     * Resolve (once) the real PDO-backed repository, building the connection via
     * the injected factory on first access. Any failure propagates to the
     * front-controller error boundary (15.7).
     */
    private function delegate(): SessionRepositoryInterface
    {
        if ($this->delegate === null) {
            $this->delegate = new PdoSessionRepository(($this->pdoFactory)());
        }

        return $this->delegate;
    }
}
