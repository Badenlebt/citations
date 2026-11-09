<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\RelevanceScorer;
use Citations\TextNormalizer;
use Eris\Generator;

/**
 * Feature: citations-management, Property 11: Treffer in Text und Quellenname
 * fließen gemeinsam ein.
 *
 * For any quote and any search input, the relevance value of a quote that gains
 * an additional match in the source name is greater than or equal to the value
 * of the same quote WITHOUT that source-name match, while the quote-text matches
 * stay unchanged. Because {@see RelevanceScorer::score()} folds every term's
 * contribution to the BEST match type across quote text AND source name
 * combined, strengthening or newly adding a source-name match can only raise or
 * keep a term's best type — the relevance never decreases (monotonic
 * non-decreasing), and a term present only in the source name still contributes.
 *
 * The property is exercised in two complementary directions:
 *   1. Monotonicity — enriching the source name with term occurrences never
 *      lowers the score, holding the quote text and the query fixed.
 *   2. Joint contribution — a quote matching term X in its text AND term Y only
 *      in its source name scores strictly higher than the same quote whose
 *      source name lacks Y (so it matches only X), proving source-name hits are
 *      counted into the same quote's single relevance value.
 *
 * Validates: Requirements 2.5
 */
final class RelevanceScorerCombinedFieldsPropertyTest extends PropertyTestCase
{
    /**
     * Alphabet of characters whose normal form is stable under substring
     * composition: lowercase ASCII letters and digits pass through
     * {@see TextNormalizer::normalize()} unchanged and uppercase letters only
     * fold to their lowercase counterpart. Avoiding whitespace, punctuation and
     * combining marks keeps every generated token a single "word" (letters /
     * digits only) whose normalized form is a literal substring of the field it
     * is embedded in.
     *
     * @var list<string>
     */
    private const ALPHABET = [
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
        'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'Z',
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
    ];

    /**
     * Build a word (letters/digits only, no boundaries) from the stable
     * alphabet, driven by shrinkable integer seeds for reproducible shrinking.
     *
     * @param list<int> $picks one alphabet index selector per character
     */
    private function build(array $picks): string
    {
        $out = '';
        foreach ($picks as $p) {
            $out .= self::ALPHABET[$p % count(self::ALPHABET)];
        }

        return $out;
    }

