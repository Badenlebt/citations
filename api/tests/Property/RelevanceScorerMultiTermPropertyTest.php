<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\RelevanceScorer;
use Citations\TextNormalizer;
use Eris\Generator;

/**
 * Feature: citations-management, Property 10: Mehr getroffene Suchwörter
 * erhöhen den Relevanzwert.
 *
 * For any multi-word search input and two otherwise-alike quotes, a quote that
 * matches a strict SUPERSET of the search terms must receive a strictly higher
 * relevance value than a quote that matches only a strict SUBSET of those terms
 * (design Property 10, Requirements 2.4).
 *
 * This test deliberately drives the ADVERSARIAL case: the subset quote matches
 * every one of its (fewer) terms with the STRONGEST match type — each term
 * appears as a standalone whole word ({@see RelevanceScorer::STANDALONE}) —
 * while the superset quote matches every one of its (more) terms with the
 * WEAKEST match type — each term is only an infix inside a longer word
 * ({@see RelevanceScorer::INFIX}). If the distinct-term count did not dominate
 * the match-quality sum, the subset quote's richer match types could overtake
 * the superset quote; the property asserts it never does.
 *
 * Validates: Requirements 2.4
 */
final class RelevanceScorerMultiTermPropertyTest extends PropertyTestCase
{
    /**
     * Per-term unique marker letters. Each generated term starts with a
     * DISTINCT marker drawn from this pool, which guarantees the terms are
     * pairwise non-overlapping: no term can be a substring of another, and a
     * term can only ever occur where its own marker was placed. That isolation
     * is what makes the matched-term SETS controllable — the subset quote
     * matches exactly the subset, the superset quote exactly the superset —
     * so the test exercises Property 10 and not an accidental cross-match.
     *
     * The 'z' filler (see below) is intentionally excluded from this pool.
     *
     * @var list<string>
     */
    private const MARKERS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

    /**
     * Body alphabet for the part of a term after its unique marker. Lowercase
     * ASCII letters are unchanged by {@see TextNormalizer::normalize()} and all
     * count as word characters (\p{L}); 'z' is excluded because it is the word
     * filler/boundary helper used to force the infix match type.
     *
     * @var list<string>
     */
    private const BODY = [
        'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p',
        'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y',
    ];

    /**
     * Build a term: a mandatory unique marker followed by an optional body of
     * non-marker letters. Because the marker appears in no other term, the
     * resulting term is guaranteed not to be a substring of any sibling term.
     *
     * @param int       $markerIndex index into {@see self::MARKERS}
     * @param list<int> $bodyPicks   index selectors into {@see self::BODY}
     */
    private function term(int $markerIndex, array $bodyPicks): string
    {
        $out = self::MARKERS[$markerIndex % count(self::MARKERS)];
        foreach ($bodyPicks as $p) {
            $out .= self::BODY[$p % count(self::BODY)];
        }

        return $out;
    }

    /**
     * Property 10: a quote matching a strict superset of the search terms
     * outranks a quote matching only a strict subset — even when the subset is
     * matched with the strongest match type (standalone) and the superset with
     * the weakest (infix).
     */
    public function testStrictSupersetOfMatchedTermsOutranksSubset(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // A permutation seed to pick DISTINCT markers per term (1 subset
            // term .. up to all but one marker), plus the extra term's marker.
            Generator\choose(1, count(self::MARKERS) - 1), // subset size
            // one body spec per possible marker slot (subset terms + extra)
            Generator\vector(
                count(self::MARKERS),
                Generator\seq(Generator\choose(0, count(self::BODY) - 1))
            )
        )->then(function (int $subsetSize, array $bodies): void {
            // Assign the first $subsetSize markers to subset terms and the next
            // marker to the extra term. Distinct markers => pairwise-disjoint,
            // non-substring terms.
            $subsetTerms = [];
            for ($i = 0; $i < $subsetSize; $i++) {
                $subsetTerms[] = $this->term($i, $bodies[$i]);
            }
            $extra = $this->term($subsetSize, $bodies[$subsetSize]);

            $supersetTerms = array_merge($subsetTerms, [$extra]); // strict superset

            // Sanity: all terms are distinct in their normal form (distinct
            // markers guarantee it), so the scorer counts each separately.
            $normals = array_map(
                static fn (string $t): string => TextNormalizer::normalize($t),
                $supersetTerms
            );
            self::assertSame(
                count($supersetTerms),
                count(array_unique($normals)),
                'terms must be pairwise distinct for a strict-superset scenario'
            );

            // The full multi-word search input contains every term.
            $searchTerms = $supersetTerms;

            // Subset quote: every subset term as a STANDALONE whole word
            // (strongest match type), space-separated. The extra term's marker
            // appears nowhere here, so the subset quote matches EXACTLY the
            // subset — never the extra term.
            $subsetText = implode(' ', $subsetTerms);

            // Superset quote: every term (subset + extra) only as an INFIX
            // inside one long word. Each term is wrapped in 'z' filler on both
            // sides and concatenated, so no term sits at a word boundary — the
            // weakest match type. Because markers are unique, the only
            // occurrence of each term is this deliberate infix placement.
            $supersetWord = 'z';
            foreach ($supersetTerms as $t) {
                $supersetWord .= $t . 'z';
            }
            $supersetText = $supersetWord;

            // Identical source name for both quotes: the only difference is the
            // set of matched terms, not the source contribution (2.5).
            $sourceName = '';

            $subsetScore = RelevanceScorer::score($subsetText, $sourceName, $searchTerms);
            $supersetScore = RelevanceScorer::score($supersetText, $sourceName, $searchTerms);

            self::assertGreaterThan(
                $subsetScore,
                $supersetScore,
                sprintf(
                    'Superset match (%s) must outrank subset match (%s): '
                    . 'superset score %.1f !> subset score %.1f. '
                    . 'subsetText=%s, supersetText=%s, terms=%s',
                    implode(',', $supersetTerms),
                    implode(',', $subsetTerms),
                    $supersetScore,
                    $subsetScore,
                    $subsetText,
                    $supersetText,
                    implode(',', $searchTerms)
                )
            );
        });
    }
}
