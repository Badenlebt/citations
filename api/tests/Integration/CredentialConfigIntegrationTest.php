<?php

declare(strict_types=1);

namespace Citations\Tests\Integration;

use Citations\ConfigException;
use Citations\ConfigLoader;
use Citations\DatabaseConfig;
use Citations\ErrorCode;
use Citations\PdoConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for the configuration/credential abort paths of the I/O
 * bootstrap (task 11.4; Requirements 15.2, 15.3, 15.6, 15.7).
 *
 * These assertions need NO live database: a missing credential field or a
 * missing/unreadable source must abort BEFORE any connect is attempted, and a
 * forced connect failure must surface generically. They therefore run in every
 * environment (unit-like reliability) and anchor the "generic 500 without
 * credentials/SQL/stack traces" guarantee end-to-end from the loader through
 * the connection factory.
 *
 * The live-DB behaviours (round-trips, random selection, default port against a
 * real server) live in the other integration test classes.
 */
final class CredentialConfigIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * 15.2: a config missing a required credential field (here: password) makes
     * the loader abort with a generic INTERNAL_ERROR before any connect, and the
     * message leaks no credential value. We also assert no connect is attempted
     * by driving the full loader path (file source) with a factory spy that
     * would have recorded a call.
     */
    public function testMissingCredentialFieldAbortsWithGenericErrorAndNoConnect(): void
    {
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return ['db' => [
            'host'     => 'leak-host-15-2',
            'name'     => 'leak_db_15_2',
            'user'     => 'leak_user_15_2',
            // password deliberately omitted (15.2)
        ]];
        PHP);

        $connectAttempted = false;

        try {
            $config = (new ConfigLoader($path, []))->loadDatabaseConfig();
            // Unreachable: loader must throw before returning a config.
            $connectAttempted = true;
            (new PdoConnectionFactory())->connect($config, ['connectTimeoutSeconds' => 1]);
            $this->fail('Expected ConfigException for the missing password field.');
        } catch (ConfigException $e) {
            $this->assertSame(ErrorCode::INTERNAL_ERROR, $e->errorCode());

            $message = $e->getMessage();
            // No credential value and no field name leaks into the message.
            $this->assertStringNotContainsString('leak-host-15-2', $message);
            $this->assertStringNotContainsString('leak_db_15_2', $message);
            $this->assertStringNotContainsString('leak_user_15_2', $message);
            $this->assertStringNotContainsString('password', strtolower($message));

            // The abort happened in the loader, so no connect was reached.
            $this->assertFalse($connectAttempted, 'Connect must not be attempted when a field is missing.');
            // Nothing chained that could resurface the detail.
            $this->assertNull($e->getPrevious());
        }
    }

    /**
     * 15.6: a missing/unreadable credential source (no file, no env) aborts with
     * the same generic INTERNAL_ERROR and never discloses the attempted path or
     * name of the source.
     */
    public function testMissingCredentialSourceAbortsWithoutDisclosingPath(): void
    {
        $missing = sys_get_temp_dir() . '/citations-it-missing-' . uniqid('', true) . '.php';

        try {
            (new ConfigLoader($missing, []))->loadDatabaseConfig();
            $this->fail('Expected ConfigException for the missing source.');
        } catch (ConfigException $e) {
            $this->assertSame(ErrorCode::INTERNAL_ERROR, $e->errorCode());

            $message = $e->getMessage();
            $this->assertStringNotContainsString($missing, $message);
            $this->assertStringNotContainsString(basename($missing), $message);
            $this->assertNull($e->getPrevious());
        }
    }

    /**
     * 15.6 variant: a source file that exists but is unusable (does not return
     * an array) is treated as the same generic unreadable source, again without
     * disclosing its path.
     */
    public function testUnusableSourceFileAbortsGenerically(): void
    {
        $path = $this->writeConfigFile("<?php\nreturn 'not-a-config-array';\n");

        try {
            (new ConfigLoader($path, []))->loadDatabaseConfig();
            $this->fail('Expected ConfigException for the unusable source file.');
        } catch (ConfigException $e) {
            $this->assertSame(ErrorCode::INTERNAL_ERROR, $e->errorCode());
            $this->assertStringNotContainsString($path, $e->getMessage());
            $this->assertStringNotContainsString(basename($path), $e->getMessage());
        }
    }

    /**
     * 15.3: a config WITHOUT a port field resolves to the MariaDB default port
     * (3306). This is a pure config assertion (no live DB needed) and complements
     * the live default-port connect check in PdoDatabaseRoundTripIntegrationTest.
     */
    public function testMissingPortResolvesToMariaDbDefault(): void
    {
        $env = [
            'CITATIONS_DB_HOST'     => '127.0.0.1',
            'CITATIONS_DB_NAME'     => 'citations_it',
            'CITATIONS_DB_USER'     => 'citations_it',
            'CITATIONS_DB_PASSWORD' => 'secret',
            // No CITATIONS_DB_PORT => default (15.3).
        ];

        $config = (new ConfigLoader(null, $env))->loadDatabaseConfig();

        $this->assertSame(DatabaseConfig::DEFAULT_PORT, $config->port);
        $this->assertSame(3306, $config->port);
        $this->assertStringContainsString('port=3306', $config->dsn());
    }

    /**
     * 15.7: a forced connect failure (unreachable, non-routable host) surfaces
     * as a generic INTERNAL_ERROR with no credential values, no SQL/DSN text,
     * and no chained driver exception that could leak a stack trace.
     */
    public function testForcedConnectFailureIsGenericAndLeaksNothing(): void
    {
        if (!in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql driver not available.');
        }

        // TEST-NET-1 (RFC 5737) is non-routable; a short timeout fails fast.
        $config = DatabaseConfig::fromArray([
            'host'     => '192.0.2.1',
            'port'     => 3306,
            'name'     => 'leak_db_15_7',
            'user'     => 'leak_user_15_7',
            'password' => 'leak_pass_15_7',
        ]);

        try {
            (new PdoConnectionFactory())->connect($config, ['connectTimeoutSeconds' => 1]);
            $this->fail('Expected the forced connect to fail.');
        } catch (ConfigException $e) {
            $this->assertSame(ErrorCode::INTERNAL_ERROR, $e->errorCode());

            $message = $e->getMessage();
            $this->assertStringNotContainsString('leak_db_15_7', $message);
            $this->assertStringNotContainsString('leak_user_15_7', $message);
            $this->assertStringNotContainsString('leak_pass_15_7', $message);
            $this->assertStringNotContainsString('192.0.2.1', $message);
            $this->assertStringNotContainsString('mysql:', $message);
            $this->assertStringNotContainsStringIgnoringCase('sqlstate', $message);
            $this->assertNull($e->getPrevious());
        }
    }

    private function writeConfigFile(string $phpContents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'citations-it-config-') . '.php';
        file_put_contents($path, $phpContents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
