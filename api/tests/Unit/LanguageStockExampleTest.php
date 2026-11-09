<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\Http\LanguageHandler;
use Citations\Http\Request;
use Citations\LanguageCreator;
use Citations\QuoteCreator;
use Citations\TextNormalizer;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Worked examples documenting the two Requirement-level invariants of the
 * Sprachbestand (language stock), observed end-to-end through the mutation and
 * repository layers over the in-memory doubles.
 *
 * These are deliberately illustrative (not exhaustive) companions to the
 * exhaustive {@see LanguageHandlerTest} / {@see \Citations\Tests\...} suites:
 * they pin down the data-model shape rather than the full validation grid.
 *
 *  - 12.1 "exactly one language per quote": a quote carries exactly one
 *    languageId (not zero, not many), and that single association is what the
 *    repository read exposes; re-pointing the quote's language leaves it with
 *    exactly one (the new) language.
 *  - 12.2 "dynamically extendable stock, not fixed to DE/EN": starting from an
 *    empty stock the available languages grow at runtime and may be any name —
 *    including non-DE/EN scripts — each immediately listable and usable as a
 *    quote's language.
 */
final class LanguageStockExampleTest extends TestCase
{
    private InMemoryStore $store;
    private InMemoryLanguageRepository $languages;
    private InMemorySourceRepository $sources;
    private InMemoryQuoteRepository $quotes;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->languages = new InMemoryLanguageRepository($this->store);
        $this->sources = new InMemorySourceRepository($this->store);
        $this->quotes = new InMemoryQuoteRepository($this->store);
    }

    private function seedLanguage(string $name): int
    {
        return $this->languages->insert($name, TextNormalizer::normalize($name));
    }

    private function quoteCreator(): QuoteCreator
    {
        return new QuoteCreator($this->quotes, $this->sources, $this->languages);
    }

    private function languageHandler(): LanguageHandler
    {
        return new LanguageHandler(new LanguageCreator($this->languages), $this->languages);
    }

    // --- 12.1 exactly one language per quote ---------------------------------

    /**
     * A created quote is stored with exactly the one chosen languageId, and the
     * joined read exposes that single language's id and name — there is one
     * language association per quote, never zero or many (Requirement 12.1).
     */
    public function testCreatedQuoteHasExactlyOneChosenLanguage(): void
    {
        $deutsch = $this->seedLanguage('Deutsch');
        $englisch = $this->seedLanguage('Englisch');

        $result = $this->quoteCreator()->create('Ein Zitat.', null, $deutsch);
        self::assertTrue($result->isSuccess);

        $stored = $this->quotes->findById($result->id);
        self::assertNotNull($stored);

        // Exactly the chosen language is associated — not the other seeded one.
        self::assertSame($deutsch, $stored['languageId']);
        self::assertSame('Deutsch', $stored['languageName']);
        self::assertNotSame($englisch, $stored['languageId']);

        // The read shape carries a single scalar languageId/languageName pair:
        // there is no multi-language field on a quote.
        self::assertIsInt($stored['languageId']);
        self::assertIsString($stored['languageName']);
    }

    /**
     * Re-pointing a quote's language via edit() replaces the one association
     * with exactly one new language — the quote still references a single
     * language, now the newly chosen one (Requirement 12.1).
     */
    public function testEditingQuoteLanguageLeavesExactlyOneLanguage(): void
    {
        $deutsch = $this->seedLanguage('Deutsch');
        $latina = $this->seedLanguage('Latina');

        $creator = $this->quoteCreator();
        $created = $creator->create('Carpe diem.', null, $deutsch);
        self::assertTrue($created->isSuccess);

        $before = $this->quotes->findById($created->id);
        self::assertSame($deutsch, $before['languageId']);

        $edited = $creator->edit($created->id, 'Carpe diem.', null, $latina);
        self::assertTrue($edited->isSuccess);

        $after = $this->quotes->findById($created->id);
        self::assertNotNull($after);
        // Exactly one language, now the new one — the old association is gone.
        self::assertSame($latina, $after['languageId']);
        self::assertSame('Latina', $after['languageName']);
        self::assertNotSame($deutsch, $after['languageId']);
    }

    // --- 12.2 dynamically extendable stock, not fixed to DE/EN ---------------

    /**
     * Starting from an empty stock, several languages — including non-DE/EN
     * names and non-Latin scripts — are created at runtime and are all
     * retrievable in the public language list, in creation (ascending-id)
     * order. The stock is not hard-coded to German/English (Requirement 12.2).
     */
    public function testStockStartsEmptyAndGrowsWithArbitraryLanguages(): void
    {
        $handler = $this->languageHandler();

        // Empty stock to begin with: nothing is pre-seeded.
        self::assertSame([], $handler->handle(new Request('GET', 'languages'))->body);

        $names = ['Français', '日本語', 'Latina'];
        $createdIds = [];
        foreach ($names as $name) {
            $created = $handler->handle(new Request('POST', 'languages', [], ['name' => $name]));
            self::assertSame(201, $created->status);
            $createdIds[] = $created->body['id'];
        }

        // All are retrievable in the list — the stock grew at runtime with
        // names that are neither German nor English.
        self::assertSame([
            ['id' => $createdIds[0], 'name' => 'Français'],
            ['id' => $createdIds[1], 'name' => '日本語'],
            ['id' => $createdIds[2], 'name' => 'Latina'],
        ], $handler->handle(new Request('GET', 'languages'))->body);
    }

    /**
     * A language created at runtime (here a non-DE/EN one) is immediately
     * usable as a quote's languageId, and the quote resolves to that language's
     * name — proving the dynamically grown stock feeds quote assignment, not a
     * fixed DE/EN set (Requirements 12.2, 12.1).
     */
    public function testRuntimeCreatedLanguageIsUsableAsQuoteLanguage(): void
    {
        $handler = $this->languageHandler();

        $created = $handler->handle(new Request('POST', 'languages', [], ['name' => '日本語']));
        self::assertSame(201, $created->status);
        $languageId = $created->body['id'];

        $quote = $this->quoteCreator()->create('名言。', null, $languageId);
        self::assertTrue($quote->isSuccess);

        $stored = $this->quotes->findById($quote->id);
        self::assertNotNull($stored);
        self::assertSame($languageId, $stored['languageId']);
        self::assertSame('日本語', $stored['languageName']);
    }
}
