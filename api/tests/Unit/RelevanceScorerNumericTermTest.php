<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\RelevanceScorer;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for {@see RelevanceScorer::score()} with purely numeric
 * search terms (Requirement 2.x scoring).
 *
 * A search term whose normal form is a numeric string (e.g. "2024") must be
 * scored like any other term. An earlier implementation collapsed distinct
 * terms via array KEYS, which PHP silently coerces from the numeric string
 * "2024" to the integer 2024; array_keys() then handed that int to the
 * string-typed matchType(), throwing a TypeError and turning a search for a
 * year/number into a 500 error. These tests pin the behaviour so the coercion
 * cannot regress.
 */
final class RelevanceScorerNumericTermTest extends TestCase
{
    public function testNumericTermStandaloneMatchScoresPositive(): void
    {
        $score = RelevanceScorer::score('published in 2024', 'author', ['2024']);

        self::assertGreaterThan(0.0, $score);
    }

    public function testNumericTermWithoutOccurrenceScoresZero(): void
    {
        $score = RelevanceScorer::score('hello world', 'author', ['2024']);

        self::assertSame(0.0, $score);
    }

    public function testMixedNumericAndTextTermsAreBothCounted(): void
    {
        $both = RelevanceScorer::score('the 2024 report', 'author', ['2024', 'report']);
        $one = RelevanceScorer::score('the 2024 report', 'author', ['2024']);

        // Matching two distinct terms outranks matching only one (2.4).
        self::assertGreaterThan($one, $both);
    }

    public function testRepeatedNumericTermIsDeduplicated(): void
    {
        $once = RelevanceScorer::score('2024 2024', 'x', ['2024']);
        $twice = RelevanceScorer::score('2024 2024', 'x', ['2024', '2024']);

        // A repeated query term must not inflate the score (one distinct term).
        self::assertSame($once, $twice);
    }

    public function testPurelyNumericTermDoesNotThrow(): void
    {
        // The exact input that previously raised a TypeError.
        $score = RelevanceScorer::score('a 1 b', 'c', ['1']);

        self::assertGreaterThan(0.0, $score);
    }
}
