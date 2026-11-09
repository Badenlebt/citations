<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\QuoteMatcher;
use Citations\TextNormalizer;
use Eris\Generator;

/**
 * Feature: citations-management, Property 2: Treffer entstehen ausschließlich
 * durch Zitattext oder Quellenname.
 *
 * For any quote, a search term that occurs ONLY in excluded source fields
 * (birth date, death date, short description) and in NEITHER the quote text NOR
 * the source name must not make the quote a match. Those three fields are
 * structurally excluded from {@see QuoteMatcher::matches()}: they are never
 * parameters, so no value placed in them can reach the substring comparison.
 *
 * The test proves this in two complementary directions:
 *   1. A term embedded into birth date / death date / short description — but
 *      kept out of the quote text and source name — never matches, because the
 *      matcher only ever sees the quote text and source name.
 *   2. The SAME term DOES match once it is placed into the quote text or the
 *      source name, confirming the term itself is a genuine, findable needle
 *      and the negative outcome in (1) stems from field exclusion, not from an
 *      inert term.
 *
 * Validates: Requirements 1.3, 1.4
 */
final class QuoteMatcherFieldExclusionPropertyTest extends PropertyTestCase
{
    /**
     * Alphabet for the SEARCH TERM. Characters whose normal form is stable
     * under {@see TextNormalizer::normalize()} (lowercase ASCII / uppercase
     * folding to the same letters), drawn from the first half of the alphabet.
     *
     * @var list<string>
     */
    private const TERM_ALPHABET = [
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M',
    ];

    /**
     * Alphabet for the SEARCHABLE FIELDS (quote text, source name) in the
     * negative case. DISJOINT from {@see self::TERM_ALPHABET} after
     * normalization: digits plus the letters n–z, whose normal forms share no
     * character with the term alphabet's normal forms ({a..m}). This guarantees
     * by construction that the normalized term can never be a substring of
     * these fields, isolating field exclusion as the only cause of a non-match.
     *
     * @var list<string>
     */
    private const FIELD_ALPHABET = [
        'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
        'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
    ];

    /**
     * Build a string from an alphabet, driven by shrinkable integer seeds so
     * counterexamples stay reproducible.
     *
     * @param list<string> $alphabet
     * @param list<int>    $picks     one index selector per character
     */
    private function build(array $alphabet, array $picks): string
    {
        $out = '';
        foreach ($picks as $p) {
            $out .= $alphabet[$p % count($alphabet)];
        }

        return $out;
    }

    /**
     * Property 2 (negative direction): a term that lives only in the excluded
     * fields never produces a match. The excluded-field values are built and
     * asserted but deliberately NOT handed to the matcher — mirroring how the
     * matcher's signature structurally omits birth date, death date and short
     * description.
     */
    public function testTermOnlyInExcludedFieldsIsNeverMatched(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // search term: one mandatory char plus an optional tail (never empty)
            Generator\choose(0, count(self::TERM_ALPHABET) - 1),
            Generator\seq(Generator\choose(0, count(self::TERM_ALPHABET) - 1)),
            // quote text and source name, drawn from the disjoint field alphabet
            Generator\seq(Generator\choose(0, count(self::FIELD_ALPHABET) - 1)),
            Generator\seq(Generator\choose(0, count(self::FIELD_ALPHABET) - 1)),
            // padding placed around the term inside the excluded fields
            Generator\seq(Generator\choose(0, count(self::TERM_ALPHABET) - 1)),
            Generator\seq(Generator\choose(0, count(self::TERM_ALPHABET) - 1))
        )->then(function (
            int $termHead,
            array $termTail,
            array $quotePicks,
            array $sourcePicks,
            array $birthPad,
            array $deathPad
        ): void {
            $term = $this->build(self::TERM_ALPHABET, array_merge([$termHead], $termTail));
            $quoteText = $this->build(self::FIELD_ALPHABET, $quotePicks);
            $sourceName = $this->build(self::FIELD_ALPHABET, $sourcePicks);

            // Excluded source fields that DO contain the term. These are never
            // passed to the matcher, exactly as in production.
            $birthDate = $this->build(self::TERM_ALPHABET, $birthPad) . $term;
            $deathDate = $term . $this->build(self::TERM_ALPHABET, $deathPad);
            $shortDescription = 'x' . $term . 'y';

            $normalizedTerm = TextNormalizer::normalize($term);

            // Precondition of Property 2: the term is present in the excluded
            // fields but absent from both searchable fields (on the normalized
            // comparison basis). The disjoint alphabets make the absence hold
            // by construction; we assert it so a broken generator fails loudly.
            self::assertNotSame('', $normalizedTerm);
            self::assertStringContainsString($normalizedTerm, TextNormalizer::normalize($birthDate));
            self::assertStringContainsString($normalizedTerm, TextNormalizer::normalize($deathDate));
            self::assertStringContainsString($normalizedTerm, TextNormalizer::normalize($shortDescription));
            self::assertStringNotContainsString($normalizedTerm, TextNormalizer::normalize($quoteText));
            self::assertStringNotContainsString($normalizedTerm, TextNormalizer::normalize($sourceName));

            // The core assertion: a term confined to excluded fields yields no
            // match, because the matcher only inspects quote text and source
            // name.
            self::assertFalse(
                QuoteMatcher::matches($quoteText, $sourceName, [$term]),
                sprintf(
                    'Term %s present only in excluded fields (birth/death/description) '
                    . 'was matched (quote=%s, source=%s).',
                    $term,
                    $quoteText,
                    $sourceName
                )
            );
        });
    }

    /**
     * Property 2 (positive control): the SAME excluded-only term becomes a
     * match the moment it is placed into the quote text or the source name.
     * This rules out the trivial explanation that the term is simply
     * unmatchable, pinning the non-match in the negative case to field
     * exclusion alone.
     */
    public function testSameTermMatchesWhenMovedIntoASearchableField(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(0, count(self::TERM_ALPHABET) - 1),
            Generator\seq(Generator\choose(0, count(self::TERM_ALPHABET) - 1)),
            // base (non-matching) content for both searchable fields
            Generator\seq(Generator\choose(0, count(self::FIELD_ALPHABET) - 1)),
            Generator\seq(Generator\choose(0, count(self::FIELD_ALPHABET) - 1)),
            // which searchable field receives the term: 0 = quote text, 1 = source name
            Generator\choose(0, 1)
        )->then(function (
            int $termHead,
            array $termTail,
            array $quotePicks,
            array $sourcePicks,
            int $carrier
        ): void {
            $term = $this->build(self::TERM_ALPHABET, array_merge([$termHead], $termTail));
            $quoteText = $this->build(self::FIELD_ALPHABET, $quotePicks);
            $sourceName = $this->build(self::FIELD_ALPHABET, $sourcePicks);

            if ($carrier === 0) {
                $quoteText .= $term;
            } else {
                $sourceName .= $term;
            }

            self::assertTrue(
                QuoteMatcher::matches($quoteText, $sourceName, [$term]),
                sprintf(
                    'Term %s placed into the %s field was not matched (quote=%s, source=%s).',
                    $term,
                    $carrier === 0 ? 'quote-text' : 'source-name',
                    $quoteText,
                    $sourceName
                )
            );
        });
    }
}
