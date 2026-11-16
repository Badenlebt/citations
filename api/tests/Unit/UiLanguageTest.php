<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\UiLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates: Requirements 6.7
 */
final class UiLanguageTest extends TestCase
{
    public function testAcceptsExactValues(): void
    {
        $this->assertSame(UiLanguage::DE, UiLanguage::tryFromInput('de'));
        $this->assertSame(UiLanguage::EN, UiLanguage::tryFromInput('en'));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame(UiLanguage::DE, UiLanguage::tryFromInput("  de\t\n"));
        $this->assertSame(UiLanguage::EN, UiLanguage::tryFromInput(' en '));
    }

    public function testIsCaseSensitive(): void
    {
        $this->assertNull(UiLanguage::tryFromInput('DE'));
        $this->assertNull(UiLanguage::tryFromInput('En'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'unknown code' => ['fr'],
            'longer value' => ['deu'],
            'null' => [null],
            'int' => [1],
            'float' => [1.5],
            'bool' => [true],
            'array' => [['de']],
            'object' => [new \stdClass()],
        ];
    }

    #[DataProvider('invalidInputProvider')]
    public function testInvalidInputYieldsNull(mixed $input): void
    {
        $this->assertNull(UiLanguage::tryFromInput($input));
    }
}
