<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\QuoteCreator;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 27: Zitat-Bearbeiten-Round-Trip.
 *
 * Für jedes vorhandene Zitat und jede gültige Eingabe (getrimmter Text 1–5000
 * Zeichen, vorhandene Quelle, vorhandene Sprache) gilt: Nach dem Bearbeiten
 * liefert das Lesen des bearbeiteten Zitats genau die geänderten Werte
 * (getrimmter Text, Quelle, Sprache). Code under test:
 * {@see QuoteCreator::edit()} against the in-memory repository doubles (design
 * "`PUT /api/quotes/{id}`").
 *
 * A quote is first seeded via {@see QuoteCreator::create()} with distinct
 * original values; several additional valid sources and languages are seeded
 * so the edit can switch to a different source/language. The raw new text is
 * built so its trimmed form is non-empty and within the 1–5000 character
 * bound, matching the edit precondition. The round trip then re-reads the
 * quote by its id and asserts the stored text, source and language are exactly
 * the newly edited ones (9.1).
 *
 * Validates: Requirements 9.1
 */
final class QuoteCreatorEditRoundTripPropertyTest extends PropertyTestCase
{
    /**
     * Raw quote text whose trimmed form is a non-empty, length-valid value.
     * A guaranteed non-whitespace leading character keeps trim() from emptying
     * the string, and arbitrary surrounding whitespace exercises the trimming
     * contract without ever pushing the trimmed length past the bound.
     */
    private function rawTextGenerator(): Generator
    {
        // Printable, non-whitespace core characters (ASCII 33..126) plus a few
        // multibyte characters, so trimming and the mb_strlen bound are both
        // exercised. Whitespace is added only as surrounding padding.
        $coreChar = Generator\oneOf(
            Generator\map(static fn (int $c): string => chr($c), Generator\choose(33, 126)),
            Generator\elements("\u{00E9}", "\u{20AC}", "\u{1F600}", "\u{00FC}")
        );
        $pad = Generator\elements('', ' ', '  ', "\t", "\n", " \t ");

        return Generator\map(
            static function (array $parts): string {
                [$lead, $tail, $prefix, $suffix] = $parts;
                $core = $lead . implode('', $tail);

                return $prefix . $core . $suffix;
            },
            Generator\tuple($coreChar, Generator\seq($coreChar), $pad, $pad)
        );
    }

    /**
     * Property 27: edit an existing quote with new valid values, then read it
     * back by its id; the stored text equals the new trimmed text and the
     * source and language are exactly the newly chosen ones.
     */
    public function testEditedQuoteReadsBackWithExactNewValues(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->rawTextGenerator(),
            // Index into the extra seeded sources/languages, so the edit can
            // target a different source and language than the original.
            Generator\choose(0, 2),
            Generator\choose(0, 2)
        )->then(function (string $rawNewText, int $sourceIndex, int $languageIndex): void {
            $trimmed = trim($rawNewText);

            // Precondition of Property 27: a valid trimmed text in 1..5000.
            if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > QuoteCreator::MAX_TEXT_LENGTH) {
                return;
            }

            $store = new InMemoryStore();
            $quotes = new InMemoryQuoteRepository($store);
            $sources = new InMemorySourceRepository($store);
            $languages = new InMemoryLanguageRepository($store);
            $creator = new QuoteCreator($quotes, $sources, $languages);

            // Seed several valid sources and languages the edit may target.
            $sourceIds = [
                $sources->insert('Quelle A'),
                $sources->insert('Quelle B'),
                $sources->insert('Quelle C'),
            ];
            $languageIds = [
                $languages->insert('Deutsch', 'deutsch'),
                $languages->insert('English', 'english'),
                $languages->insert('Français', 'francais'),
            ];

            // Seed an existing quote with distinct original values.
            $created = $creator->create('Ursprünglicher Text', $sourceIds[0], $languageIds[0]);
            self::assertTrue($created->isSuccess, 'Seeding the original quote failed.');
            self::assertNotNull($created->id);
            $quoteId = $created->id;

            $newSourceId = $sourceIds[$sourceIndex];
            $newLanguageId = $languageIds[$languageIndex];

            // Edit the existing quote with new valid values.
            $result = $creator->edit($quoteId, $rawNewText, $newSourceId, $newLanguageId);

            self::assertTrue(
                $result->isSuccess,
                sprintf(
                    'Edit rejected valid input (error=%s, text=%s)',
                    $result->error?->name ?? 'none',
                    bin2hex($trimmed)
                )
            );
            self::assertSame($quoteId, $result->id, 'Edit returned a different id than the edited quote.');

            // Read back the edited quote.
            $record = $quotes->findById($quoteId);
            self::assertNotNull($record, 'Edited id does not resolve to a quote.');

            // Round trip: the stored values are exactly the newly edited ones (9.1).
            self::assertSame($trimmed, $record['text'], 'Stored text differs from new trimmed input.');
            self::assertSame($newSourceId, $record['source']['id'], 'Stored source differs from new chosen source.');
            self::assertSame($newLanguageId, $record['languageId'], 'Stored language differs from new chosen language.');
        });
    }
}
