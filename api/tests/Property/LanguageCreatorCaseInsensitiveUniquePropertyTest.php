<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\LanguageCreator;
use Citations\TextNormalizer;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 41: Sprachen sind case-insensitiv
 * eindeutig.
 *
 * Für eine bereits gespeicherte Sprache gilt: Ein zweites Anlegen, dessen
 * getrimmte Bezeichnung dieselbe Normalform besitzt (Unterschiede nur in Groß-/
 * Kleinschreibung und/oder Diakritika), wird mit LANGUAGE_EXISTS abgewiesen,
 * und es wird keine zweite Sprache gespeichert. Code under test:
 * {@see LanguageCreator::create()} gegen das in-memory
 * {@see InMemoryLanguageRepository}.
 *
 * The generator builds a base name from letters that each carry a diacritical
 * and/or case variant. The variant string is derived letter-by-letter from the
 * base, each letter independently flipped to a form that shares its normal form
 * (upper/lower case and accented/plain), so by construction
 * normalize(base) === normalize(variant) while the display strings may differ.
 * After storing the base, creating the variant must be rejected with
 * LANGUAGE_EXISTS and must leave the stock at exactly one language (12.5).
 *
 * Validates: Requirements 12.5
 */
final class LanguageCreatorCaseInsensitiveUniquePropertyTest extends PropertyTestCase
{
    /** Letters paired with case/diacritics variants that share a normal form. */
    private const LETTER_VARIANTS = [
        ['a', 'A', "\u{00E0}", "\u{00C4}"],
        ['e', 'E', "\u{00E9}", "\u{00C8}"],
        ['u', 'U', "\u{00FC}", "\u{00DB}"],
        ['o', 'O', "\u{00F6}", "\u{00D4}"],
        ['n', 'N', "\u{00F1}", 'N'],
    ];

    public function testSecondCreateWithSameNormalFormIsRejected(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // One mandatory leading letter index plus an optional tail, so the
            // word is always non-empty by construction.
            Generator\choose(0, count(self::LETTER_VARIANTS) - 1),
            Generator\seq(Generator\choose(0, count(self::LETTER_VARIANTS) - 1)),
            // Per letter, which variant to use for base vs. for the second attempt.
            Generator\seq(Generator\choose(0, 3)),
            Generator\seq(Generator\choose(0, 3))
        )->then(function (int $head, array $tail, array $basePick, array $variantPick): void {
            // Keep the word within the 100-character bound (each letter is one
            // Unicode character) so the base name is always storable; the
            // uniqueness rule, not the length rule, is under test here.
            $tail = array_slice($tail, 0, 60);
            $letters = array_merge([$head], $tail);
            $base = '';
            $variant = '';
            foreach ($letters as $i => $letterIdx) {
                $forms = self::LETTER_VARIANTS[$letterIdx];
                $base .= $forms[($basePick[$i] ?? 0) % count($forms)];
                $variant .= $forms[($variantPick[$i] ?? 0) % count($forms)];
            }

            // Both share a normal form by construction (differ only by case/diacritics).
            self::assertSame(TextNormalizer::normalize($base), TextNormalizer::normalize($variant));

            $store = new InMemoryStore();
            $languages = new InMemoryLanguageRepository($store);
            $creator = new LanguageCreator($languages);

            $first = $creator->create($base);
            self::assertTrue($first->isSuccess, sprintf('base name must be storable (%s)', bin2hex($base)));

            $snapshot = [];
            foreach (array_keys($store->languages) as $id) {
                $snapshot[$id] = $languages->findById($id);
            }

            // (12.5) The second create with the same normal form is rejected.
            $second = $creator->create($variant);
            self::assertFalse($second->isSuccess);
            self::assertSame(ErrorCode::LANGUAGE_EXISTS, $second->error);
            self::assertNull($second->id);

            // No second language was stored; the stock is exactly unchanged.
            $after = [];
            foreach (array_keys($store->languages) as $id) {
                $after[$id] = $languages->findById($id);
            }
            self::assertSame($snapshot, $after, 'a duplicate normal form must not store a second language');
            self::assertCount(1, $languages->findAll(), 'exactly one language must remain in the stock');
        });
    }
}
