<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\SearchTokenizer;
use Citations\TextNormalizer;
use Eris\Generator;

/**
 * Feature: citations-management, Property 12: Scoring ist case- und
 * diakritika-invariant (Normalform-Anteil).
 *
 * Covers the normal-form slice of Property 12 (design.md) / Requirement 2.6:
 * strings that differ only in case or in diacritics share the same normal
 * form, so {@see TextNormalizer::normalize()} must map them to IDENTICAL
 * output, and the derived tokenization comparison basis (normalize() applied
 * to each term of {@see SearchTokenizer::tokenize()}) must be identical too.
 *
 * Validates: Requirements 2.6
 */
final class TextNormalizerInvariancePropertyTest extends PropertyTestCase
{
    /**
     * Equivalence classes keyed by their shared normal form. Each class lists
     * surface variants (upper/lower case, precomposed vs. combining
     * diacritics) that MUST normalize to the key. Mixing any of these never
     * changes the normal form.
     *
     * @var array<string, list<string>>
     */
    private const EQUIVALENCE_CLASSES = [
        'a' => ['a', 'A', "\u{00E4}", "\u{00C4}", "a\u{0308}", "A\u{0308}", "\u{00E0}", "\u{00C1}", "a\u{0301}"],
        'e' => ['e', 'E', "\u{00E9}", "\u{00C9}", "e\u{0301}", "e\u{0300}", "\u{00CA}"],
        'o' => ['o', 'O', "\u{00F6}", "\u{00D6}", "o\u{0308}", "\u{00F4}"],
        'u' => ['u', 'U', "\u{00FC}", "\u{00DC}", "u\u{0308}"],
        'n' => ['n', 'N', "\u{00F1}", "\u{00D1}", "n\u{0303}"],
        'c' => ['c', 'C', "\u{00E7}", "\u{00C7}", "c\u{0327}"],
        ' ' => [' '],
        '-' => ['-'],
        '1' => ['1'],
    ];

    /**
     * Pick one surface variant at random for each normal-form character, then
     * re-pick independently to build a second string. Because every variant of
     * a class shares the class key as its normal form, both strings share the
     * same normal form by construction regardless of the chosen case or
     * diacritic surface.
     *
     * @param list<string> $normalChars sequence of equivalence-class keys
     */
    private function variantOf(array $normalChars, int $seed): string
    {
        $out = '';
        foreach ($normalChars as $i => $key) {
            $variants = self::EQUIVALENCE_CLASSES[$key];
            // Deterministic spread across variants driven by the shrinkable
            // integer seed, so counterexamples stay reproducible.
            $out .= $variants[($seed + $i * 31) % count($variants)];
        }

        return $out;
    }

    /**
     * Property 12 (Normalform): two strings built from the SAME sequence of
     * equivalence-class keys but with arbitrary, independently chosen case /
     * diacritic surface forms normalize to byte-identical output.
     */
    public function testSameNormalFormYieldsIdenticalNormalization(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\seq(Generator\elements(array_keys(self::EQUIVALENCE_CLASSES))),
            Generator\choose(0, 9999),
            Generator\choose(0, 9999)
        )->then(function (array $normalChars, int $seedA, int $seedB): void {
            $variantA = $this->variantOf($normalChars, $seedA);
            $variantB = $this->variantOf($normalChars, $seedB);

            $normA = TextNormalizer::normalize($variantA);
            $normB = TextNormalizer::normalize($variantB);

            self::assertSame(
                $normA,
                $normB,
                sprintf(
                    'Variants %s and %s share a normal form but normalized differently.',
                    bin2hex($variantA),
                    bin2hex($variantB)
                )
            );

            // The normalized output equals the canonical key sequence.
            self::assertSame(implode('', $normalChars), $normA);
        });
    }

    /**
     * The derived comparison basis (normalize() applied to each tokenized
     * search term) is likewise invariant under case / diacritic variation:
     * same normal form => same tokens => same normalized term list.
     */
    public function testTokenizationComparisonBasisIsInvariant(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\seq(Generator\elements(array_keys(self::EQUIVALENCE_CLASSES))),
            Generator\choose(0, 9999),
            Generator\choose(0, 9999)
        )->then(function (array $normalChars, int $seedA, int $seedB): void {
            $variantA = $this->variantOf($normalChars, $seedA);
            $variantB = $this->variantOf($normalChars, $seedB);

            $basisA = array_map(
                [TextNormalizer::class, 'normalize'],
                SearchTokenizer::tokenize($variantA)
            );
            $basisB = array_map(
                [TextNormalizer::class, 'normalize'],
                SearchTokenizer::tokenize($variantB)
            );

            self::assertSame($basisA, $basisB);
        });
    }
}
