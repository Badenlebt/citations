<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\LanguageCreator;
use Citations\TextNormalizer;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 39: Sprach-Anlegen-Round-Trip.
 *
 * Für jede gültige Sprachbezeichnung (getrimmt 1–100 Zeichen, normalisiert noch
 * nicht vorhanden) gilt: Nach dem Anlegen ist die Sprache in der Menge der
 * verfügbaren Sprachen enthalten — lesbar über
 * {@see InMemoryLanguageRepository::findAll()} bzw. ::findById() mit genau dem
 * getrimmten Namen. Code under test: {@see LanguageCreator::create()} gegen das
 * in-memory {@see InMemoryLanguageRepository} (design "`POST /api/languages`").
 *
 * The raw name is built so its trimmed form is non-empty and within the
 * 1–100 character bound, matching the create precondition; the fresh store
 * holds no language yet, so the normal form is guaranteed not to pre-exist.
 * The round trip re-reads the language by the id create() returned and asserts
 * it is part of the available set with exactly the trimmed display name.
 *
 * Validates: Requirements 12.3
 */
final class LanguageCreatorRoundTripPropertyTest extends PropertyTestCase
{
    /**
     * Raw language name whose trimmed form is a non-empty, length-valid value.
     * A guaranteed non-whitespace leading character keeps trim() from emptying
     * the string; arbitrary surrounding whitespace exercises the trimming
     * contract without pushing the trimmed length past the 100-char bound.
     */
    private function rawNameGenerator(): Generator
    {
        $coreChar = Generator\oneOf(
            Generator\map(static fn (int $c): string => chr($c), Generator\choose(33, 126)),
            Generator\elements("\u{00E9}", "\u{20AC}", "\u{00FC}", "\u{00C4}")
        );
        $pad = Generator\elements('', ' ', '  ', "\t", "\n", " \t ");

        return Generator\map(
            static function (array $parts): string {
                [$lead, $tail, $prefix, $suffix] = $parts;
                // Cap the core at 100 characters so the trimmed name stays
                // within the 1–100 bound and every iteration does a real create.
                $core = $lead . implode('', array_slice($tail, 0, 99));

                return $prefix . $core . $suffix;
            },
            Generator\tuple($coreChar, Generator\seq($coreChar), $pad, $pad)
        );
    }

    public function testCreatedLanguageIsInTheAvailableSet(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll($this->rawNameGenerator())->then(function (string $rawName): void {
            $trimmed = trim($rawName);

            // Precondition of Property 39: a valid trimmed name in 1..100.
            if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > LanguageCreator::MAX_NAME_LENGTH) {
                return;
            }

            $store = new InMemoryStore();
            $languages = new InMemoryLanguageRepository($store);
            $creator = new LanguageCreator($languages);

            $result = $creator->create($rawName);

            self::assertTrue(
                $result->isSuccess,
                sprintf('Create rejected valid language name (text=%s)', bin2hex($trimmed))
            );
            self::assertNotNull($result->id);

            // (12.3) Read back by the returned id with exactly the trimmed name.
            $record = $languages->findById($result->id);
            self::assertNotNull($record, 'Returned id does not resolve to a language.');
            self::assertSame($trimmed, $record['name'], 'Stored name differs from trimmed input.');

            // (12.3) The language is part of the available set (findAll()).
            $names = array_column($languages->findAll(), 'name', 'id');
            self::assertArrayHasKey($result->id, $names, 'Created language is not in the available set.');
            self::assertSame($trimmed, $names[$result->id]);

            // Its normal form is the one the repository indexes for uniqueness.
            self::assertTrue($languages->existsByNormalizedName(TextNormalizer::normalize($trimmed)));
        });
    }
}
