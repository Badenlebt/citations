<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\LanguageCreator;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 40: Ungültige Sprachbezeichnung wird
 * abgewiesen.
 *
 * Für jede Bezeichnung, die nach Trim leer ist (leerer String oder reine
 * Whitespace-Folge), gilt: Das Anlegen wird mit MISSING_LANGUAGE_NAME
 * abgewiesen, und die Sprachmenge bleibt unverändert (keine Sprache wird
 * gespeichert). Code under test: {@see LanguageCreator::create()} gegen das
 * in-memory {@see InMemoryLanguageRepository}.
 *
 * The generator yields strings built only from characters that PHP's trim()
 * strips (plus the empty string), so every input is trim-empty by
 * construction. Each iteration checks the create path against a world that
 * already holds one ordinary language, proving the MISSING_LANGUAGE_NAME code
 * and the absence of any state change (12.4).
 *
 * Validates: Requirements 12.4
 */
final class LanguageCreatorInvalidNamePropertyTest extends PropertyTestCase
{
    /** Whitespace characters removed by PHP's trim() by default. */
    private const TRIM_WHITESPACE = [" ", "\t", "\n", "\r", "\0", "\x0B"];

    /** Snapshot every stored language so a "no state change" assertion is exact. */
    private function snapshot(InMemoryStore $store, InMemoryLanguageRepository $languages): array
    {
        $snapshot = [];
        foreach (array_keys($store->languages) as $id) {
            $snapshot[$id] = $languages->findById($id);
        }

        return $snapshot;
    }

    public function testTrimEmptyNameRejectsCreateWithoutStateChange(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\seq(Generator\choose(0, count(self::TRIM_WHITESPACE) - 1))
        )->then(function (array $picks): void {
            $name = '';
            foreach ($picks as $p) {
                $name .= self::TRIM_WHITESPACE[$p % count(self::TRIM_WHITESPACE)];
            }
            self::assertSame('', trim($name), 'generator must produce trim-empty name');

            $store = new InMemoryStore();
            $languages = new InMemoryLanguageRepository($store);
            $creator = new LanguageCreator($languages);

            // An existing ordinary language, to prove the stock stays unchanged.
            $existing = $creator->create('Deutsch');
            self::assertTrue($existing->isSuccess);
            $before = $this->snapshot($store, $languages);

            // (12.4) Create with trim-empty name: MISSING_LANGUAGE_NAME, nothing stored.
            $created = $creator->create($name);
            self::assertFalse($created->isSuccess);
            self::assertSame(ErrorCode::MISSING_LANGUAGE_NAME, $created->error);
            self::assertNull($created->id);
            self::assertSame(
                $before,
                $this->snapshot($store, $languages),
                'create with trim-empty name must not add a language or change any state'
            );
        });
    }
}
