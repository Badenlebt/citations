<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\SearchQueryValidator;
use Eris\Generator;

/**
 * Feature: citations-management, Property 6: Leere oder reine Whitespace-Sucheingabe
 * wird abgewiesen.
 *
 * Für jede Sucheingabe, die nach Entfernen von Leerzeichen leer ist
 * (einschließlich des leeren Strings und beliebiger reiner Whitespace-Zeichenketten),
 * antwortet die Such-Validierung mit MISSING_QUERY und führt keine
 * Ergebnisberechnung durch (design.md Property 6; "GET /api/search" (1.9)).
 *
 * The validator applies the length gate (1.13) BEFORE the emptiness gate (1.9),
 * so this property is scoped to inputs within the 200-character limit: there,
 * any empty or whitespace-only input must resolve to MISSING_QUERY. Whitespace
 * is detected exactly as {@see \Citations\SearchTokenizer::tokenize()} splits
 * (runs of Unicode \s with the /u modifier), so the generators below mix ASCII
 * spacing (space, tab, newline, CR, vertical tab, form feed) with Unicode
 * whitespace (NBSP, en/em space, line/paragraph separator, ideographic space)
 * that the tokenizer likewise treats as separators. A positive control proves
 * the rejection is specific to blank input: a non-whitespace input within the
 * length limit is never rejected as MISSING_QUERY.
 *
 * Validates: Requirements 1.9
 */
final class SearchQueryValidatorEmptyRejectionPropertyTest extends PropertyTestCase
{
    /**
     * Whitespace characters that PCRE's \s (with /u) treats as separators, so
     * {@see \Citations\SearchTokenizer::tokenize()} yields zero terms for any
     * string built solely from them. Covers ASCII spacing plus representative
     * Unicode whitespace (non-breaking space, en/em space, line and paragraph
     * separators, ideographic space).
     *
     * @var list<string>
     */
    private const WHITESPACE = [
        ' ', "\t", "\n", "\r", "\x0B", "\f",
        "\u{00A0}", "\u{2002}", "\u{2003}", "\u{2028}", "\u{2029}", "\u{3000}",
    ];

    /**
     * Non-whitespace characters for the positive-control input, so the built
     * string always tokenizes to at least one term and must NOT be rejected as
     * MISSING_QUERY.
     *
     * @var list<string>
     */
    private const CONTENT = [
        'a', 'B', 'c', 'Z', '1', '9', '_', '-', '.', '@', 'x', "\u{00E9}", "\u{00FC}",
    ];

    /**
     * Build a whitespace-only string of the requested length from the
     * whitespace alphabet, driven by a shrinkable per-character selector.
     *
     * @param list<int> $picks one whitespace-index selector per character
     */
    private function buildWhitespace(array $picks): string
    {
        $out = '';
        foreach ($picks as $p) {
            $out .= self::WHITESPACE[$p % count(self::WHITESPACE)];
        }

        return $out;
    }

    /**
     * Property 6: every empty or whitespace-only search input within the length
     * limit is rejected with MISSING_QUERY. The generator produces strings of
     * length 0..200 made exclusively of (ASCII and Unicode) whitespace; the
     * empty case is covered by the empty picks sequence. Because the tokenizer
     * splits on these separators, each such input yields zero terms, so the
     * emptiness gate must fire.
     */
    public function testEmptyOrWhitespaceOnlyInputIsRejectedAsMissingQuery(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\seq(Generator\choose(0, count(self::WHITESPACE) - 1))
        )->then(function (array $picks): void {
            // Keep the input within the 200-character length limit so the
            // emptiness gate (1.9) is the deciding rule, not the length gate
            // (1.13) which runs first.
            $picks = array_slice($picks, 0, SearchQueryValidator::MAX_LENGTH);
            $query = $this->buildWhitespace($picks);

            self::assertLessThanOrEqual(
                SearchQueryValidator::MAX_LENGTH,
                mb_strlen($query, 'UTF-8'),
                'Precondition: generated whitespace input must stay within the length limit.'
            );

            self::assertSame(
                ErrorCode::MISSING_QUERY,
                SearchQueryValidator::validate($query),
                sprintf(
                    'Whitespace-only input (len=%d, hex=%s) must be rejected as MISSING_QUERY.',
                    mb_strlen($query, 'UTF-8'),
                    bin2hex($query)
                )
            );
        });
    }

    /**
     * The empty string specifically — the degenerate case of "after removing
     * whitespace, nothing remains" — is rejected as MISSING_QUERY.
     */
    public function testEmptyStringIsRejectedAsMissingQuery(): void
    {
        self::assertSame(ErrorCode::MISSING_QUERY, SearchQueryValidator::validate(''));
    }

    /**
     * Positive control: an input that contains at least one non-whitespace
     * character (optionally surrounded by arbitrary whitespace) tokenizes to a
     * non-empty term list and therefore must NOT be rejected as MISSING_QUERY.
     * This keeps the property from being vacuously satisfied by a validator
     * that rejects everything.
     */
    public function testNonWhitespaceInputIsNotRejectedAsMissingQuery(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(0, count(self::CONTENT) - 1),
            Generator\seq(Generator\choose(0, count(self::CONTENT) - 1)),
            Generator\seq(Generator\choose(0, count(self::WHITESPACE) - 1)),
            Generator\seq(Generator\choose(0, count(self::WHITESPACE) - 1))
        )->then(function (int $head, array $contentPicks, array $leadWs, array $trailWs): void {
            $core = self::CONTENT[$head % count(self::CONTENT)];
            foreach ($contentPicks as $p) {
                $core .= self::CONTENT[$p % count(self::CONTENT)];
            }

            $query = $this->buildWhitespace($leadWs) . $core . $this->buildWhitespace($trailWs);
            // Stay within the length limit so MISSING_QUERY is the only possible
            // blank-rejection and QUERY_TOO_LONG does not interfere.
            $query = mb_substr($query, 0, SearchQueryValidator::MAX_LENGTH, 'UTF-8');

            // Trimming may have cut away the content tail; re-ensure at least one
            // non-whitespace character survives, otherwise this case is not a
            // valid positive control and is skipped by reasserting the core.
            if (preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) === []) {
                $query = $core;
            }

            self::assertNotSame(
                ErrorCode::MISSING_QUERY,
                SearchQueryValidator::validate($query),
                sprintf(
                    'Input with a non-whitespace term (hex=%s) must not be MISSING_QUERY.',
                    bin2hex($query)
                )
            );
        });
    }
}
