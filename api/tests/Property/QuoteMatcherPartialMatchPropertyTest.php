<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\QuoteMatcher;
use Citations\TextNormalizer;
use Eris\Generator;

/**
 * Feature: citations-management, Property 1: Teiltreffer in Zitattext oder
 * Quellenname werden gefunden.
 *
 * For any quote store and any valid search input, every quote whose normalized
 * quote text OR whose normalized source name contains at least one normalized
 * search term (a partial / substring match) as a substring is part of the
 * overall match set. This test drives {@see QuoteMatcher::matches()} so that an
 * embedded substring is placed into EITHER the quote text OR the source name,
 * exercising both searchable fields (1.1 quote text, 1.2 source name).
 *
 * Validates: Requirements 1.1, 1.2
 */
final class QuoteMatcherPartialMatchPropertyTest extends PropertyTestCase
{
    /**
     * Alphabet of characters whose normal form is stable under substring
     * composition: lowercase ASCII letters and digits are unchanged by
     * {@see TextNormalizer::normalize()}, and uppercase letters only fold to
     * their lowercase counterpart. Avoiding whitespace and combining marks
     * keeps the substring relationship intact after normalization, so a term
     * that is a literal substring of a field is also a substring of that
     * field's normal form.
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
     * Build a string of the given length from the stable alphabet, driven by a
     * shrinkable integer seed so counterexamples stay reproducible.
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
     * Property 1: a search term that is a substring of the quote text OR of the
     * source name makes the quote a match. The term is a non-empty slice taken
     * from whichever field it is embedded in, so the partial-match contract
     * must hold regardless of case folding applied by normalization.
     */
    public function testEmbeddedSubstringInEitherFieldIsFound(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // prefix/suffix padding around the embedded term in the carrier field
            Generator\seq(Generator\choose(0, count(self::ALPHABET) - 1)),
            Generator\seq(Generator\choose(0, count(self::ALPHABET) - 1)),
            // the term itself: one mandatory char plus an optional tail, so it
            // is always a real (non-empty) needle
            Generator\choose(0, count(self::ALPHABET) - 1),
            Generator\seq(Generator\choose(0, count(self::ALPHABET) - 1)),
            // the other, unrelated field
            Generator\seq(Generator\choose(0, count(self::ALPHABET) - 1)),
            // extra noise terms that should not affect the positive outcome
            Generator\seq(Generator\choose(0, count(self::ALPHABET) - 1)),
            // which field carries the term: 0 = quote text, 1 = source name
            Generator\choose(0, 1)
        )->then(function (
            array $prefix,
            array $suffix,
            int $termHead,
            array $termTail,
            array $otherPicks,
            array $noisePicks,
            int $carrier
        ): void {
            $term = $this->build(array_merge([$termHead], $termTail));
            $carrierField = $this->build($prefix) . $term . $this->build($suffix);
            $otherField = $this->build($otherPicks);
            $noise = $this->build($noisePicks);

            if ($carrier === 0) {
                $quoteText = $carrierField;
                $sourceName = $otherField;
            } else {
                $quoteText = $otherField;
                $sourceName = $carrierField;
            }

            // Sanity: with this alphabet the term is a substring of the
            // carrier's normal form, which is exactly the precondition of
            // Property 1.
            self::assertStringContainsString(
                TextNormalizer::normalize($term),
                TextNormalizer::normalize($carrierField)
            );

            // OR-combined term list: the matching term plus unrelated noise.
            $terms = $noise === '' ? [$term] : [$noise, $term];

            self::assertTrue(
                QuoteMatcher::matches($quoteText, $sourceName, $terms),
                sprintf(
                    'Term %s embedded in %s field was not found (quote=%s, source=%s).',
                    $term,
                    $carrier === 0 ? 'quote-text' : 'source-name',
                    $quoteText,
                    $sourceName
                )
            );
        });
    }

    /**
     * Property 1 under case/diacritic variation: a term that matches the
     * normalized form of a field is still found when the stored field uses
     * precomposed accents or mixed case. This confirms the partial-match
     * contract holds on the normalized comparison basis, not the raw bytes.
     */
    public function testPartialMatchHoldsAcrossCaseAndDiacritics(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\elements('Cafe', 'Caf' . "\u{00E9}", 'CAF' . "\u{00C9}"),
            Generator\elements('cafe', 'CAFE', 'Caf' . "\u{00E9}"),
            Generator\choose(0, 1)
        )->then(function (string $accentedField, string $termVariant, int $carrier): void {
            // The term shares the normal form "cafe" with the field, so the
            // normalized needle is a substring of the normalized haystack.
            $padded = 'x' . $accentedField . 'y';

            if ($carrier === 0) {
                $quoteText = $padded;
                $sourceName = 'unrelated';
            } else {
                $quoteText = 'unrelated';
                $sourceName = $padded;
            }

            self::assertTrue(
                QuoteMatcher::matches($quoteText, $sourceName, [$termVariant]),
                sprintf(
                    'Term %s did not match field %s on normalized basis.',
                    bin2hex($termVariant),
                    bin2hex($padded)
                )
            );
        });
    }
}
