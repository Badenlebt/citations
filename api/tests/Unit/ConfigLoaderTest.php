<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ConfigException;
use Citations\ConfigLoader;
use Citations\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see ConfigLoader} covering Requirements 15.1 (credentials
 * from config.php above the web root or environment variables; none hard-coded)
 * and 15.6 (missing/unreadable source ⇒ abort, generic INTERNAL_ERROR without
 * disclosing path/name).
 */
final class ConfigLoaderTest extends TestCase
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

    private function writeConfigFile(string $phpContents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'citations-config-') . '.php';
        file_put_contents($path, $phpContents);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testLoadsFromConfigFile(): void
    {
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return [
            'db' => [
                'host'     => 'file-host',
                'port'     => 3310,
                'name'     => 'file_db',
                'user'     => 'file_user',
                'password' => 'file_pass',
            ],
        ];
        PHP);

        $config = (new ConfigLoader($path))->loadDatabaseConfig();

        $this->assertSame('file-host', $config->host);
        $this->assertSame(3310, $config->port);
        $this->assertSame('file_db', $config->name);
        $this->assertSame('file_user', $config->user);
        $this->assertSame('file_pass', $config->password);
    }

    public function testLoadsFromEnvironmentWhenNoFile(): void
    {
        $env = [
            'CITATIONS_DB_HOST'     => 'env-host',
            'CITATIONS_DB_NAME'     => 'env_db',
            'CITATIONS_DB_USER'     => 'env_user',
            'CITATIONS_DB_PASSWORD' => 'env_pass',
            // No port => MariaDB default (15.3).
        ];

        $config = (new ConfigLoader(null, $env))->loadDatabaseConfig();

        $this->assertSame('env-host', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('env_db', $config->name);
        $this->assertSame('env_user', $config->user);
        $this->assertSame('env_pass', $config->password);
    }

    public function testFileTakesPrecedenceOverEnvironment(): void
    {
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return ['db' => [
            'host' => 'file-host', 'name' => 'file_db',
            'user' => 'file_user', 'password' => 'file_pass',
        ]];
        PHP);
        $env = [
            'CITATIONS_DB_HOST'     => 'env-host',
            'CITATIONS_DB_NAME'     => 'env_db',
            'CITATIONS_DB_USER'     => 'env_user',
            'CITATIONS_DB_PASSWORD' => 'env_pass',
        ];

        $config = (new ConfigLoader($path, $env))->loadDatabaseConfig();

        $this->assertSame('file-host', $config->host);
    }

    public function testFallsBackToEnvironmentWhenFileMissing(): void
    {
        $missing = sys_get_temp_dir() . '/citations-does-not-exist-' . uniqid() . '.php';
        $env = [
            'CITATIONS_DB_HOST'     => 'env-host',
            'CITATIONS_DB_NAME'     => 'env_db',
            'CITATIONS_DB_USER'     => 'env_user',
            'CITATIONS_DB_PASSWORD' => 'env_pass',
        ];

        $config = (new ConfigLoader($missing, $env))->loadDatabaseConfig();

        $this->assertSame('env-host', $config->host);
    }

    public function testMissingSourceThrowsGenericErrorWithoutPath(): void
    {
        $missing = sys_get_temp_dir() . '/citations-secret-path-' . uniqid() . '.php';

        try {
            (new ConfigLoader($missing, []))->loadDatabaseConfig();
            $this->fail('Expected ConfigException for missing source.');
        } catch (ConfigException $e) {
            $this->assertSame(ErrorCode::INTERNAL_ERROR, $e->errorCode());
            // 15.6: the error must not disclose the path/name of the source.
            $this->assertStringNotContainsString($missing, $e->getMessage());
            $this->assertStringNotContainsString(basename($missing), $e->getMessage());
        }
    }

    public function testNoSourceConfiguredThrowsGenericError(): void
    {
        try {
            (new ConfigLoader(null, []))->loadDatabaseConfig();
            $this->fail('Expected ConfigException when no source is configured.');
        } catch (ConfigException $e) {
            $this->assertSame(ErrorCode::INTERNAL_ERROR, $e->errorCode());
        }
    }

    public function testUnreadableFileContentsThrowsGenericError(): void
    {
        // A file that exists but does not return an array is an unusable source.
        $path = $this->writeConfigFile("<?php\nreturn 'not-an-array';\n");

        $this->expectException(ConfigException::class);
        (new ConfigLoader($path, []))->loadDatabaseConfig();
    }

    public function testMissingCredentialFieldInFileThrows(): void
    {
        // Source is readable but a required field (password) is absent (15.2).
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return ['db' => [
            'host' => 'h', 'name' => 'n', 'user' => 'u',
        ]];
        PHP);

        try {
            (new ConfigLoader($path, []))->loadDatabaseConfig();
            $this->fail('Expected ConfigException for missing credential field.');
        } catch (ConfigException $e) {
            $this->assertSame(ErrorCode::INTERNAL_ERROR, $e->errorCode());
        }
    }

    public function testPartialEnvironmentIsTreatedAsSourceButIncompleteFails(): void
    {
        // Any DB env var present => environment is the intended source; a missing
        // required field then fails via DatabaseConfig validation (15.2), not as
        // "no source".
        $env = ['CITATIONS_DB_HOST' => 'env-host'];

        $this->expectException(ConfigException::class);
        (new ConfigLoader(null, $env))->loadDatabaseConfig();
    }
}
