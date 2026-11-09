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
 * Feature: citations-management, Property 24: Ungültiger Zitattext verhindert
 * jede Zustandsänderung.
 *
 * Für jeden Zitattext, der nach Trim leer ist, gilt sowohl beim Anlegen als
 * auch beim Bearbeiten: Die Operation wird mit MISSING_TEXT abgewiesen; beim
 * Anlegen entsteht kein neues Zitat, beim Bearbeiten bleibt das bestehende
 * Zitat vollständig unverändert.
 *
 * The generator produces strings composed only of characters that PHP's trim()
 * strips (space, tab, newline, carriage return, NUL, vertical tab), including
 * the empty string, so every generated input is trim-empty by construction.
 * Each iteration is checked against both {@see QuoteCreator::create()} and
 * {@see QuoteCreator::edit()} on a world that already holds one quote, proving
 * the rejection code and the absence of any state change on both paths.
 *
 * Validates: Requirements 8.2, 9.2
 */
final class QuoteMutationEmptyTextNoStateChangePropertyTest extends PropertyTestCase
{
    /**
     * The whitespace characters removed by PHP's trim() by default. A text
     * built from only these (or an empty text) is always empty after trimming.
     *
     * @var list<string>
     */
    private const TRIM_WHITESPACE = [" ", "\t", "\n", "\r", "\0", "\x0B"];

    /** Snapshot every stored quote so a "no state change" assertion is exact. */
    private function snapshot(InMemoryStore $store, InMemoryQuoteRepository $quotes): array
    {
        $snapshot = [];
        foreach (array_keys($store->quotes) as $id) {
            $snapshot[$id] = $quotes->findById($id);
        }

        return $snapshot;
    }

    public function testTrimEmptyTextRejectsCreateAndEditWithoutStateChange(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // A sequence of whitespace-only picks; the empty sequence yields "".
            Generator\seq(Generator\choose(0, count(self::TRIM_WHITESPACE) - 1))
        )->then(function (array $picks): void {
            // Build a text that is guaranteed empty after trim().
            $text = '';
            foreach ($picks as $p) {
                $text .= self::TRIM_WHITESPACE[$p % count(self::TRIM_WHITESPACE)];
            }
            self::assertSame('', trim($text), 'generator must produce trim-empty text');

            // A fresh world with one existing, valid quote to edit.
            $store = new InMemoryStore();
            $quotes = new InMemoryQuoteRepository($store);
            $sources = new InMemorySourceRepository($store);
            $languages = new InMemoryLanguageRepository($store);
            $sourceId = $sources->insert('Seneca', []);
            $languageId = $languages->insert('Deutsch', 'deutsch');
            $creator = new QuoteCreator($quotes, $sources, $languages);

            $existing = $creator->create('Ein gültiges Zitat', $sourceId, $languageId);
            self::assertTrue($existing->isSuccess);
            $before = $this->snapshot($store, $quotes);

            // (8.2) Create with trim-empty text: MISSING_TEXT, no new quote.
            $created = $creator->create($text, $sourceId, $languageId);
            self::assertFalse($created->isSuccess);
            self::assertSame(ErrorCode::MISSING_TEXT, $created->error);
            self::assertNull($created->id);
            self::assertSame(
                $before,
                $this->snapshot($store, $quotes),
                'create with trim-empty text must not add a quote or change any state'
            );

            // (9.2) Edit the existing quote with trim-empty text: MISSING_TEXT,
            // the stored quote stays fully unchanged.
            $edited = $creator->edit($existing->id, $text, $sourceId, $languageId);
            self::assertFalse($edited->isSuccess);
            self::assertSame(ErrorCode::MISSING_TEXT, $edited->error);
            self::assertNull($edited->id);
            self::assertSame(
                $before,
                $this->snapshot($store, $quotes),
                'edit with trim-empty text must leave the existing quote fully unchanged'
            );
        });
    }
}
