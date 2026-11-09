<?php

declare(strict_types=1);

namespace Citations;

/**
 * Immutable, validated set of database connection parameters
 * (Requirements 15.1, 15.2, 15.3; design "Konfiguration & Credentials").
 *
 * An instance can only exist once the four required credential fields —
 * host/address, database name, user and password — are present and non-blank
 * (15.2). The port is always a concrete value: when none was configured it is
 * the MariaDB default {@see self::DEFAULT_PORT} (15.3). This keeps the
 * connection factory free of validation and defaulting logic.
 *
 * The values themselves (host, name, user, password) are credentials; this
 * object only carries them so the factory can open a connection. They are never
 * rendered into an exception message, log line, or response (15.2, 15.6) — that
 * discipline lives in {@see ConfigException} and the connection factory.
 */
final class DatabaseConfig
{
    /** MariaDB's standard TCP port, used when no port is configured (15.3). */
    public const DEFAULT_PORT = 3306;

    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $name,
        public readonly string $user,
        public readonly string $password,
    ) {
    }

    /**
     * Build a validated config from a raw associative credential array.
     *
     * Expected keys: `host`, `name`, `user`, `password` (all required) and the
     * optional `port`. Values are read leniently (scalars are cast to string,
     * surrounding whitespace is trimmed) so a config file or environment
     * variable can supply them in their natural form.
     *
     * Required-field rule (15.2): if any of host/address, database name, user or
     * password is absent or blank after trimming, this throws a
     * {@see ConfigException} and NO config is produced — the caller must then
     * abort before connecting, with a generic error that leaks no value.
     *
     * Port rule (15.3): an absent, null, empty, or non-positive port falls back
     * to {@see self::DEFAULT_PORT}; a configured positive integer is used as-is.
     *
     * @param array<string, mixed> $raw The raw credential values.
     * @throws ConfigException when a required credential field is missing/blank.
     */
    public static function fromArray(array $raw): self
    {
        $host     = self::requiredField($raw, 'host');
        $name     = self::requiredField($raw, 'name');
        $user     = self::requiredField($raw, 'user');
        $password = self::requiredPassword($raw);
        $port     = self::resolvePort($raw['port'] ?? null);

        return new self($host, $port, $name, $user, $password);
    }

    /**
     * Read a required, non-blank string field, throwing the generic
     * ConfigException when it is absent or blank (15.2). The field name and
     * value never appear in the thrown error.
     */
    private static function requiredField(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;

        if (!is_scalar($value)) {
            throw ConfigException::missingCredentialField();
        }

        $value = trim((string) $value);
        if ($value === '') {
            throw ConfigException::missingCredentialField();
        }

        return $value;
    }

    /**
     * Read the required password. Unlike the other fields its surrounding
     * characters are significant, so only presence and non-emptiness are
     * checked; the raw value is used verbatim. Absence or an empty string is a
     * missing required field (15.2).
     */
    private static function requiredPassword(array $raw): string
    {
        $value = $raw['password'] ?? null;

        if (!is_scalar($value)) {
            throw ConfigException::missingCredentialField();
        }

        $value = (string) $value;
        if ($value === '') {
            throw ConfigException::missingCredentialField();
        }

        return $value;
    }

    /**
     * Resolve the effective port: a configured positive integer, otherwise the
     * MariaDB default (15.3).
     */
    private static function resolvePort(mixed $port): int
    {
        if (is_int($port) && $port > 0) {
            return $port;
        }

        if (is_string($port) && ctype_digit(trim($port)) && (int) trim($port) > 0) {
            return (int) trim($port);
        }

        return self::DEFAULT_PORT;
    }

    /**
     * The PDO DSN for this configuration (MariaDB via the mysql driver, utf8mb4).
     * Carries credential-adjacent values (host, port, db name) and so must not
     * be logged or surfaced in an error.
     */
    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->name,
        );
    }
}
