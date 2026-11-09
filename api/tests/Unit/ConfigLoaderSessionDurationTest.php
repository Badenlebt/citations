<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ConfigLoader;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see ConfigLoader::loadSessionDurationHours()} (Auth-API
 * session duration, Requirements 6.1, 6.4).
 *
 * The accessor reads `session.durationHours` from config.php, or the
 * CITATIONS_SESSION_DURATION_HOURS environment variable when no readable file
 * is present, and returns null when unconfigured so {@see \Citations\SessionValidator}
 * applies its 24-hour default. The loader returns the raw configured value
 * without clamping — clamping happens only when the expiry is derived.
 */
final class ConfigLoaderSessionDurationTest extends TestCase
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

    public function testReadsDurationFromConfigFile(): void
    {
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return [
            'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => 'p'],
            'session' => ['durationHours' => 48],
        ];
        PHP);

        self::assertSame(48, (new ConfigLoader($path))->loadSessionDurationHours());
    }

    public function testReadsDurationFromEnvironmentWhenNoFile(): void
    {
        $env = ['CITATIONS_SESSION_DURATION_HOURS' => '12'];

        self::assertSame(12, (new ConfigLoader(null, $env))->loadSessionDurationHours());
    }

    public function testAbsentSessionSectionYieldsNull(): void
    {
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return ['db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => 'p']];
        PHP);

        self::assertNull((new ConfigLoader($path))->loadSessionDurationHours());
    }

    public function testAbsentEverywhereYieldsNull(): void
    {
        self::assertNull((new ConfigLoader(null, []))->loadSessionDurationHours());
    }

    public function testNonNumericValueYieldsNull(): void
    {
        $path = $this->writeConfigFile(<<<'PHP'
        <?php
        return [
            'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => 'p'],
            'session' => ['durationHours' => 'soon'],
        ];
        PHP);

        self::assertNull((new ConfigLoader($path))->loadSessionDurationHours());
    }

    public function testReturnsRawValueWithoutClamping(): void
    {
        // The loader does not judge or clamp the range (that happens when the
        // expiry is derived); a value outside [1, 168] is returned as-is.
        $env = ['CITATIONS_SESSION_DURATION_HOURS' => '999'];

        self::assertSame(999, (new ConfigLoader(null, $env))->loadSessionDurationHours());
    }
}
