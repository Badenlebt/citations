<?php

declare(strict_types=1);

namespace Citations\Repository;

use Closure;
use DateTimeImmutable;
use PDO;

/**
 * A {@see LoginAttemptRepositoryInterface} that defers opening the database
 * connection until the first attempt access (design "PHP Front-Controller",
 * Requirements 15.7; mirrors {@see LazySessionRepository}).
 *
 * The Auth-API rate limiter (task 12.12) queries and records login attempts for
 * a username. Connecting eagerly in `index.php` would do needless I/O and let a
 * config/DB failure escape the request's error boundary at bootstrap. This
 * wrapper builds the real {@see PdoLoginAttemptRepository} on first use via the
 * injected factory, invoked from inside
 * {@see \Citations\Http\FrontController::run()}'s try/catch, so any config or
 * connection failure maps to a generic 500 that leaks no credentials, DSN, or
 * SQL (15.7). The real repository is built at most once per request.
 */
final class LazyLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    private ?LoginAttemptRepositoryInterface $delegate = null;

    /**
     * @param Closure(): PDO $pdoFactory Resolves the PDO connection on first
     *        use. Invoked lazily from inside the request's error boundary.
     */
    public function __construct(
        private readonly Closure $pdoFactory,
    ) {
    }

    public function record(string $username, DateTimeImmutable $attemptedAt, bool $success): void
    {
        $this->delegate()->record($username, $attemptedAt, $success);
    }

    public function countFailedSince(string $username, DateTimeImmutable $since): int
    {
        return $this->delegate()->countFailedSince($username, $since);
    }

    public function failedAttemptsSince(string $username, DateTimeImmutable $since): array
    {
        return $this->delegate()->failedAttemptsSince($username, $since);
    }

    /**
     * Resolve (once) the real PDO-backed repository, building the connection via
     * the injected factory on first access. Any failure propagates to the
     * front-controller error boundary (15.7).
     */
    private function delegate(): LoginAttemptRepositoryInterface
    {
        if ($this->delegate === null) {
            $this->delegate = new PdoLoginAttemptRepository(($this->pdoFactory)());
        }

        return $this->delegate;
    }
}
