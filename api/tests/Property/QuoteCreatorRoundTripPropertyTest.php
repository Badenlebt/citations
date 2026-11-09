<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\QuoteCreator;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 23: Zitat-Anlegen-Round-Trip.
 *
 * Für jede gültige Eingabe (getrimmter Text 1–5000 Zeichen, vorhandene Quelle,
 * vorhandene Sprache) gilt: Nach dem Anlegen liefert das Lesen des
 * zurückgegebenen Zitats genau die angelegten Werte (getrimmter Text, Quelle,
 * Sprache). Code under test: {@see QuoteCreator::create()} against the
 * in-memory repository doubles (design "`POST /api/quotes`").
 *
 * The raw text is built so its trimmed form is non-empty and within the
 * 1–5000 character bound, matching the create precondition; the referenced
 * source and language are seeded into the shared {@see InMemoryStore} before
 * the call so both references exist. The round trip then re-reads the quote by
 * the id {@see QuoteCreator::create()} returned and asserts the stored text,
 * source and language are exactly the created ones.
 *
 * Validates: Requirements 8.1
 */
final class QuoteCreatorRoundTripPropertyTest extends PropertyTestCase
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
     * Property 23: create, then read back by the returned id, yields exactly
     * the trimmed text, the chosen source and the chosen language.
     */
    public function testCreatedQuoteReadsBackWithExactValues(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->rawTextGenerator(),
            // Source/language display names only affect seeding, not the
            // round-trip identity; keep them simple and distinct per run.
            Generator\string(),
            Generator\string()
        )->then(function (string $rawText, string $sourceName, string $languageName): void {
            $trimmed = trim($rawText);

            // Precondition of Property 23: a valid trimmed text in 1..5000.
            if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > QuoteCreator::MAX_TEXT_LENGTH) {
                return;
            }

            $store = new InMemoryStore();
            $quotes = new InMemoryQuoteRepository($store);
            $sources = new InMemorySourceRepository($store);
            $languages = new InMemoryLanguageRepository($store);

            // Seed an existing source and an existing language to reference.
            $sourceId = $sources->insert($sourceName === '' ? 'Quelle' : $sourceName);
            $languageId = $languages->insert(
                $languageName === '' ? 'Sprache' : $languageName,
                $languageName === '' ? 'sprache' : $languageName
            );

            $creator = new QuoteCreator($quotes, $sources, $languages);

            $result = $creator->create($rawText, $sourceId, $languageId);

            self::assertTrue(
                $result->isSuccess,
                sprintf(
                    'Create rejected valid input (error=%s, text=%s)',
                    $result->error?->name ?? 'none',
                    bin2hex($trimmed)
                )
            );
            self::assertNotNull($result->id);

            // Read back the exact quote the create returned.
            $record = $quotes->findById($result->id);
            self::assertNotNull($record, 'Returned id does not resolve to a quote.');

            // Round trip: stored text is the trimmed text, and source and
            // language are exactly the ones supplied on create (8.1).
            self::assertSame($trimmed, $record['text'], 'Stored text differs from trimmed input.');
            self::assertSame($sourceId, $record['source']['id'], 'Stored source differs from chosen source.');
            self::assertSame($languageId, $record['languageId'], 'Stored language differs from chosen language.');
        });
    }
}
