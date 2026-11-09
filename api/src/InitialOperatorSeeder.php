<?php

declare(strict_types=1);

namespace Citations;

use Citations\Repository\OperatorRepositoryInterface;

/**
 * Idempotently seeds the initial operator account from the setup configuration
 * ("Einrichtungskonfiguration") at request/bootstrap time (Requirements 17.1,
 * 17.2, 17.3, 17.4, 17.5; design "Einrichtung des initialen Betreiber-Accounts").
 *
 * The username and precomputed password hash come exclusively from the setup
 * configuration — a file above the web root or environment variables — carried
 * in an {@see InitialOperatorConfig}. A plaintext password is never expected,
 * required, or stored; only the hash is ever passed to the repository (17.1,
 * 17.4).
 *
 * Semantics (see {@see self::seed()}):
 *   - An account already exists  ⇒ nothing is created, the existing account(s)
 *     are left untouched (17.3). This makes repeated bootstraps safe.
 *   - No account exists and the config is complete (username AND hash present
 *     and non-blank) ⇒ exactly one account is inserted with those values (17.2).
 *   - No account exists but the config is incomplete (username or hash
 *     missing/empty) ⇒ nothing is created (17.5); a later login attempt is then
 *     refused via the INVALID_CREDENTIALS path — not this class's concern.
 *
 * Existence is decided solely via {@see OperatorRepositoryInterface::hasAny()},
 * never by inspecting or matching the configured username, so an operator who
 * renamed the initial account is never re-seeded (17.3).
 */
final class InitialOperatorSeeder
{
    public function __construct(
        private readonly OperatorRepositoryInterface $operators,
    ) {
    }

    /**
     * Run the idempotent seed for the given configuration and report whether an
     * account was created.
     *
     * @return bool True when exactly one account was inserted (empty table and a
     *              complete config, 17.2); false when nothing was created — either
     *              because an account already existed (17.3) or the config was
     *              incomplete (17.5).
     */
    public function seed(InitialOperatorConfig $config): bool
    {
        // (17.3) An account already exists: create nothing, change nothing.
        if ($this->operators->hasAny()) {
            return false;
        }

        // (17.5) Empty table but no complete credentials: create nothing. Login
        // is later refused via INVALID_CREDENTIALS (handled elsewhere).
        if (!$config->isComplete()) {
            return false;
        }

        // (17.2, 17.4) Empty table and complete config: insert exactly one
        // account with the configured username and precomputed hash.
        $this->operators->insert($config->username, $config->passwordHash);

        return true;
    }
}
