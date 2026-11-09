<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\SearchQueryValidator;
use Eris\Generator;

/**
 * Feature: citations-management, Property 45: Überlange Sucheingabe wird abgewiesen.
 *
 * Für jede Sucheingabe mit mehr als 200 Zeichen gilt: Die Such-Validierung
 * antwortet mit QUERY_TOO_LONG und führt keine Zerlegung in Suchbegriffe und
 * keine Ergebnisberechnung durch (design.md Property 45; "GET /api/search"
 * (1.13)). Die Länge wird in Unicode-Zeichen (mb_strlen) gemessen, nicht in
 * Bytes, und der Längen-Gate läuft vor dem Leerheits-Gate (1.9), weshalb eine
 * überlange, rein aus Whitespace bestehende Eingabe ebenfalls QUERY_TOO_LONG
 * ergibt.
 *
 * Validates: Requirements 1.13
 */
final class SearchQueryValidatorLengthRejectionPropertyTest extends PropertyTestCase
{
    /**
     * Single-byte (ASCII) content characters; each contributes exactly one
     * Unicode character, so mb_strlen equals the number of picks.
     *
     * @var list<string>
     */
    private const ASCII = [
        'a', 'B', 'c', 'Z', '1', '9', '_', '-', '.', '@', 'x', 'q', ' ', 'word',
    ];

    /**
     * Multibyte content characters (2–4 bytes each) that each count as a single
     * Unicode character. Used to prove length is measured per character, not
     * per byte: 201 of these is rejected even though its byte length is far
     * above 201.
     *
     * @var list<string>
     */
    private const MULTIBYTE = [
        "\u{00E9}", "\u{00FC}", "\u{00F1}", "\u{20AC}", "\u{4E2D}", "\u{1F600}",
    ];

    /**
     * ASCII whitespace characters; a string built solely from these tokenizes
     * to zero terms, so a within-limit such string would be MISSING_QUERY. Used
     * to prove the length gate wins over the emptiness gate when over-long.
     *
     * @var list<string>
     */
    private const WHITESPACE = [' ', "\t", "\n", "\r", "\x0B", "\f"];

    /**
     * Build a string of exactly $length Unicode characters by cycling the given
     * alphabet, driven by a shrinkable per-character selector sequence.
     *
     * @param list<string> $alphabet single-Unicode-character atoms
     * @param list<int>     $picks    per-character selectors (padded if short)
     */
    private function build(array $alphabet, array $picks, int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $sel = $picks[$i] ?? $i;
            $out .= $alphabet[$sel % count($alphabet)];
        }

