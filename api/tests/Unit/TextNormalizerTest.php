<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Example-based unit tests for the pure Unicode normal form (Requirement 2.6).
 * Property 12 (case-/diacritics invariance) is covered separately by the
 * optional property test in task 1.3.
 */
final class TextNormalizerTest extends TestCase
{
    public function testLowercasesAsciiInput(): void
    {
        self::assertSame('hello world', TextNormalizer::normalize('Hello World'));
        self::assertSame('abc', TextNormalizer::normalize('ABC'));
    }

    public function testIsCaseInvariant(): void
    {
        $lower = TextNormalizer::normalize('straße café');
        $upper = TextNormalizer::normalize('STRAßE CAFÉ');
        self::assertSame($lower, $upper);
    }

    public function testRemovesDiacriticsFromPrecomposedLetters(): void
    {
        self::assertSame('a', TextNormalizer::normalize('ä'));
        self::assertSame('a', TextNormalizer::normalize('Ä'));
        self::assertSame('e', TextNormalizer::normalize('É'));
        self::assertSame('o', TextNormalizer::normalize('Ö'));
        self::assertSame('u', TextNormalizer::normalize('Ü'));
        self::assertSame('n', TextNormalizer::normalize('Ñ'));
    }

    public function testPrecomposedAndCombiningFormsProduceIdenticalOutput(): void
    {
        // Precomposed "ä" (U+00E4) vs. "a" + combining diaeresis (U+0308).
        $precomposed = "\u{00E4}";
        $combining = "a\u{0308}";
        self::assertSame(
            TextNormalizer::normalize($precomposed),
            TextNormalizer::normalize($combining)
        );
        self::assertSame('a', TextNormalizer::normalize($combining));
    }

    public function testBaseLetterAndDiacriticVariantsAllCollapse(): void
    {
        // "Ä", "ä", and base "a" with combining diaeresis all -> "a".
        $forms = ["\u{00C4}", "\u{00E4}", 'a', "a\u{0308}"];
        foreach ($forms as $form) {
            self::assertSame('a', TextNormalizer::normalize($form));
        }
    }

    /**
     * @dataProvider idempotencySamples
     */
    public function testNormalizeIsIdempotent(string $input): void
    {
        $once = TextNormalizer::normalize($input);
        self::assertSame($once, TextNormalizer::normalize($once));
    }

    /** @return iterable<string, array{string}> */
    public static function idempotencySamples(): iterable
    {
        yield 'empty' => [''];
        yield 'ascii' => ['Hello World'];
        yield 'german umlauts' => ['Grüße aus München'];
        yield 'combining form' => ["a\u{0308}O\u{0308}"];
        yield 'mixed case accents' => ['ÀÉÎÕÜ ñ Ç'];
        yield 'whitespace only' => ["  \t\n "];
    }

    public function testPreservesInnerWhitespaceAndNonLetters(): void
    {
        self::assertSame('a 1-2 b', TextNormalizer::normalize('A 1-2 B'));
    }

    public function testStoredValueContainsNormalizedSearchTerm(): void
    {
        // A stored quote text and a matching search term share a normal form,
        // so the term is a substring of the normalized stored value (2.6).
        $storedText = 'Die Zukunft gehört denen, die an die SCHÖNHEIT glauben';
        $searchTerm = 'schönheit';

        $normalizedStored = TextNormalizer::normalize($storedText);
        $normalizedTerm = TextNormalizer::normalize($searchTerm);

        self::assertSame('schonheit', $normalizedTerm);
        self::assertStringContainsString($normalizedTerm, $normalizedStored);
    }

    public function testStoredSourceNameComparableToSearchTerm(): void
    {
        $sourceName = 'Émile Zola';
        $term = 'EMILE';
        self::assertStringContainsString(
            TextNormalizer::normalize($term),
            TextNormalizer::normalize($sourceName)
        );
    }
}
