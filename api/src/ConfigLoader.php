<?php

declare(strict_types=1);

namespace Citations;

/**
 * Loads the setup configuration ("Einrichtungskonfiguration") from a secure
 * source: a PHP `config.php` placed ABOVE the web root, or environment
 * variables (Requirements 15.1, 15.6; design "Konfiguration & Credentials").
 *
 * No credentials are hard-coded in the application source (15.1): this loader
 * only reads them from an external file or the environment.
 *
 * Source resolution order:
 *   1. If a config file path is given AND that file exists and is readable, the
 *      array it returns is used.
 *   2. Otherwise, if the required DB environment variables are present, the
 *      config is assembled from the environment.
 *   3. Otherwise the source is considered missing/unreadable and a generic
 *      {@see ConfigException::unreadableSource()} is thrown (15.6).
 *
 * Crucially, when the source is missing or unreadable the thrown error never
 * reveals the path or name of the attempted source, nor any credential value
 * (15.6). A configured file path that does not exist or is unreadable, with no
 * environment fallback available, surfaces as the same generic failure as "no
 * source configured at all".
 */
final class ConfigLoader
{
    /** Environment variable names for the DB credentials (env fallback, 15.1). */
    private const ENV_DB = [
        'host'     => 'CITATIONS_DB_HOST',
        'port'     => 'CITATIONS_DB_PORT',
        'name'     => 'CITATIONS_DB_NAME',
        'user'     => 'CITATIONS_DB_USER',
        'password' => 'CITATIONS_DB_PASSWORD',
    ];

    /**
     * Environment variable names for the initial-operator credentials (env
     * fallback, 17.1). Only the password HASH is ever read — never a plaintext
     * password (17.4).
     */
    private const ENV_INITIAL_OPERATOR = [
        'username'     => 'CITATIONS_INITIAL_OPERATOR_USERNAME',
        'passwordHash' => 'CITATIONS_INITIAL_OPERATOR_PASSWORD_HASH',
    ];

    /** Environment variable name for the configured session duration (6.4). */
    private const ENV_SESSION_DURATION_HOURS = 'CITATIONS_SESSION_DURATION_HOURS';

    /**
     * @param string|null $configFilePath Absolute path to the config.php above
     *        the web root, or null to rely solely on environment variables.
     * @param array<string, string>|null $env Environment source; defaults to the
     *        process environment ($_SERVER/getenv). Injectable for testing so the
     *        loader stays free of hidden globals.
     */
    public function __construct(
        private readonly ?string $configFilePath = null,
        private readonly ?array $env = null,
    ) {
    }

    /**
     * Load and validate the database configuration from the secure source.
     *
     * @throws ConfigException when the source is missing/unreadable (15.6), or
     *         when a required credential field is absent/blank (15.2). The error
     *         is generic and leaks neither credential values nor the source
     *         path/name.
     */
    public function loadDatabaseConfig(): DatabaseConfig
    {
        $db = $this->rawDatabaseCredentials();

        return DatabaseConfig::fromArray($db);
    }

    /**
     * Load the initial-operator credentials (username + password hash) from the
     * secure source, mirroring {@see self::loadDatabaseConfig()} (17.1): the
     * `initialOperator` section of config.php takes precedence, otherwise the
     * CITATIONS_INITIAL_OPERATOR_* environment variables are used.
     *
     * Unlike the DB config, an absent or incomplete initial-operator section is
     * NOT an error: it simply yields an incomplete {@see InitialOperatorConfig}
     * (empty username and/or hash). The seeder then creates no account (17.5).
     * A plaintext password is never read here; only the precomputed hash (17.4).
     *
     * If neither a readable config file nor any initial-operator environment
     * variable is present, an {@see InitialOperatorConfig::absent()} is returned
     * rather than throwing — the request bootstrap can still proceed and simply
     * seeds nothing.
     */
    public function loadInitialOperator(): InitialOperatorConfig
    {
        $fromFile = $this->loadFromFile();
        if ($fromFile !== null) {
            $section = $fromFile['initialOperator'] ?? null;

            return InitialOperatorConfig::fromArray(is_array($section) ? $section : []);
        }

        $fromEnv = $this->loadInitialOperatorFromEnv();
        if ($fromEnv !== null) {
            return InitialOperatorConfig::fromArray($fromEnv);
        }

        return InitialOperatorConfig::absent();
    }