        return $out;
    }

    /**
     * (a) Arbitrary ASCII content strings strictly longer than the limit
     * (length 201..400) are always rejected with QUERY_TOO_LONG.
     */
    public function testArbitraryOverLongInputIsRejectedAsTooLong(): void
    {
        // Note: self::ASCII contains a multi-character atom ('word'); build only
        // uses single-character atoms here to keep mb_strlen == $length. Replace
        // it with a single char for this length-precise generator.
        $singles = array_values(array_filter(self::ASCII, static fn (string $c): bool => mb_strlen($c, 'UTF-8') === 1));

        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(SearchQueryValidator::MAX_LENGTH + 1, SearchQueryValidator::MAX_LENGTH + 200),
            Generator\seq(Generator\choose(0, count($singles) - 1))
        )->then(function (int $length, array $picks) use ($singles): void {
            $query = $this->build($singles, $picks, $length);

            self::assertSame(
                $length,
                mb_strlen($query, 'UTF-8'),
                'Precondition: generated input must have the intended character length.'
            );
            self::assertGreaterThan(SearchQueryValidator::MAX_LENGTH, mb_strlen($query, 'UTF-8'));

            self::assertSame(
                ErrorCode::QUERY_TOO_LONG,
                SearchQueryValidator::validate($query),
                sprintf('Input of %d characters must be rejected as QUERY_TOO_LONG.', $length)
            );
        });
    }

    /**
     * (b) Boundary: a 201-character input is rejected with QUERY_TOO_LONG, while
     * a 200-character input (same alphabet) is NOT QUERY_TOO_LONG. This pins the
     * exact threshold MAX_LENGTH = 200.
     */
    public function testBoundaryAtMaxLength(): void
    {
        $singles = array_values(array_filter(self::ASCII, static fn (string $c): bool => mb_strlen($c, 'UTF-8') === 1));

        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\seq(Generator\choose(0, count($singles) - 1))
        )->then(function (array $picks) use ($singles): void {
            $atLimit = $this->build($singles, $picks, SearchQueryValidator::MAX_LENGTH);
            $overLimit = $this->build($singles, $picks, SearchQueryValidator::MAX_LENGTH + 1);

            self::assertSame(200, mb_strlen($atLimit, 'UTF-8'));
            self::assertSame(201, mb_strlen($overLimit, 'UTF-8'));

            self::assertSame(
                ErrorCode::QUERY_TOO_LONG,
                SearchQueryValidator::validate($overLimit),
                '201 characters must be rejected as QUERY_TOO_LONG.'
            );
            self::assertNotSame(
                ErrorCode::QUERY_TOO_LONG,
                SearchQueryValidator::validate($atLimit),
                '200 characters must NOT be QUERY_TOO_LONG (at the limit, not over it).'
            );
        });
    }

    /**
     * (c) Multibyte content is counted per Unicode character, not per byte: a
     * string of 201 multibyte characters (whose byte length is well above 201)
     * is rejected with QUERY_TOO_LONG, while 200 such characters is not.
     */
    public function testMultibyteLengthCountedByCharacterNotByte(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\seq(Generator\choose(0, count(self::MULTIBYTE) - 1))
        )->then(function (array $picks): void {
            $atLimit = $this->build(self::MULTIBYTE, $picks, SearchQueryValidator::MAX_LENGTH);
            $overLimit = $this->build(self::MULTIBYTE, $picks, SearchQueryValidator::MAX_LENGTH + 1);

            self::assertSame(200, mb_strlen($atLimit, 'UTF-8'));
            self::assertSame(201, mb_strlen($overLimit, 'UTF-8'));
            // Byte length must exceed the character count for genuine multibyte
            // input, otherwise the per-character claim would be vacuous.
            self::assertGreaterThan(
                mb_strlen($overLimit, 'UTF-8'),
                strlen($overLimit),
                'Precondition: multibyte input must have more bytes than characters.'
            );

            self::assertSame(
                ErrorCode::QUERY_TOO_LONG,
                SearchQueryValidator::validate($overLimit),
                '201 multibyte characters must be rejected as QUERY_TOO_LONG (counted by character).'
            );
            self::assertNotSame(
                ErrorCode::QUERY_TOO_LONG,
                SearchQueryValidator::validate($atLimit),
                '200 multibyte characters must NOT be QUERY_TOO_LONG despite a large byte size.'
            );
        });
    }

    /**
     * (d) Length precedence over emptiness: an over-long input built solely from
     * whitespace (which would tokenize to zero terms and thus be MISSING_QUERY
     * if it were within the limit) is still rejected as QUERY_TOO_LONG, because
     * the length gate runs before the emptiness gate.
     */
    public function testOverLongWhitespaceOnlyInputIsTooLongNotMissing(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(SearchQueryValidator::MAX_LENGTH + 1, SearchQueryValidator::MAX_LENGTH + 100),
            Generator\seq(Generator\choose(0, count(self::WHITESPACE) - 1))
        )->then(function (int $length, array $picks): void {
            $query = $this->build(self::WHITESPACE, $picks, $length);

            self::assertSame($length, mb_strlen($query, 'UTF-8'));
            self::assertGreaterThan(SearchQueryValidator::MAX_LENGTH, mb_strlen($query, 'UTF-8'));

            self::assertSame(
                ErrorCode::QUERY_TOO_LONG,
                SearchQueryValidator::validate($query),
                sprintf(
                    'Over-long whitespace-only input (len=%d) must be QUERY_TOO_LONG, not MISSING_QUERY (length precedence).',
                    $length
                )
            );
        });
    }
}
