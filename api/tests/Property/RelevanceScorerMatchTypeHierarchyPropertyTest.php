<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\RelevanceScorer;
use Citations\TextNormalizer;
use Eris\Generator;

/**
 * Feature: citations-management, Property 9: Trefferart-Hierarchie beim
 * Relevanz-Scoring.
 *
 * For any single search term and otherwise-alike quotes that all match THAT
 * SAME term, the match type strictly orders the relevance value:
 *
 *   standalone whole word  >  word-prefix  >  infix (inside a word)  >  0
 *
 * Because all three quotes match exactly one distinct term, the distinct-term
 * count component of the score is equal across them, so the ordering is decided
 * solely by the match-type weight (STANDALONE > PREFIX > INFIX). The test
 * constructs, for an arbitrary term, three quote texts embedding it as
 * (a) a standalone word, (b) a word prefix, (c) an infix, and asserts
 * score(a) > score(b) > score(c) > 0.
 *
 * Validates: Requirements 2.2, 2.3
 */
final class RelevanceScorerMatchTypeHierarchyPropertyTest extends PropertyTestCase
{
    /**
     * Alphabet of characters whose normal form is stable under substring
     * composition: lowercase ASCII letters are unchanged by
     * {@see TextNormalizer::normalize()}, and uppercase letters only fold to
     * their lowercase counterpart. All are Unicode word characters (\p{L}), so
     * adjoining two of them keeps them inside a single word after
     * normalization — exactly what the prefix/infix constructions need.
     *
     * Digits are intentionally excluded so that a generated term is never a
     * purely numeric string; the hierarchy under test is independent of the
     * character class, and letters alone exercise standalone / prefix / infix
     * fully without entangling the test with numeric-string edge cases.
     *
     * The wrapper character {@see self::WRAPPER} is deliberately NOT part of
     * this alphabet, so a generated term can never contain it.
     *
     * @var list<string>
     */
    private const WORD_ALPHABET = [
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
        'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'y', 'z',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'Z',
    ];

    /**
     * A word character used to pad the term into a longer word for the prefix
     * and infix constructions. It is excluded from {@see self::WORD_ALPHABET},
     * so the generated term never contains it. This guarantees the term cannot
     * accidentally occur starting at a wrapper position, which keeps the prefix
     * word a strict prefix match and the infix word a strict infix match
     * (never degrading to a standalone occurrence). Its normal form is itself.
     */
    private const WRAPPER = 'x';

    /**
     * Build a word (string of word characters) from a shrinkable list of index
     * seeds so counterexamples stay reproducible.
     *
     * @param list<int> $picks one alphabet index selector per character
     */
    private function word(array $picks): string
    {
        $out = '';
        foreach ($picks as $p) {
            $out .= self::WORD_ALPHABET[$p % count(self::WORD_ALPHABET)];
        }

        return $out;
    }

    /**
     * Property 9: for one arbitrary term, a standalone-word match scores
     * strictly higher than a word-prefix match, which scores strictly higher
     * than an infix match, and all three are positive.
     *
     * The three quotes are otherwise alike: they match the SAME single term and
     * are scored against the SAME (unrelated) source name, so only the match
     * type of the term inside the quote text differs.
     */
    public function testStandaloneOutranksPrefixOutranksInfix(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // the term: one mandatory char plus an optional tail => never empty
            Generator\choose(0, count(self::WORD_ALPHABET) - 1),
            Generator\seq(Generator\choose(0, count(self::WORD_ALPHABET) - 1)),
            // how many wrapper characters pad the term into a longer word
            // (>= 1), varying the surrounding word length
            Generator\choose(1, 4)
        )->then(function (
            int $termHead,
            array $termTail,
            int $pad
        ): void {
            $term = $this->word(array_merge([$termHead], $termTail));
            // A run of wrapper characters; the wrapper is never part of the
            // term, so the term can only occur at the positions we place it.
            $body = str_repeat(self::WRAPPER, $pad);

            // Surrounding scaffold words built ONLY from the wrapper character.
            // Because the wrapper is never part of the term, these scaffold
            // words can never contain a stray occurrence of the term — so the
            // ONLY occurrence of the term in each quote is the one we place.
            $lead = str_repeat(self::WRAPPER, 3);
            $trail = str_repeat(self::WRAPPER, 3);

            // (a) standalone: the term stands alone as its own word, delimited
            //     by spaces (word boundaries) on both sides.
            $standaloneQuote = $lead . ' ' . $term . ' ' . $trail;

            // (b) word-prefix: the term starts a longer word (boundary before,
            //     a wrapper word character right after), never standalone.
            $prefixQuote = $lead . ' ' . $term . $body . ' ' . $trail;

            // (c) infix: the term sits inside a word with a wrapper word
            //     character on BOTH sides, so it is neither standalone nor at a
            //     word start — the term cannot begin at a wrapper position.
            $infixQuote = $lead . ' ' . $body . $term . $body . ' ' . $trail;

            // Identical source name for all three so the quotes are otherwise
            // alike. It is built ONLY from the wrapper character, which is never
            // part of the term, so the source can never match — the term's best
            // match type is therefore decided solely by the quote text, which
            // is exactly what the hierarchy (standalone > prefix > infix) tests.
            $sourceName = str_repeat(self::WRAPPER, 5);
            $terms = [$term];

            $scoreStandalone = RelevanceScorer::score($standaloneQuote, $sourceName, $terms);
            $scorePrefix = RelevanceScorer::score($prefixQuote, $sourceName, $terms);
            $scoreInfix = RelevanceScorer::score($infixQuote, $sourceName, $terms);

            // All three genuinely match the single term on the normalized basis.
            $normalizedTerm = TextNormalizer::normalize($term);
            self::assertStringContainsString(
                $normalizedTerm,
                TextNormalizer::normalize($infixQuote),
                'Infix construction must actually contain the term.'
            );

            self::assertGreaterThan(
                0.0,
                $scoreInfix,
                sprintf('Infix match must be positive (term=%s, body=%s).', $term, $body)
            );
            self::assertGreaterThan(
                $scorePrefix,
                $scoreStandalone,
                sprintf(
                    'Standalone (%.3f) must outrank prefix (%.3f): term=%s, body=%s.',
                    $scoreStandalone,
                    $scorePrefix,
                    $term,
                    $body
                )
            );
            self::assertGreaterThan(
                $scoreInfix,
                $scorePrefix,
                sprintf(
                    'Prefix (%.3f) must outrank infix (%.3f): term=%s, body=%s.',
                    $scorePrefix,
                    $scoreInfix,
                    $term,
                    $body
                )
            );
        });
    }
}
