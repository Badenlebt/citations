<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\SourceCreator;
use Citations\TextNormalizer;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 43: Der Name "unbekannt" ist
 * reserviert.
 *
 * Für jede Schreibweise des Namens, die dieselbe Normalform wie "unbekannt"
 * besitzt (Groß-/Kleinschreibung, diakritische Varianten, umschließende
 * Leerzeichen), gilt: Das Anlegen einer neuen Quelle mit diesem Namen wird mit
 * UNKNOWN_SOURCE_RESERVED abgelehnt und ändert nichts, und ebenso das
 * Bearbeiten einer anderen, bereits vorhandenen Quelle auf diesen Namen; das
 * Bearbeiten der Unbekannt-Quelle selbst auf "unbekannt" bleibt erlaubt. Code
 * under test: {@see SourceCreator::create()} und {@see SourceCreator::edit()}
 * gegen das in-memory {@see InMemorySourceRepository} (Requirement 13.2).
 *
 * The property generates reserved-name variants by case-flipping the letters of
 * "unbekannt" and adding surrounding whitespace, confirming via
 * {@see TextNormalizer::normalize()} that each variant shares the reserved
 * normal form before exercising the create and edit rejections.
 *
 * Validates: Requirements 13.2
 */
final class SourceCreatorReservedNamePropertyTest extends PropertyTestCase
{
    public function testReservedNameIsRejectedOnCreateAndEdit(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // One case decision per letter of "unbekannt" (9 letters).
            Generator\vector(9, Generator\bool()),
            Generator\elements('', ' ', '  ', "\t", " \t ")
        )->then(function (array $upper, string $pad): void {
            $letters = str_split('unbekannt');
            $name = '';
            foreach ($letters as $i => $letter) {
                $name .= $upper[$i] ? strtoupper($letter) : $letter;
            }
            $variant = $pad . $name . $pad;

            // Sanity: the variant shares the reserved normal form.
            self::assertSame(
                TextNormalizer::normalize('unbekannt'),
                TextNormalizer::normalize(trim($variant)),
                'generator must produce a reserved-name variant'
            );

            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $creator = new SourceCreator($sources);

            // A pre-existing ordinary source we will try to rename to "unbekannt".
            $otherId = $creator->create('Seneca')->id;
            $sourcesBefore = $store->sources;

            // (13.2) Create a new source with a reserved name: rejected, no change.
            $created = $creator->create($variant);
            self::assertFalse($created->isSuccess, 'Creating a reserved-named source must be rejected.');
            self::assertSame(ErrorCode::UNKNOWN_SOURCE_RESERVED, $created->error);
            self::assertNull($created->id);
            self::assertSame($sourcesBefore, $store->sources, 'reserved-name create must change nothing');

            // (13.2) Edit another source to the reserved name: rejected, no change.
            $edited = $creator->edit($otherId, $variant);
            self::assertFalse($edited->isSuccess, 'Renaming another source to "unbekannt" must be rejected.');
            self::assertSame(ErrorCode::UNKNOWN_SOURCE_RESERVED, $edited->error);
            self::assertSame($sourcesBefore, $store->sources, 'reserved-name edit must change nothing');

            // Editing the Unbekannt-Quelle itself to keep the name stays allowed.
            $unknownId = $sources->findUnknown()['id'];
            $keep = $creator->edit($unknownId, $variant);
            self::assertTrue($keep->isSuccess, 'The Unbekannt-Quelle may keep its reserved name.');
            self::assertSame('unbekannt', TextNormalizer::normalize($sources->findById($unknownId)['name']));
        });
    }
}
