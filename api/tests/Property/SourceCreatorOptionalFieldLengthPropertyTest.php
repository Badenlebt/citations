<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\SourceCreator;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 32: Längengrenzen der optionalen
 * Freitextfelder.
 *
 * Für jedes der optionalen Freitextfelder (Geburtsdatum, Sterbedatum,
 * Kurzbeschreibung) gilt: Ein Wert mit bis zu 500 Zeichen (nach Trim) wird beim
 * Anlegen akzeptiert und gespeichert; ein Wert mit mehr als 500 Zeichen (nach
 * Trim) wird mit INPUT_TOO_LONG abgewiesen, ohne dass eine Quelle entsteht.
 * Code under test: {@see SourceCreator::create()} gegen das in-memory
 * {@see InMemorySourceRepository}.
 *
 * The property drives, per iteration, which of the three optional fields is put
 * under test, and a trimmed length that straddles the 500-character boundary
 * (both at/under and over). A length within the bound must be accepted and read
 * back unchanged; a length over the bound must be rejected with INPUT_TOO_LONG
 * and leave the store unchanged.
 *
 * Validates: Requirements 10.3
 */
final class SourceCreatorOptionalFieldLengthPropertyTest extends PropertyTestCase
{
    /** The three optional free-text fields bounded at 500 characters (10.3). */
    private const FIELDS = ['birthDate', 'deathDate', 'description'];

    /**
     * Build the positional create() optional arguments with exactly the chosen
     * field set to the given value and the others null.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function optionalArgs(string $field, string $value): array
    {
        return [
            $field === 'birthDate' ? $value : null,
            $field === 'deathDate' ? $value : null,
            $field === 'description' ? $value : null,
        ];
    }

    public function testOptionalFieldLengthBoundaryCreate(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(0, count(self::FIELDS) - 1),
            // A trimmed length around the boundary: 1..510.
            Generator\choose(1, 510)
        )->then(function (int $fieldIdx, int $length): void {
            $field = self::FIELDS[$fieldIdx];
            // A single-byte ASCII filler so trimmed mb_strlen equals $length.
            $value = str_repeat('x', $length);

            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $creator = new SourceCreator($sources);

            [$birth, $death, $description] = $this->optionalArgs($field, $value);
            $before = array_keys($store->sources);

            $result = $creator->create('Autor', $birth, $death, $description);

            if ($length <= SourceCreator::MAX_OPTIONAL_FIELD_LENGTH) {
                // Within the bound: accepted and stored with the exact value.
                self::assertTrue(
                    $result->isSuccess,
                    sprintf('Field %s of length %d must be accepted.', $field, $length)
                );
                $record = $sources->findById($result->id);
                self::assertNotNull($record);
                self::assertSame($value, $record[$field], sprintf('Field %s must round-trip.', $field));
            } else {
                // Over the bound: INPUT_TOO_LONG and no new source.
                self::assertFalse(
                    $result->isSuccess,
                    sprintf('Field %s of length %d must be rejected.', $field, $length)
                );
                self::assertSame(ErrorCode::INPUT_TOO_LONG, $result->error);
                self::assertNull($result->id);
                self::assertSame(
                    $before,
                    array_keys($store->sources),
                    'an over-long optional field must not create a source'
                );
            }
        });
    }
}