    /**
     * Load the configured session duration in hours from the secure source
     * (Requirements 6.1, 6.4), or null when none is configured.
     *
     * The value comes from the `session.durationHours` entry of config.php, or
     * the {@see self::ENV_SESSION_DURATION_HOURS} environment variable when no
     * readable config file is present (mirroring the other accessors). A null
     * result signals "unconfigured" so {@see \Citations\SessionValidator} can
     * apply its 24-hour default; a present value is returned as-is and clamped
     * into the permitted [1, 168] range only when the expiry is derived — so
     * the loader itself does not judge or clamp it. A non-numeric or absent
     * entry yields null.
     *
     * @return int|null The configured duration in hours, or null if unconfigured.
     */
    public function loadSessionDurationHours(): ?int
    {
        $fromFile = $this->loadFromFile();
        if ($fromFile !== null) {
            $section = $fromFile['session'] ?? null;
            $value = is_array($section) ? ($section['durationHours'] ?? null) : null;

            return $this->toNullableInt($value);
        }

        return $this->toNullableInt($this->readEnv(self::ENV_SESSION_DURATION_HOURS));
    }

    /**
     * Coerce a configured session-duration value to an int, or null when it is
     * absent or not a meaningful integer. Accepts native ints and numeric
     * strings (the env source is always a string); anything else is treated as
     * unconfigured.
     */
    private function toNullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Assemble the raw initial-operator values from environment variables when
     * at least one of them is present; otherwise null (no env source).
     *
     * Any present CITATIONS_INITIAL_OPERATOR_* variable signals the environment
     * is the intended source, so the array is returned even if incomplete —
     * completeness is then decided by {@see InitialOperatorConfig::isComplete()}
     * (17.2, 17.5), not treated as a fatal error.
     *
     * @return array<string, string>|null
     */
    private function loadInitialOperatorFromEnv(): ?array
    {
        $values = [];
        $anyPresent = false;

        foreach (self::ENV_INITIAL_OPERATOR as $key => $envName) {
            $value = $this->readEnv($envName);
            if ($value !== null) {
                $anyPresent = true;
                $values[$key] = $value;
            }
        }

        return $anyPresent ? $values : null;
    }

    /**
     * Resolve the raw DB credential array from the file or the environment,
     * throwing the generic unreadable-source error when neither is available
     * (15.6).
     *
     * @return array<string, mixed>
     */
    private function rawDatabaseCredentials(): array
    {
        $fromFile = $this->loadFromFile();
        if ($fromFile !== null) {
            $db = $fromFile['db'] ?? null;

            return is_array($db) ? $db : [];
        }

        $fromEnv = $this->loadFromEnv();
        if ($fromEnv !== null) {
            return $fromEnv;
        }

        // Neither a readable file nor environment credentials: missing/unreadable
        // source. The error names no path and no value (15.6).
        throw ConfigException::unreadableSource();
    }

    /**
     * Read the config file if a path was given and the file exists and is
     * readable; otherwise null (so the env fallback can apply). A returned value
     * that is not an array is treated as an unreadable source.
     *
     * @return array<string, mixed>|null
     */
    private function loadFromFile(): ?array
    {
        if ($this->configFilePath === null || $this->configFilePath === '') {
            return null;
        }

        if (!is_file($this->configFilePath) || !is_readable($this->configFilePath)) {
            return null;
        }

        /** @psalm-suppress UnresolvableInclude */
        $config = require $this->configFilePath;

        if (!is_array($config)) {
            // The file exists but does not yield a usable config: treat as an
            // unreadable source without disclosing its path (15.6).
            throw ConfigException::unreadableSource();
        }

        return $config;
    }

    /**
     * Assemble raw DB credentials from environment variables when at least one
     * of them is present; otherwise null (no env source configured).
     *
     * Presence of any DB env var signals that the environment is the intended
     * source, so the array is returned even if incomplete — completeness is then
     * enforced by {@see DatabaseConfig::fromArray()} (15.2). When no DB env var
     * is set at all, the environment is not a source.
     *
     * @return array<string, mixed>|null
     */
    private function loadFromEnv(): ?array
    {
        $db = [];
        $anyPresent = false;

        foreach (self::ENV_DB as $key => $envName) {
            $value = $this->readEnv($envName);
            if ($value !== null) {
                $anyPresent = true;
                $db[$key] = $value;
            }
        }

        return $anyPresent ? $db : null;
    }

    /**
     * Read a single environment variable from the injected env or the process
     * environment. Returns null when unset.
     */
    private function readEnv(string $name): ?string
    {
        if ($this->env !== null) {
            return array_key_exists($name, $this->env) ? $this->env[$name] : null;
        }

        $value = getenv($name);

        return $value === false ? null : $value;
    }
}
