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
 * Feature: citations-management, Property 44: Zitat ohne Quellenauswahl erhält
 * die Unbekannt-Quelle.
 *
 * Für jede Anlege-Operation eines Zitats ohne ausgewählte Quelle (sourceId ===
 * null) gilt: Das gespeicherte Zitat ist der stets vorhandenen Unbekannt-Quelle
 * zugeordnet (Requirement 13.3). Code under test:
 * {@see QuoteCreator::create()} with a null source, resolved against the
 * in-memory repositories whose {@see InMemoryStore} pre-seeds the
 * Unbekannt-Quelle (id 1, name 'unbekannt', isUnknown = true).
 *
 * The property drives arbitrary valid quote texts (non-empty after trim, within
 * the 5000-character bound) over a store holding the Unbekannt-Quelle plus
 * additional, distractor sources, and asserts that a create without a chosen
 * source always lands on the Unbekannt-Quelle — never on one of the other
 * sources — and is actually persisted.
 *
 * Validates: Requirements 13.3
 */
final class QuoteCreatorUnknownSourceFallbackPropertyTest extends PropertyTestCase
{
    /**
     * Property 44: creating a quote with a null sourceId assigns it to the
     * Unbekannt-Quelle and stores it.
     */
    public function testCreateWithoutSourceAssignsUnknownSource(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // Arbitrary raw text; padded with surrounding whitespace so the
            // trim path is exercised and the stored text is the trimmed value.
            Generator\string(),
            // Number of additional (non-unknown) distractor sources seeded
            // alongside the Unbekannt-Quelle, so the fallback must actively
            // pick the unknown one rather than defaulting to "the only source".
            Generator\choose(0, 4)
        )->then(function (string $rawText, int $extraSources): void {
            // A guaranteed non-empty, length-valid core so the create is not
            // rejected for MISSING_TEXT/INPUT_TOO_LONG; the generated text is
            // appended as free variation and the whole thing is padded so trim
            // has something to remove.
            $text = '  quote-' . $rawText . '  ';

            $store = new InMemoryStore();
            $sources = new InMemorySourceRepository($store);
            $quotes = new InMemoryQuoteRepository($store);
            $languages = new InMemoryLanguageRepository($store);

            // One real language the quote can reference.
            $languageId = $languages->insert('Deutsch', 'deutsch');

            // Distractor sources with ids different from the Unbekannt-Quelle.
            for ($i = 0; $i < $extraSources; $i++) {
                $sources->insert('Autor ' . $i);
            }

            $unknownId = $sources->findUnknown()['id'];

            $creator = new QuoteCreator($quotes, $sources, $languages);

            // Create WITHOUT a chosen source (13.3).
            $result = $creator->create($text, null, $languageId);

            self::assertTrue(
                $result->isSuccess,
                sprintf('Create without a source must succeed (error: %s).', $result->error?->name ?? 'none')
            );
            self::assertNotNull($result->id);

            // The stored quote exists and is assigned to the Unbekannt-Quelle.
            $stored = $quotes->findById($result->id);
            self::assertNotNull($stored, 'The quote must actually be persisted.');
            self::assertSame(
                $unknownId,
                $stored['source']['id'],
                'A quote created without a source must be assigned to the Unbekannt-Quelle.'
            );
            self::assertSame(
                'unbekannt',
                $stored['source']['name'],
                'The resolved source must be the Unbekannt-Quelle named "unbekannt".'
            );
            // And the resolved source is the one flagged as unknown.
            self::assertTrue(
                $sources->findById($stored['source']['id'])['isUnknown'],
                'The assigned source must carry the Unbekannt-Quelle flag.'
            );
        });
    }
}
