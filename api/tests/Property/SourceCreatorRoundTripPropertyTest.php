<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\SourceCreator;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 30: Quellen-Anlegen- und
 * Bearbeiten-Round-Trip.
 *
 * Für jede gültige Eingabe (getrimmter Name 1–200 Zeichen, optionale
 * Freitextfelder 0–500, gültige Link-URL 0–2000) gilt: Nach dem Anlegen liefert
 * das Lesen der zurückgegebenen Quelle genau die angelegten (getrimmten) Werte,
 * und ein anschließendes Bearbeiten derselben Quelle mit neuen gültigen Werten
 * liefert beim erneuten Lesen genau die bearbeiteten Werte. Code under test:
 * {@see SourceCreator::create()} und {@see SourceCreator::edit()} gegen das
 * in-memory {@see InMemorySourceRepository} (design "`POST /api/sources`",
 * "`PUT /api/sources/{id}`").
 *
 * Both the create and the edit input are generated with surrounding whitespace
 * so the trim contract is exercised; optional fields and the link URL are
 * generated within their bounds, with the link URL carrying a required
 * http(s):// prefix. The stored values after each operation are read back via
 * {@see InMemorySourceRepository::findById()} and compared to the trimmed
 * inputs (optional empty values normalize to null).
 *
 * Validates: Requirements 10.1, 10.6
 */
final class SourceCreatorRoundTripPropertyTest extends PropertyTestCase
{
    /** A non-reserved, trimmed-non-empty name within 1..200 characters. */
    private function nameGenerator(): Generator
    {
        return Generator\map(
            // Prefix "Q-" guarantees a non-whitespace, non-"unbekannt" core.
            static fn (string $s): string => '  Q-' . $s . '  ',
            Generator\string()
        );
    }

    /** An optional free-text field within 0..500 characters, padded. */
    private function optionalGenerator(): Generator
    {
        return Generator\map(
            static fn (string $s): string => ' ' . $s . ' ',
            Generator\string()
        );
    }

    /** A valid link URL carrying the required http(s):// prefix, within bound. */
    private function linkUrlGenerator(): Generator
    {
        return Generator\map(
            static fn (array $p): string => ($p[0] ? 'https://' : 'http://') . 'example.test/' . $p[1],
            Generator\tuple(Generator\bool(), Generator\string())
        );
    }

    public function testCreateThenEditReadsBackExactValues(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->nameGenerator(),
            $this->optionalGenerator(),
            $this->linkUrlGenerator(),
            $this->nameGenerator(),
            $this->optionalGenerator(),
            $this->linkUrlGenerator()
        )->then(function (
            string $name1,
            string $desc1,
            string $link1,
            string $name2,
            string $desc2,
            string $link2
        ): void {
            // Keep inputs within the create/edit length bounds; skip otherwise.
            foreach ([$name1 => 200, $name2 => 200, $desc1 => 500, $desc2 => 500, $link1 => 2000, $link2 => 2000] as $value => $max) {
                if (mb_strlen(trim((string) $value), 'UTF-8') > $max) {
                    return;
                }
            }

            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $creator = new SourceCreator($sources);

            // (10.1) Create and read back exactly the trimmed values.
            $created = $creator->create($name1, null, null, $desc1, $link1);
            self::assertTrue(
                $created->isSuccess,
                sprintf('Create rejected valid input (error=%s).', $created->error?->name ?? 'none')
            );
            self::assertNotNull($created->id);

            $recordA = $sources->findById($created->id);
            self::assertNotNull($recordA, 'Returned id does not resolve to a source.');
            self::assertSame(trim($name1), $recordA['name'], 'Stored name differs from trimmed create input.');
            self::assertSame(
                trim($desc1) === '' ? null : trim($desc1),
                $recordA['description'],
                'Stored description differs from trimmed create input.'
            );
            self::assertSame(trim($link1), $recordA['linkUrl'], 'Stored link URL differs from create input.');

            // (10.6) Edit the same source and read back exactly the new values.
            $edited = $creator->edit($created->id, $name2, null, null, $desc2, $link2);
            self::assertTrue(
                $edited->isSuccess,
                sprintf('Edit rejected valid input (error=%s).', $edited->error?->name ?? 'none')
            );
            self::assertSame($created->id, $edited->id, 'Edit must return the same source id.');

            $recordB = $sources->findById($created->id);
            self::assertNotNull($recordB, 'Source vanished after edit.');
            self::assertSame(trim($name2), $recordB['name'], 'Stored name differs from trimmed edit input.');
            self::assertSame(
                trim($desc2) === '' ? null : trim($desc2),
                $recordB['description'],
                'Stored description differs from trimmed edit input.'
            );
            self::assertSame(trim($link2), $recordB['linkUrl'], 'Stored link URL differs from edit input.');
        });
    }
}
