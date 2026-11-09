<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ConfigException;
use Citations\DatabaseConfig;
use Citations\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see DatabaseConfig} covering Requirements 15.2 (required
 * credential fields) and 15.3 (default MariaDB port). Credential handling is an
 * I/O-adjacent, state-dependent concern verified via example tests per the
 * design Testing Strategy, not property tests.
 */
final class DatabaseConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function completeRaw(): array
    {
        return [
            'host'     => 'db.internal',
            'port'     => 3307,
            'name'     => 'citations',
            'user'     => 'app',
            'password' => 's3cret',
        ];
    }

    public function testBuildsConfigFromCompleteCredentials(): void
    {
        $config = DatabaseConfig::fromArray(self::completeRaw());

        $this->assertSame('db.internal', $config->host);
        $this->assertSame(3307, $config->port);
        $this->assertSame('citations', $config->name);
        $this->assertSame('app', $config->user);
        $this->assertSame('s3cret', $config->password);
    }

    /**
     * 15.3: an absent, null, empty or non-positive port falls back to the
     * MariaDB default 3306.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function portFallbackCases(): array
    {
        return [
            'key absent'   => [null], // handled by unsetting below
            'null port'    => [null],
            'empty string' => [''],
            'zero'         => [0],
            'negative'     => [-5],
            'non-numeric'  => ['abc'],
        ];
    }

    #[DataProvider('portFallbackCases')]
    public function testFallsBackToDefaultPort(mixed $port): void
    {
        $raw = self::completeRaw();
        $raw['port'] = $port;

        $config = DatabaseConfig::fromArray($raw);

        $this->assertSame(DatabaseConfig::DEFAULT_PORT, $config->port);
        $this->assertSame(3306, $config->port);
    }

    public function testFallsBackToDefaultPortWhenKeyMissing(): void
    {
        $raw = self::completeRaw();
        unset($raw['port']);

        $config = DatabaseConfig::fromArray($raw);

        $this->assertSame(3306, $config->port);
    }

    public function testAcceptsNumericStringPort(): void
    {
        $raw = self::completeRaw();
        $raw['port'] = '3309';

        $config = DatabaseConfig::fromArray($raw);

        $this->assertSame(3309, $config->port);
    }

    /**
     * 15.2: any missing or blank required field (host, name, user, password)
     * aborts config creation with a generic INTERNAL_ERROR-mapped exception.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function missingRequiredFieldCases(): array
    {
        return [
            'host absent'       => ['host', '__unset__'],
            'host blank'        => ['host', '   '],
            'host empty'        => ['host', ''],
            'name absent'       => ['name', '__unset__'],
            'name blank'        => ['name', "\t"],
            'user absent'       => ['user', '__unset__'],
            'user empty'        => ['user', ''],
            'password absent'   => ['password', '__unset__'],
            'password empty'    => ['password', ''],
        ];
    }

    #[DataProvider('missingRequiredFieldCases')]
    public function testMissingRequiredFieldThrows(string $field, mixed $value): void
    {
        $raw = self::completeRaw();
        if ($value === '__unset__') {
            unset($raw[$field]);
        } else {
            $raw[$field] = $value;
        }

        try {
            DatabaseConfig::fromArray($raw);
            $this->fail('Expected ConfigException for missing field: ' . $field);
        } catch (ConfigException $e) {
            $this->assertSame(ErrorCode::INTERNAL_ERROR, $e->errorCode());
            // The generic message must never contain the credential value.
            if (is_string($value) && trim($value) !== '') {
                $this->assertStringNotContainsString($value, $e->getMessage());
            }
        }
    }

    public function testPasswordWithWhitespaceIsAccepted(): void
    {
        $raw = self::completeRaw();
        $raw['password'] = '  spaced secret  ';

        $config = DatabaseConfig::fromArray($raw);

        // Password surrounding characters are significant and must be preserved.
        $this->assertSame('  spaced secret  ', $config->password);
    }

    public function testDsnDoesNotExposeThroughException(): void
    {
        $config = DatabaseConfig::fromArray(self::completeRaw());

        $dsn = $config->dsn();
        $this->assertStringContainsString('mysql:', $dsn);
        $this->assertStringContainsString('host=db.internal', $dsn);
        $this->assertStringContainsString('port=3307', $dsn);
        $this->assertStringContainsString('dbname=citations', $dsn);
        $this->assertStringContainsString('charset=utf8mb4', $dsn);
    }
}
