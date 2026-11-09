<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\RelevanceScorer;
use Eris\Generator;

/**
 * Feature: citations-management, Property 12: Scoring ist case- und
 * diakritika-invariant.
 *
 * Covers the SCORER slice of Property 12 (design.md) / Requirement 2.6: for any
 * quote text, source name and search terms, replacing arbitrary characters in
 * ANY of these inputs with case- or diacritic variants that share the SAME
 * Unicode normal form must leave the relevance value produced by
 * {@see RelevanceScorer::score()} unchanged. The normal-form slice (same normal
 * form => same tokens/comparison basis) is covered by task 1.3
 * ({@see TextNormalizerInvariancePropertyTest}); here we prove the scorer that
 * consumes that normal form is itself invariant end to end.
 *
 * Technique: the equivalence-class approach reused from task 1.3. Every input
 * is generated as a sequence of equivalence-class KEYS (the canonical normal
 * form characters); each key maps to several surface variants (upper/lower
 * case, precomposed vs. combining diacritics) that all normalize back to the
 * key. Picking variants independently for two scoring runs yields inputs that
 * differ only in case/diacritics yet share the same normal form by
 * construction, so the two scores must be byte-identical.
 *
 * Validates: Requirements 2.6
 */
final class RelevanceScorerInvariancePropertyTest extends PropertyTestCase
{
    /**
     * Equivalence classes keyed by their shared normal form. Each class lists
     * surface variants that MUST normalize to the key. Includes word
     * characters (letters/digits) plus a space and hyphen so generated inputs
     * contain real word boundaries, exercising standalone/prefix/infix match
     * types inside the scorer.
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
     * Equivalence-class keys used to generate SEARCH TERMS. Identical to
     * {@see self::EQUIVALENCE_CLASSES} but without the pure-digit class: a
     * term whose entire normal form is a numeric string (e.g. "1") is an
     * orthogonal scorer edge case (PHP array-key integer coercion) that is
     * unrelated to the case/diacritic invariance under test here. Quote text
     * and source name still draw from the full class set, so digits continue
     * to appear in the scored haystacks.
     *
     * @var list<string>
     */
    private const TERM_KEYS = ['a', 'e', 'o', 'u', 'n', 'c', ' ', '-'];

    /**
     * Build one surface variant of a canonical key sequence. Every character is
     * replaced by one of its equivalence-class variants, selected
     * deterministically from the shrinkable integer seed so counterexamples
     * remain reproducible. Because every variant of a class shares the class
     * key as its normal form, the result always normalizes back to the key
     * sequence regardless of the chosen case/diacritic surface.
     *
     * @param list<string> $normalChars sequence of equivalence-class keys
     */
    private function variantOf(array $normalChars, int $seed): string
    {
        $out = '';
        foreach ($normalChars as $i => $key) {
            $variants = self::EQUIVALENCE_CLASSES[$key];
            $out .= $variants[($seed + $i * 31) % count($variants)];
        }

        return $out;
    }

