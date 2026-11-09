<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\Http\Request;
use Citations\Http\SearchHandler;
use Citations\RandomNumberGenerator;
use Citations\RandomQuoteSelector;
use Citations\Repository\QuoteRepositoryInterface;
use Citations\SearchQueryValidator;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see SearchHandler}, the Search-API endpoint composition
 * for GET /api/search and GET /api/random (Requirements 1.1, 1.5, 1.6, 1.7,
 * 1.8, 3.1, 3.3, 5.3). The handler is driven over the in-memory repositories
 * and a fixed random generator; the underlying search/scoring/pagination
 * properties are covered by the pure-component property tests.
 */
final class SearchHandlerTest extends TestCase
{
    private InMemoryStore $store;
    private InMemorySourceRepository $sources;
    private InMemoryQuoteRepository $quotes;
    private InMemoryLanguageRepository $languages;
    private int $de;
    private int $en;
    private int $seneca;
    private int $cicero;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->sources = new InMemorySourceRepository($this->store);
        $this->quotes = new InMemoryQuoteRepository($this->store);
        $this->languages = new InMemoryLanguageRepository($this->store);

        $this->de = $this->languages->insert('Deutsch', 'deutsch');
        $this->en = $this->languages->insert('English', 'english');
        $this->seneca = $this->sources->insert('Seneca', []);
        $this->cicero = $this->sources->insert('Cicero', []);
    }

    private function handler(float $randomDraw = 0.0): SearchHandler
    {
        return new SearchHandler(
            $this->quotes,
            $this->languages,
            new RandomQuoteSelector($this->quotes, $this->fixedRng($randomDraw)),
        );
    }

    private function search(array $query): \Citations\Http\Response
    {
        return $this->handler()->handle(new Request('GET', 'search', $query));
    }

    // --- GET /api/search: happy path, shaping, ordering, pagination ---------

    public function testSearchReturnsShapedItemsOrderedByRelevanceWithIdTieBreak(): void
    {
        // "weisheit" as a standalone word scores higher than as an infix.
        $standalone = $this->quotes->insert('Die weisheit siegt', $this->seneca, $this->de);
        // Two infix-only matches share the same relevance -> id tie-break asc.
        $infixA = $this->quotes->insert('allweisheitlich', $this->cicero, $this->en);
        $infixB = $this->quotes->insert('urweisheitskunde', $this->seneca, $this->de);

        $response = $this->search(['q' => 'weisheit']);

        self::assertSame(200, $response->status);
        $body = $response->body;
        self::assertSame(3, $body['total']);
        self::assertSame(1, $body['page']);
        self::assertSame(20, $body['pageSize']);

        $ids = array_column($body['items'], 'id');
        // standalone first (highest relevance); ties broken by ascending id.
        self::assertSame([$standalone, $infixA, $infixB], $ids);

        // Item shape: id, text, languageId, languageName, source{id,name}.
        $first = $body['items'][0];
        self::assertSame($standalone, $first['id']);
        self::assertSame('Die weisheit siegt', $first['text']);
        self::assertSame($this->de, $first['languageId']);
        self::assertSame('Deutsch', $first['languageName']);
        self::assertSame(['id' => $this->seneca, 'name' => 'Seneca'], $first['source']);
        self::assertArrayNotHasKey('linkUrl', $first['source']);
    }

    public function testSearchMatchesOnSourceName(): void
    {
        // The term appears only in the source name, not the quote text (1.2).
        $hit = $this->quotes->insert('Ein kurzer Satz', $this->seneca, $this->de);

        $body = $this->search(['q' => 'seneca'])->body;

        self::assertSame(1, $body['total']);
        self::assertSame($hit, $body['items'][0]['id']);
    }

    public function testSearchPaginatesOrderedTotalList(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->quotes->insert("wort nummer {$i}", $this->seneca, $this->de);
        }

        $body = $this->search(['q' => 'wort', 'page' => '2', 'pageSize' => '2'])->body;

        self::assertSame(5, $body['total']);
        self::assertSame(2, $body['page']);
        self::assertSame(2, $body['pageSize']);
        self::assertCount(2, $body['items']); // the second window of two
    }

    // --- GET /api/search: empty / beyond-last-page / no-hits ----------------

    public function testSearchNoHitsReturnsEmptyListTotalZero(): void
    {
        $this->quotes->insert('Nichts passendes hier', $this->seneca, $this->de);

        $body = $this->search(['q' => 'xyzzy'])->body;

        self::assertSame([], $body['items']);
        self::assertSame(0, $body['total']);
    }

    public function testSearchPageBeyondLastReturnsEmptyListWithUnchangedTotal(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->quotes->insert("treffer {$i}", $this->seneca, $this->de);
        }

        // 3 hits, pageSize 20 => only page 1 exists; page 5 is beyond the last.
        $body = $this->search(['q' => 'treffer', 'page' => '5'])->body;

        self::assertSame([], $body['items']);
        self::assertSame(3, $body['total']); // total unchanged (1.8)
    }

    // --- GET /api/search: query validation applied --------------------------

    public function testEmptyQueryYieldsMissingQueryWithoutSearching(): void
    {
        $this->quotes->insert('Irgendein Zitat', $this->seneca, $this->de);

        $response = $this->search(['q' => '   ']);

        self::assertSame(ErrorCode::MISSING_QUERY->httpStatus(), $response->status);
        self::assertSame('MISSING_QUERY', $response->body['error']);
    }

    public function testMissingQueryParamYieldsMissingQuery(): void
    {
        $response = $this->search([]);

        self::assertSame('MISSING_QUERY', $response->body['error']);
    }

    public function testOverlongQueryYieldsQueryTooLong(): void
    {
        $longQuery = str_repeat('a', SearchQueryValidator::MAX_LENGTH + 1);

        $response = $this->search(['q' => $longQuery]);

        self::assertSame(ErrorCode::QUERY_TOO_LONG->httpStatus(), $response->status);
        self::assertSame('QUERY_TOO_LONG', $response->body['error']);
    }

    // --- GET /api/search: language filter and sourceId restriction ----------

    public function testLanguageFilterNarrowsResultsToSelectedLanguages(): void
    {
        $german = $this->quotes->insert('geteiltes leid', $this->seneca, $this->de);
        $this->quotes->insert('shared leid word', $this->cicero, $this->en);

        $body = $this->search(['q' => 'leid', 'languages' => (string) $this->de])->body;

        self::assertSame(1, $body['total']);
        self::assertSame($german, $body['items'][0]['id']);
        self::assertSame($this->de, $body['items'][0]['languageId']);
    }

    public function testFilterReferencingOnlyUnknownLanguageYieldsNoHits(): void
    {
        $this->quotes->insert('etwas text', $this->seneca, $this->de);

        $body = $this->search(['q' => 'text', 'languages' => '9999'])->body;

        self::assertSame([], $body['items']);
        self::assertSame(0, $body['total']);
    }

    public function testSourceIdRestrictsResultsToOneSource(): void
    {
        $senecaHit = $this->quotes->insert('gemeinsames stichwort', $this->seneca, $this->de);
        $this->quotes->insert('gemeinsames stichwort', $this->cicero, $this->de);

        $body = $this->search(['q' => 'stichwort', 'sourceId' => (string) $this->seneca])->body;

        self::assertSame(1, $body['total']);
        self::assertSame($senecaHit, $body['items'][0]['id']);
        self::assertSame($this->seneca, $body['items'][0]['source']['id']);
    }

    // --- GET /api/random ----------------------------------------------------

    public function testRandomReturnsOneCompleteQuote(): void
    {
        $only = $this->quotes->insert('Ein zufaelliges Zitat', $this->seneca, $this->de);

        $response = $this->handler(0.0)->handle(new Request('GET', 'random'));

        self::assertSame(200, $response->status);
        self::assertSame($only, $response->body['id']);
        self::assertSame('Ein zufaelliges Zitat', $response->body['text']);
        self::assertSame($this->de, $response->body['languageId']);
        self::assertSame('Deutsch', $response->body['languageName']);
        self::assertSame($this->seneca, $response->body['source']['id']);
        self::assertSame('Seneca', $response->body['source']['name']);
    }

    public function testRandomWithEmptyStockYieldsNoQuotes(): void
    {
        $response = $this->handler(0.0)->handle(new Request('GET', 'random'));

        self::assertSame(ErrorCode::NO_QUOTES->httpStatus(), $response->status);
        self::assertSame('NO_QUOTES', $response->body['error']);
    }

    private function fixedRng(float $value): RandomNumberGenerator
    {
        return new class ($value) implements RandomNumberGenerator {
            public function __construct(private readonly float $value)
            {
            }

            public function next(): float
            {
                return $this->value;
            }
        };
    }
}
