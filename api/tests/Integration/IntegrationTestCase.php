<?php

declare(strict_types=1);

namespace Citations\Tests\Integration;

use Citations\ConfigException;
use Citations\DatabaseConfig;
use Citations\PdoConnectionFactory;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the PDO/MariaDB integration tests of the I/O path (task 11.4;
 * Requirements 3.2, 15.2, 15.3, 15.6, 15.7, 16.2).
 *
 * These tests only run against a REAL, disposable MariaDB. The connection is
 * taken from a dedicated CITATIONS_IT_* environment variable set so a normal
 * `phpunit` run (unit + property + integration) stays green on machines and CI
 * without a database: whenever the variables are absent OR the connect fails,
 * {@see self::requireDatabase()} calls {@see TestCase::markTestSkipped()} with a
 * clear message rather than failing.
 *
 * Required environment variables (all mandatory except the port):
 *   - CITATIONS_IT_DB_HOST      host/address of the test MariaDB
 *   - CITATIONS_IT_DB_PORT      optional; omit to use the MariaDB default (3306)
 *   - CITATIONS_IT_DB_NAME      name of a DISPOSABLE test schema (its tables are
 *                               dropped and recreated on every test)
 *   - CITATIONS_IT_DB_USER      user with full rights on that schema
 *   - CITATIONS_IT_DB_PASSWORD  that user's password
 *
 * On first use the schema from db/schema.sql and the seed from db/seed.sql are
 * applied to the configured database; before each test every table is
 * truncated and the Unbekannt-Quelle re-seeded, so stored data is isolated
 * between tests (no cross-test leakage).
 */
abstract class IntegrationTestCase extends TestCase
{
    /** Shared connection for the whole test process, built lazily once. */
    private static ?PDO $pdo = null;

    /** True once schema.sql/seed.sql were applied to the test database. */
    private static bool $schemaApplied = false;

    protected function setUp(): void
    {
        $pdo = $this->requireDatabase();
        $this->resetDatabase($pdo);
    }

    /**
     * Return the live PDO connection, or skip the test when no disposable test
     * database is configured/reachable. This is the single gate that keeps the
     * integration suite green without a DB: a missing CITATIONS_IT_* set or a
     * failing connect both become a clean skip, never a failure.
     */
    final protected function requireDatabase(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql driver not available; skipping live-DB integration test.');
        }

        $config = self::integrationConfig();
        if ($config === null) {
            $this->markTestSkipped(
                'No disposable test database configured. Set CITATIONS_IT_DB_HOST/'
                . 'NAME/USER/PASSWORD (and optionally CITATIONS_IT_DB_PORT) to run '
                . 'the PDO/MariaDB integration tests.',
            );
        }

        try {
            self::$pdo = (new PdoConnectionFactory())->connect($config, ['connectTimeoutSeconds' => 3]);
        } catch (ConfigException) {
            // The generic failure carries no detail; the skip message must not
            // either (mirrors 15.7 discipline).
            $this->markTestSkipped('Configured test database is not reachable; skipping integration test.');
        }

        return self::$pdo;
    }

    /**
     * Build the validated {@see DatabaseConfig} from the CITATIONS_IT_* env, or
     * null when the mandatory fields are not all present. The port is passed
     * through only when set, so an absent port exercises the MariaDB default
     * (15.3) just like production.
     */
    final protected static function integrationConfig(): ?DatabaseConfig
    {
        $host = self::env('CITATIONS_IT_DB_HOST');
        $name = self::env('CITATIONS_IT_DB_NAME');
        $user = self::env('CITATIONS_IT_DB_USER');
        $password = self::env('CITATIONS_IT_DB_PASSWORD');

        if ($host === null || $name === null || $user === null || $password === null) {
            return null;
        }

        $raw = [
            'host' => $host,
            'name' => $name,
            'user' => $user,
            'password' => $password,
        ];

        $port = self::env('CITATIONS_IT_DB_PORT');
        if ($port !== null && $port !== '') {
            $raw['port'] = $port;
        }

        return DatabaseConfig::fromArray($raw);
    }

    /**
     * Whether a CITATIONS_IT_DB_PORT is explicitly configured. Used by the
     * default-port assertion (15.3): if the test DB runs on a non-default port
     * the default-port check is skipped rather than failing.
     */
    final protected static function hasExplicitPort(): bool
    {
        $port = self::env('CITATIONS_IT_DB_PORT');

        return $port !== null && $port !== '';
    }

    /** Read a single environment variable, returning null when unset/empty-false. */
    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
    }

    /**
     * (Re)create the schema+seed once per process, then truncate every table and
     * re-seed the Unbekannt-Quelle before each test so stored data is isolated
     * (no row survives from a previous test).
     */
    private function resetDatabase(PDO $pdo): void
    {
        if (!self::$schemaApplied) {
            $this->applySqlFile($pdo, self::dbPath('schema.sql'), dropFirst: true);
            self::$schemaApplied = true;
        }

        // Truncate in FK-safe manner, then re-apply the seed.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['login_attempt', 'session', 'quote', 'language', 'operator', 'source'] as $table) {
            $pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->applySqlFile($pdo, self::dbPath('seed.sql'), dropFirst: false);
    }

    /**
     * Apply a .sql file statement-by-statement. When $dropFirst is set the
     * CREATE TABLE statements are preceded by DROP TABLE IF EXISTS so a rerun
     * starts from a clean schema. Comment lines and the SET NAMES pragma are
     * handled naturally as individual statements.
     */
    private function applySqlFile(PDO $pdo, string $path, bool $dropFirst): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            $this->fail('Could not read SQL file: ' . basename($path));
        }

        if ($dropFirst) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['login_attempt', 'session', 'quote', 'language', 'operator', 'source'] as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $table);
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        foreach (self::splitStatements($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Split a .sql script into executable statements on semicolons, stripping
     * line comments and blank lines. The schema/seed files use only simple
     * `;`-terminated statements (no stored routines), so a semicolon split is
     * sufficient and keeps the loader dependency-free.
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\r?\n/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $clean[] = $line;
        }

        $statements = [];
        foreach (explode(';', implode("\n", $clean)) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }

        return $statements;
    }

    /** Absolute path to a file in the repository's db/ directory. */
    private static function dbPath(string $file): string
    {
        return dirname(__DIR__, 3) . '/db/' . $file;
    }

    // ---- Shared fixture helpers for the concrete integration tests -----------

    /**
     * Insert a language directly and return its id. Mirrors what the language
     * mutation layer would persist (display name + normalized form).
     */
    final protected function seedLanguage(PDO $pdo, string $name, string $normalized): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO language (name, name_normalized) VALUES (:n, :norm)',
        );
        $stmt->execute([':n' => $name, ':norm' => $normalized]);

        return (int) $pdo->lastInsertId();
    }
}
