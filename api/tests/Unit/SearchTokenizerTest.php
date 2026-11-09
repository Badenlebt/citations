<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\SearchTokenizer;
use Citations\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Example-based unit tests for the pure search-input tokenization
 * (Requirement 1.1/1.2 "Suchbegriff", design "Abgeleitete / normalisierte Werte").
 */
final class SearchTokenizerTest extends TestCase
{
    public function testSplitsSingleSpaceSeparatedTerms(): void
    {
        self::assertSame(['alpha', 'beta', 'gamma'], SearchTokenizer::tokenize('alpha beta gamma'));
    }

    public function testCollapsesMultipleSpacesAndDropsEmptyTokens(): void
    {
        self::assertSame(['alpha', 'beta'], SearchTokenizer::tokenize('alpha     beta'));
        self::assertSame(['alpha', 'beta'], SearchTokenizer::tokenize('  alpha   beta  '));
    }

    public function testSplitsOnMixedUnicodeWhitespace(): void
    {
        // space, tab, newline, carriage return, and non-breaking space (U+00A0).
        $input = "one\ttwo\nthree\r\nfour\u{00A0}five";
        self::assertSame(['one', 'two', 'three', 'four', 'five'], SearchTokenizer::tokenize($input));
    }

    public function testEmptyInputYieldsEmptyArray(): void
    {
        self::assertSame([], SearchTokenizer::tokenize(''));
    }

    /**
     * @dataProvider whitespaceOnlySamples
     */
    public function testWhitespaceOnlyInputYieldsEmptyArray(string $input): void
    {
        self::assertSame([], SearchTokenizer::tokenize($input));
    }

    /** @return iterable<string, array{string}> */
    public static function whitespaceOnlySamples(): iterable
    {
        yield 'single space' => [' '];
        yield 'many spaces' => ['      '];
        yield 'tabs and newlines' => ["\t\n\r\n"];
        yield 'non-breaking space' => ["\u{00A0}\u{00A0}"];
    }

    public function testSingleTermReturnedAsIs(): void
    {
        self::assertSame(['wort'], SearchTokenizer::tokenize('  wort  '));
    }

    public function testReturnsRawUnnormalizedTermsForCallerToNormalize(): void
    {
        $terms = SearchTokenizer::tokenize('Café  Grün');
        self::assertSame(['Café', 'Grün'], $terms);

        // Composition with the normalizer is the caller's responsibility and
        // yields the diacritics-free comparison basis. Note "ß" is a distinct
        // letter (not a diacritic), so it is only lowercased, never folded to
        // "ss"; this test deliberately avoids it.
        $normalized = array_map(
            static fn (string $t): string => TextNormalizer::normalize($t),
            $terms
        );
        self::assertSame(['cafe', 'grun'], $normalized);
    }
}