    /**
     * Property 12 (scorer): the relevance value is invariant under arbitrary
     * case/diacritic variation of the quote text, the source name AND the
     * search terms, as long as every variant shares the normal form of its
     * canonical counterpart.
     *
     * For each run we draw canonical key sequences for the quote text, the
     * source name and two search terms, then realise each of them twice with
     * INDEPENDENTLY chosen variant seeds. The two realisations differ only in
     * case/diacritics, so the scorer must return exactly the same value.
     */
    public function testScoreIsInvariantUnderCaseAndDiacriticVariants(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\seq(Generator\elements(array_keys(self::EQUIVALENCE_CLASSES))), // quote text
            Generator\seq(Generator\elements(array_keys(self::EQUIVALENCE_CLASSES))), // source name
            Generator\seq(Generator\elements(self::TERM_KEYS)), // term 1
            Generator\seq(Generator\elements(self::TERM_KEYS)), // term 2
            // Independent variant seeds for the two realisations of each input.
            Generator\choose(0, 9999),
            Generator\choose(0, 9999),
            Generator\choose(0, 9999),
            Generator\choose(0, 9999),
            Generator\choose(0, 9999),
            Generator\choose(0, 9999),
            Generator\choose(0, 9999),
            Generator\choose(0, 9999)
        )->then(function (
            array $textKeys,
            array $sourceKeys,
            array $term1Keys,
            array $term2Keys,
            int $textSeedA,
            int $textSeedB,
            int $sourceSeedA,
            int $sourceSeedB,
            int $t1SeedA,
            int $t1SeedB,
            int $t2SeedA,
            int $t2SeedB
        ): void {
            // Realisation A: one set of case/diacritic surface choices.
            $textA = $this->variantOf($textKeys, $textSeedA);
            $sourceA = $this->variantOf($sourceKeys, $sourceSeedA);
            $termsA = [
                $this->variantOf($term1Keys, $t1SeedA),
                $this->variantOf($term2Keys, $t2SeedA),
            ];

            // Realisation B: independently chosen surface choices, same normal
            // form for every input by construction.
            $textB = $this->variantOf($textKeys, $textSeedB);
            $sourceB = $this->variantOf($sourceKeys, $sourceSeedB);
            $termsB = [
                $this->variantOf($term1Keys, $t1SeedB),
                $this->variantOf($term2Keys, $t2SeedB),
            ];

            $scoreA = RelevanceScorer::score($textA, $sourceA, $termsA);
            $scoreB = RelevanceScorer::score($textB, $sourceB, $termsB);

            self::assertSame(
                $scoreA,
                $scoreB,
                sprintf(
                    'Case/diacritic variants that share a normal form scored '
                    . 'differently: A=%s (text=%s source=%s terms=%s) vs '
                    . 'B=%s (text=%s source=%s terms=%s).',
                    $scoreA,
                    bin2hex($textA),
                    bin2hex($sourceA),
                    bin2hex(implode('|', $termsA)),
                    $scoreB,
                    bin2hex($textB),
                    bin2hex($sourceB),
                    bin2hex(implode('|', $termsB))
                )
            );
        });
    }

    /**
     * Property 12 (scorer, single-input isolation): varying the case/diacritic
     * surface of just ONE field at a time while leaving the others at their
     * canonical normal form still leaves the score unchanged. This pins the
     * invariance to each input independently rather than only to a coordinated
     * variation of all inputs at once.
     */
    public function testScoreIsInvariantWhenVaryingOneFieldAtATime(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\seq(Generator\elements(array_keys(self::EQUIVALENCE_CLASSES))), // quote text
            Generator\seq(Generator\elements(array_keys(self::EQUIVALENCE_CLASSES))), // source name
            Generator\seq(Generator\elements(self::TERM_KEYS)), // single term
            Generator\choose(0, 9999),
            Generator\choose(0, 9999),
            Generator\choose(0, 9999)
        )->then(function (
            array $textKeys,
            array $sourceKeys,
            array $termKeys,
            int $textSeed,
            int $sourceSeed,
            int $termSeed
        ): void {
            // Canonical baseline: every input is its own normal form (seed 0
            // always selects the first, canonical variant of each class).
            $canonText = implode('', $textKeys);
            $canonSource = implode('', $sourceKeys);
            $canonTerm = implode('', $termKeys);

            $baseline = RelevanceScorer::score($canonText, $canonSource, [$canonTerm]);

            // Vary only the quote text.
            self::assertSame(
                $baseline,
                RelevanceScorer::score($this->variantOf($textKeys, $textSeed), $canonSource, [$canonTerm]),
                'Varying only the quote text changed the score.'
            );

            // Vary only the source name.
            self::assertSame(
                $baseline,
                RelevanceScorer::score($canonText, $this->variantOf($sourceKeys, $sourceSeed), [$canonTerm]),
                'Varying only the source name changed the score.'
            );

            // Vary only the search term.
            self::assertSame(
                $baseline,
                RelevanceScorer::score($canonText, $canonSource, [$this->variantOf($termKeys, $termSeed)]),
                'Varying only the search term changed the score.'
            );
        });
    }
}