    /**
     * Property 11 (monotonicity): adding term occurrences to the source name,
     * with the quote text and the query unchanged, never decreases the score.
     *
     * We score the SAME quote text and query twice: once against a source name
     * that carries none of the terms (a single unrelated word) and once against
     * a source name that embeds some of the query terms as standalone words.
     * The enriched source name can only lift terms' best match type or newly
     * match terms, so its score must be >= the plain source's score.
     */
    public function testAddingSourceNameMatchNeverLowersScore(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // query terms: 1..4 words
            Generator\seq(Generator\choose(1, 8)),              // term A chars
            Generator\seq(Generator\choose(1, 8)),              // term B chars
            // quote text: a standalone word (may or may not be a term)
            Generator\seq(Generator\choose(1, 8)),              // quote word
            // which query terms to also drop into the source name (subset)
            Generator\choose(0, 3),                             // enrichment mask
            Generator\seq(Generator\choose(1, 8))               // unrelated source word
        )->then(function (
            array $termAPicks,
            array $termBPicks,
            array $quoteWordPicks,
            int $mask,
            array $unrelatedPicks
        ): void {
            // Non-empty words (choose(1, ...) guarantees at least one char).
            $termA = $this->build($termAPicks);
            $termB = $this->build($termBPicks);
            $quoteWord = $this->build($quoteWordPicks);
            $unrelated = $this->build($unrelatedPicks);

            $terms = [$termA, $termB];

            // Quote text is identical in both scorings.
            $quoteText = $quoteWord;

            // Baseline source name: a single unrelated word. Any match it still
            // produces (e.g. the unrelated word happening to contain a term) is
            // present in BOTH scorings, so it cancels out of the comparison.
            $plainSource = $unrelated;

            // Enriched source name: the baseline plus selected query terms as
            // standalone words (space-separated word boundaries).
            $extra = [];
            if (($mask & 1) !== 0) {
                $extra[] = $termA;
            }
            if (($mask & 2) !== 0) {
                $extra[] = $termB;
            }
            $enrichedSource = $extra === []
                ? $plainSource
                : $plainSource . ' ' . implode(' ', $extra);

            $scorePlain = RelevanceScorer::score($quoteText, $plainSource, $terms);
            $scoreEnriched = RelevanceScorer::score($quoteText, $enrichedSource, $terms);

            self::assertGreaterThanOrEqual(
                $scorePlain,
                $scoreEnriched,
                sprintf(
                    'Enriching the source name lowered the score: plain=%s (source=%s) '
                    . 'enriched=%s (source=%s), quote=%s, terms=[%s].',
                    $scorePlain,
                    $plainSource,
                    $scoreEnriched,
                    $enrichedSource,
                    $quoteText,
                    implode(', ', $terms)
                )
            );
        });
    }

    /**
     * Property 11 (joint contribution): a term that occurs ONLY in the source
     * name still raises the single relevance value of the quote.
     *
     * Setup: the query has two DISTINCT terms X and Y. Term X is a standalone
     * word in the quote text; term Y appears in NEITHER the quote text nor the
     * baseline source name. Scoring with a source name that adds Y as a
     * standalone word must be STRICTLY higher than scoring with a source name
     * that lacks Y, because the second quote now matches both X and Y while the
     * first matches only X — more distinct matched words dominates.
     */
    public function testTermOnlyInSourceNameIncreasesScore(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\seq(Generator\choose(1, 8)),  // X seed
            Generator\seq(Generator\choose(1, 8)),  // Y seed
            Generator\choose(0, count(self::ALPHABET) - 1) // disambiguator char
        )->then(function (array $xPicks, array $yPicks, int $disambig): void {
            $x = $this->build($xPicks);
            // Force Y to differ from X in normal form by giving it a distinct
            // leading character, so the two terms are genuinely distinct words.
            $y = $this->build(array_merge([($disambig + 1)], $yPicks)) . 'qzqz';

            $nx = TextNormalizer::normalize($x);
            $ny = TextNormalizer::normalize($y);

            // Skip degenerate draws where the terms collapse to the same word
            // or one contains the other (which would blur the distinct-term
            // count the property relies on).
            if ($nx === '' || $ny === '' || $nx === $ny) {
                return;
            }
            if (str_contains($nx, $ny) || str_contains($ny, $nx)) {
                return;
            }

            $terms = [$x, $y];

            // Quote text matches X (standalone word) but never Y.
            $quoteText = $x;

            // Baseline source: unrelated word, no X and no Y.
            $sourceWithoutY = 'unrelatedword';
            // Guard the baseline against an accidental Y substring.
            if (str_contains(TextNormalizer::normalize($sourceWithoutY), $ny)
                || str_contains(TextNormalizer::normalize($sourceWithoutY), $nx)) {
                return;
            }

            // Enriched source: adds Y as a standalone word.
            $sourceWithY = $sourceWithoutY . ' ' . $y;

            $scoreOnlyX = RelevanceScorer::score($quoteText, $sourceWithoutY, $terms);
            $scoreXandY = RelevanceScorer::score($quoteText, $sourceWithY, $terms);

            self::assertGreaterThan(
                $scoreOnlyX,
                $scoreXandY,
                sprintf(
                    'A term present only in the source name did not raise the score: '
                    . 'onlyX=%s (source=%s) vs X+Y=%s (source=%s), quote=%s, X=%s, Y=%s.',
                    $scoreOnlyX,
                    $sourceWithoutY,
                    $scoreXandY,
                    $sourceWithY,
                    $quoteText,
                    $x,
                    $y
                )
            );
        });
    }
}
