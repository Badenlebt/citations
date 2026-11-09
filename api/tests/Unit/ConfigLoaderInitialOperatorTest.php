<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ConfigLoader;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see ConfigLoader::loadInitialOperator()} covering
 * Requirement 17.1 (username + password hash sourced from config.php above the
 * web root OR environment variables) and that an absent/incomplete section is
 * not a fatal error but simply yields an incomplete, non-seedable config (17.5).
 * Only the hash is ever read — never a plaintext password (17.4).
 */
final class ConfigLoaderInitialOperatorTest extends TestCase
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

    public function testLoadsInitialOperatorFromConfigFile(): void
    {
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return [
            'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => 'p'],
            'initialOperator' => [
                'username'     => 'admin',
                'passwordHash' => '$2y$10$filehashfilehashfilehash',
            ],
        ];
        PHP);

        $operator = (new ConfigLoader($path))->loadInitialOperator();

        self::assertTrue($operator->isComplete());
        self::assertSame('admin', $operator->username);
        self::assertSame('$2y$10$filehashfilehashfilehash', $operator->passwordHash);
    }

    public function testLoadsInitialOperatorFromEnvironmentWhenNoFile(): void
    {
        $env = [
            'CITATIONS_INITIAL_OPERATOR_USERNAME'      => 'envadmin',
            'CITATIONS_INITIAL_OPERATOR_PASSWORD_HASH' => '$2y$10$envhashenvhashenvhash',
        ];

        $operator = (new ConfigLoader(null, $env))->loadInitialOperator();

        self::assertTrue($operator->isComplete());
        self::assertSame('envadmin', $operator->username);
        self::assertSame('$2y$10$envhashenvhashenvhash', $operator->passwordHash);
    }

    public function testFileSectionTakesPrecedenceOverEnvironment(): void
    {
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return [
            'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => 'p'],
            'initialOperator' => ['username' => 'fileadmin', 'passwordHash' => 'filehash'],
        ];
        PHP);
        $env = [
            'CITATIONS_INITIAL_OPERATOR_USERNAME'      => 'envadmin',
            'CITATIONS_INITIAL_OPERATOR_PASSWORD_HASH' => 'envhash',
        ];

        $operator = (new ConfigLoader($path, $env))->loadInitialOperator();

        self::assertSame('fileadmin', $operator->username);
        self::assertSame('filehash', $operator->passwordHash);
    }

    public function testMissingInitialOperatorSectionYieldsIncompleteConfig(): void
    {
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return ['db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => 'p']];
        PHP);

        $operator = (new ConfigLoader($path))->loadInitialOperator();

        // (17.5) No section ⇒ incomplete, not an error. Seeder creates nothing.
        self::assertFalse($operator->isComplete());
        self::assertSame('', $operator->username);
        self::assertSame('', $operator->passwordHash);
    }

    public function testEmptyHashInFileYieldsIncompleteConfig(): void
    {
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return [
            'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => 'p'],
            'initialOperator' => ['username' => 'admin', 'passwordHash' => ''],
        ];
        PHP);

        $operator = (new ConfigLoader($path))->loadInitialOperator();

        self::assertFalse($operator->isComplete());
    }

    public function testNoSourceAtAllYieldsAbsentConfigWithoutThrowing(): void
    {
        // Unlike loadDatabaseConfig(), a missing source is not fatal here.
        $operator = (new ConfigLoader(null, []))->loadInitialOperator();

        self::assertFalse($operator->isComplete());
        self::assertSame('', $operator->username);
        self::assertSame('', $operator->passwordHash);
    }

    public function testPartialEnvironmentIsTreatedAsSourceButIncomplete(): void
    {
        // Only the username env var present ⇒ env is the source, but the config
        // is incomplete (no hash) so the seeder creates nothing (17.5).
        $env = ['CITATIONS_INITIAL_OPERATOR_USERNAME' => 'envadmin'];

        $operator = (new ConfigLoader(null, $env))->loadInitialOperator();

        self::assertFalse($operator->isComplete());
        self::assertSame('envadmin', $operator->username);
        self::assertSame('', $operator->passwordHash);
    }
}
