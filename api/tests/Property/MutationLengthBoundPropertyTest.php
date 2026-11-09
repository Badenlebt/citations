<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\ErrorCode;
use Citations\LanguageCreator;
use Citations\QuoteCreator;
use Citations\SourceCreator;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Eris\Generator;

/**
 * Feature: citations-management, Property 46: Überlange längenbegrenzte
 * Eingaben werden ohne Zustandsänderung abgewiesen.
 *
 * Für jede schreibende Operation mit einem längenbegrenzten Feld, dessen Wert
 * nach Trim die zulässige Maximallänge überschreitet, gilt: Die Operation wird
 * mit INPUT_TOO_LONG abgewiesen, und der gesamte Datenstand bleibt unverändert
 * (kein neuer Datensatz, keine Änderung eines bestehenden). Abgedeckt werden
 * alle längenbegrenzten Felder der Mutationsschicht: Zitattext (5000, via
 * {@see QuoteCreator::create()} und ::edit()), Quellenname/optionale
 * Freitextfelder/Link-URL (200/500/2000, via {@see SourceCreator::create()} und
 * ::edit()) sowie Sprachbezeichnung (100, via {@see LanguageCreator::create()}).
 *
 * The generator drives which bounded field/operation is under test (CASES) and
 * an overshoot that always pushes the trimmed length past that case's bound.
 * The world is seeded with one existing quote, one existing ordinary source and
 * one existing language so the edit paths have a real target and the "no state
 * change" snapshot is non-trivial. Every case asserts INPUT_TOO_LONG and that
 * the full store (sources, languages, quotes) is byte-for-byte unchanged.
 *
 * Validates: Requirements 8.6, 9.6, 10.7, 12.6
 */
final class MutationLengthBoundPropertyTest extends PropertyTestCase
{
    /**
     * Each case: [label, bound, operation]. The operation receives the three
     * creators plus the seeded ids and an over-long value, and returns the
     * mutation result object (carrying ->error / ->isSuccess).
     *
     * @var list<array{0: string, 1: int}>
     */
    private const CASES = [
        ['quote.create.text', QuoteCreator::MAX_TEXT_LENGTH],
        ['quote.edit.text', QuoteCreator::MAX_TEXT_LENGTH],
        ['source.create.name', SourceCreator::MAX_NAME_LENGTH],
        ['source.edit.name', SourceCreator::MAX_NAME_LENGTH],
        ['source.create.optional', SourceCreator::MAX_OPTIONAL_FIELD_LENGTH],
        ['source.edit.optional', SourceCreator::MAX_OPTIONAL_FIELD_LENGTH],
        ['source.create.linkUrl', SourceCreator::MAX_LINK_URL_LENGTH],
        ['source.edit.linkUrl', SourceCreator::MAX_LINK_URL_LENGTH],
        ['language.create.name', LanguageCreator::MAX_NAME_LENGTH],
    ];

    /** A full, exact snapshot of every stored row across all repositories. */
    private function snapshot(InMemoryStore $store): array
    {
        return [
            'sources' => $store->sources,
            'languages' => $store->languages,
            'quotes' => $store->quotes,
        ];
    }

    public function testOverLongBoundedFieldRejectedWithoutStateChange(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(0, count(self::CASES) - 1),
            // Overshoot beyond the bound: 1..50 extra characters after trim.
            Generator\choose(1, 50)
        )->then(function (int $caseIdx, int $overshoot): void {
            [$label, $bound] = self::CASES[$caseIdx];

            $store = new InMemoryStore();
            $quotes = new InMemoryQuoteRepository($store);
            $sources = new InMemorySourceRepository($store);
            $languages = new InMemoryLanguageRepository($store);

            $quoteCreator = new QuoteCreator($quotes, $sources, $languages);
            $sourceCreator = new SourceCreator($sources);
            $languageCreator = new LanguageCreator($languages);

            // Seed one existing language, source and quote as mutation targets.
            $languageId = $languages->insert('Deutsch', 'deutsch');
            $sourceId = $sources->insert('Seneca');
            $quoteId = $quotes->insert('Bestehendes Zitat', $sourceId, $languageId);

            // A single-byte ASCII filler so trimmed mb_strlen == bound + overshoot.
            $long = str_repeat('x', $bound + $overshoot);
            // An over-long link URL still carries the http(s):// prefix; the
            // length check precedes the prefix check, so INPUT_TOO_LONG wins.
            $longUrl = 'https://' . $long;

            $before = $this->snapshot($store);

            $result = match ($label) {
                'quote.create.text' => $quoteCreator->create($long, $sourceId, $languageId),
                'quote.edit.text' => $quoteCreator->edit($quoteId, $long, $sourceId, $languageId),
                'source.create.name' => $sourceCreator->create($long),
                'source.edit.name' => $sourceCreator->edit($sourceId, $long),
                'source.create.optional' => $sourceCreator->create('Autor', $long),
                'source.edit.optional' => $sourceCreator->edit($sourceId, 'Autor', $long),
                'source.create.linkUrl' => $sourceCreator->create('Autor', null, null, null, $longUrl),
                'source.edit.linkUrl' => $sourceCreator->edit($sourceId, 'Autor', null, null, null, $longUrl),
                'language.create.name' => $languageCreator->create($long),
            };

            // The over-long bounded field is rejected with INPUT_TOO_LONG.
            self::assertFalse(
                $result->isSuccess,
                sprintf('%s: over-long input (bound=%d) must be rejected', $label, $bound)
            );
            self::assertSame(
                ErrorCode::INPUT_TOO_LONG,
                $result->error,
                sprintf('%s: expected INPUT_TOO_LONG', $label)
            );
            self::assertNull($result->id);

            // The entire data store is unchanged: no new row, no edited row.
            self::assertSame(
                $before,
                $this->snapshot($store),
                sprintf('%s: an over-long bounded field must leave the whole store unchanged', $label)
            );
        });
    }
}
