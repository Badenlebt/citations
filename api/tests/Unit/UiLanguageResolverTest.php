<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\UiLanguage;
use Citations\UiLanguageResolver;
use PHPUnit\Framework\TestCase;

/**
 * Validates: Requirements 6.7, 6.8, 6.9
 */
final class UiLanguageResolverTest extends TestCase
{
    public function testNullUiLanguageYieldsNoCandidates(): void
    {
        $this->assertSame([], UiLanguageResolver::candidateLanguageIds(null, ['de' => 1, 'en' => 2]));
    }

    public function testOnlyUiMappingPresent(): void
    {
        $this->assertSame([1], UiLanguageResolver::candidateLanguageIds(UiLanguage::DE, ['de' => 1]));
    }

    public function testOnlyEnglishMappingPresentFallsBackToIt(): void
    {
        $this->assertSame([2], UiLanguageResolver::candidateLanguageIds(UiLanguage::DE, ['en' => 2]));
    }

    public function testBothMappingsUiFirstThenEnglish(): void
    {
        $this->assertSame([1, 2], UiLanguageResolver::candidateLanguageIds(UiLanguage::DE, ['de' => 1, 'en' => 2]));
    }

    public function testEnglishUiWithBothMappingsYieldsEnglishOnce(): void
    {
        $this->assertSame([2], UiLanguageResolver::candidateLanguageIds(UiLanguage::EN, ['de' => 1, 'en' => 2]));
    }

    public function testSameIdIsDeduplicated(): void
    {
        $this->assertSame([5], UiLanguageResolver::candidateLanguageIds(UiLanguage::DE, ['de' => 5, 'en' => 5]));
    }

    public function testMissingEntriesYieldEmptyList(): void
    {
        $this->assertSame([], UiLanguageResolver::candidateLanguageIds(UiLanguage::DE, []));
        $this->assertSame([], UiLanguageResolver::candidateLanguageIds(UiLanguage::EN, ['de' => 1]));
    }
}
