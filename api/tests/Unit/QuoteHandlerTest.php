<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\Http\Dispatcher;
use Citations\Http\QuoteHandler;
use Citations\Http\Request;
use Citations\Http\Response;
use Citations\Http\SessionGuard;
use Citations\QuoteCreator;
use Citations\Tests\Fake\FixedClock;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySessionRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\TokenAuthenticator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see QuoteHandler}, the Zitat-API endpoint composition for
 * GET/POST/PUT/DELETE /api/quotes (Requirements 4.1, 4.5, 7.4, 8.1, 9.1, 9.4,
 * 9.5). The handler is driven over the in-memory repositories; the underlying
 * mutation/validation rules are covered by the {@see QuoteCreator} tests.
 */
final class QuoteHandlerTest extends TestCase
{
    private InMemoryStore $store;
    private InMemorySourceRepository $sources;
    private InMemoryQuoteRepository $quotes;
    private InMemoryLanguageRepository $languages;
    private QuoteHandler $handler;
    private int $de;
    private int $seneca;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->sources = new InMemorySourceRepository($this->store);
        $this->quotes = new InMemoryQuoteRepository($this->store);
        $this->languages = new InMemoryLanguageRepository($this->store);

        $this->de = $this->languages->insert('Deutsch', 'deutsch');
        $this->seneca = $this->sources->insert('Seneca', ['linkUrl' => 'https://example.test/seneca']);

        $this->handler = new QuoteHandler(
            new QuoteCreator($this->quotes, $this->sources, $this->languages),
            $this->quotes,
        );
    }

    private function get(int $id): Response
    {
        return $this->handler->handle(new Request('GET', "quotes/{$id}"), ['id' => $id]);
    }

    private function post(array $body): Response
    {
        return $this->handler->handle(new Request('POST', 'quotes', [], $body));
    }

    private function put(int $id, array $body): Response
    {
        return $this->handler->handle(new Request('PUT', "quotes/{$id}", [], $body), ['id' => $id]);
    }

    private function deleteRequest(int $id): Response
    {
        return $this->handler->handle(new Request('DELETE', "quotes/{$id}"), ['id' => $id]);
    }

    // --- GET /api/quotes/{id} -------------------------------------------------

    public function testGetReturnsSingleViewIncludingSourceLinkUrl(): void
    {
        $id = $this->quotes->insert('Non vitae sed scholae discimus', $this->seneca, $this->de);

        $response = $this->get($id);

        self::assertSame(200, $response->status);
        self::assertSame([
            'id' => $id,
            'text' => 'Non vitae sed scholae discimus',
            'languageId' => $this->de,
            'languageName' => 'Deutsch',
            'source' => [
                'id' => $this->seneca,
                'name' => 'Seneca',
                'linkUrl' => 'https://example.test/seneca',
            ],
        ], $response->body);
        // The single view INCLUDES source.linkUrl (4.1), unlike the search item.
        self::assertArrayHasKey('linkUrl', $response->body['source']);
    }

    public function testGetUnknownIdReturns404NotFound(): void
    {
        $response = $this->get(9999);

        self::assertSame(404, $response->status);
        self::assertSame(ErrorCode::NOT_FOUND->value, $response->body['error']);
    }

    // --- POST /api/quotes -----------------------------------------------------

    public function testPostValidReturns201WithId(): void
    {
        $response = $this->post(['text' => 'Carpe diem', 'sourceId' => $this->seneca, 'languageId' => $this->de]);

        self::assertSame(201, $response->status);
        self::assertIsInt($response->body['id']);

        $stored = $this->quotes->findById($response->body['id']);
        self::assertSame('Carpe diem', $stored['text']);
        self::assertSame($this->seneca, $stored['source']['id']);
        self::assertSame($this->de, $stored['languageId']);
    }

    public function testPostMissingTextReturns400MissingText(): void
    {
        $response = $this->post(['text' => '   ', 'languageId' => $this->de]);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::MISSING_TEXT->value, $response->body['error']);
        self::assertSame(0, $this->quotes->count());
    }

    public function testPostInvalidSourceReturns400InvalidSource(): void
    {
        $response = $this->post(['text' => 'Hallo', 'sourceId' => 4242, 'languageId' => $this->de]);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_SOURCE->value, $response->body['error']);
        self::assertSame(0, $this->quotes->count());
    }

    public function testPostMissingLanguageReturns400InvalidLanguage(): void
    {
        $response = $this->post(['text' => 'Hallo', 'sourceId' => $this->seneca]);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_LANGUAGE->value, $response->body['error']);
        self::assertSame(0, $this->quotes->count());
    }

    public function testPostInvalidLanguageReturns400InvalidLanguage(): void
    {
        $response = $this->post(['text' => 'Hallo', 'languageId' => 4242]);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_LANGUAGE->value, $response->body['error']);
        self::assertSame(0, $this->quotes->count());
    }

    public function testPostWithoutSourceFallsBackToUnknownSource(): void
    {
        // Missing sourceId => Unbekannt-Quelle (13.3); the create still succeeds.
        $response = $this->post(['text' => 'Ohne Quelle', 'languageId' => $this->de]);

        self::assertSame(201, $response->status);
        $stored = $this->quotes->findById($response->body['id']);
        self::assertSame(InMemoryStore::UNKNOWN_SOURCE_ID, $stored['source']['id']);
        self::assertSame('unbekannt', $stored['source']['name']);
    }

    // --- PUT /api/quotes/{id} -------------------------------------------------

    public function testPutValidSavesChangedValuesAndReturns200(): void
    {
        $id = $this->quotes->insert('Alt', $this->seneca, $this->de);
        $en = $this->languages->insert('English', 'english');

        $response = $this->put($id, ['text' => 'Neu', 'languageId' => $en]);

        self::assertSame(200, $response->status);
        self::assertSame($id, $response->body['id']);

        $stored = $this->quotes->findById($id);
        self::assertSame('Neu', $stored['text']);
        self::assertSame($en, $stored['languageId']);
        // Null source on edit falls back to the Unbekannt-Quelle (13.3).
        self::assertSame(InMemoryStore::UNKNOWN_SOURCE_ID, $stored['source']['id']);
    }

    public function testPutUnknownIdReturns404(): void
    {
        $response = $this->put(9999, ['text' => 'Neu', 'languageId' => $this->de]);

        self::assertSame(404, $response->status);
        self::assertSame(ErrorCode::NOT_FOUND->value, $response->body['error']);
    }

    public function testPutInvalidBodyReturns400AndLeavesQuoteUnchanged(): void
    {
        $id = $this->quotes->insert('Original', $this->seneca, $this->de);

        $response = $this->put($id, ['text' => '', 'languageId' => $this->de]);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::MISSING_TEXT->value, $response->body['error']);

        $stored = $this->quotes->findById($id);
        self::assertSame('Original', $stored['text']);
    }

    // --- DELETE /api/quotes/{id} ---------------------------------------------

    public function testDeleteExistingReturns200ConfirmationAndRemovesQuote(): void
    {
        $id = $this->quotes->insert('Weg damit', $this->seneca, $this->de);

        $response = $this->deleteRequest($id);

        self::assertSame(200, $response->status);
        self::assertSame($id, $response->body['id']);
        self::assertFalse($this->quotes->exists($id));
    }

    public function testDeleteUnknownIdReturns404(): void
    {
        $response = $this->deleteRequest(9999);

        self::assertSame(404, $response->status);
        self::assertSame(ErrorCode::NOT_FOUND->value, $response->body['error']);
    }

    // --- GET /api/quotes (management list, Requirement 18) --------------------

    /**
     * Drive a bare `GET quotes` (no {id}) through the handler.
     *
     * @param array<string, int|string> $query
     */
    private function list(array $query = []): Response
    {
        return $this->handler->handle(new Request('GET', 'quotes', $query));
    }

    /**
     * Seed $count quotes with ascending texts, all on the Seneca source and the
     * Deutsch language, and return their ids in insertion order.
     *
     * @return list<int>
     */
    private function seedQuotes(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->quotes->insert("Zitat {$i}", $this->seneca, $this->de);
        }

        return $ids;
    }

    public function testBareCollectionGetReturnsListEnvelope(): void
    {
        // Requirement 18 now DEFINES GET /quotes: a bare collection GET returns
        // 200 with the { items, total, page, pageSize } envelope (18.1), not a
        // 404. With no quotes the list is empty and total is 0 (18.5).
        $response = $this->list();

        self::assertSame(200, $response->status);
        self::assertSame(['items', 'total', 'page', 'pageSize'], array_keys($response->body));
        self::assertSame([], $response->body['items']);
        self::assertSame(0, $response->body['total']);
    }

    public function testListDefaultsToPageOneSizeTwenty(): void
    {
        // No page/pageSize params => page 1, pageSize 20 (18.1 defaults). 25
        // quotes means a full first page of 20 with total 25.
        $this->seedQuotes(25);

        $response = $this->list();

        self::assertSame(200, $response->status);
        self::assertSame(1, $response->body['page']);
        self::assertSame(20, $response->body['pageSize']);
        self::assertSame(25, $response->body['total']);
        self::assertCount(20, $response->body['items']);
    }

    public function testListReturnsRequestedWindowAndTotal(): void
    {
        // 25 quotes, page 2 of size 10 => the 5 remaining items, total unchanged
        // at 25, with page/pageSize echoed back (18.1).
        $this->seedQuotes(25);

        $response = $this->list(['page' => 2, 'pageSize' => 10]);

        self::assertSame(200, $response->status);
        self::assertSame(2, $response->body['page']);
        self::assertSame(10, $response->body['pageSize']);
        self::assertSame(25, $response->body['total']);
        self::assertCount(10, $response->body['items']);
    }

    public function testListClampsPageSizeAbove100ToHundred(): void
    {
        // pageSize > 100 clamps to 100, mirroring Paginator (18.1 range).
        $response = $this->list(['pageSize' => 500]);

        self::assertSame(100, $response->body['pageSize']);
    }

    public function testListClampsNonPositivePageAndPageSizeToValidMinimums(): void
    {
        // page < 1 clamps to the default page 1; a non-positive pageSize is
        // treated as "not requested" and falls back to the default 20 (the
        // same normalization Paginator applies).
        $response = $this->list(['page' => 0, 'pageSize' => 0]);

        self::assertSame(1, $response->body['page']);
        self::assertSame(20, $response->body['pageSize']);
    }

    public function testListReturnsItemsInStableAscendingIdOrder(): void
    {
        $ids = $this->seedQuotes(5);
        $expectedOrder = $ids; // already ascending by construction
        sort($expectedOrder);

        $first = $this->list(['pageSize' => 100]);
        $actual = array_map(static fn (array $item): int => $item['id'], $first->body['items']);
        self::assertSame($expectedOrder, $actual, 'items come back sorted by ascending id (18.3)');

        // Repeating the call with the same data yields the same order (18.3).
        $second = $this->list(['pageSize' => 100]);
        self::assertSame($first->body['items'], $second->body['items']);
    }

    public function testListItemCarriesExpectedFieldsAndNoSourceLinkUrl(): void
    {
        $id = $this->quotes->insert('Non vitae sed scholae discimus', $this->seneca, $this->de);

        $response = $this->list();

        self::assertCount(1, $response->body['items']);
        $item = $response->body['items'][0];

        // Item fields: id/text/languageId/languageName/source{id,name} (18.2).
        self::assertSame($id, $item['id']);
        self::assertSame('Non vitae sed scholae discimus', $item['text']);
        self::assertSame($this->de, $item['languageId']);
        self::assertSame('Deutsch', $item['languageName']);
        self::assertSame(['id' => $this->seneca, 'name' => 'Seneca'], $item['source']);
        // The list item carries NO source linkUrl, unlike the single view (18.2).
        self::assertArrayNotHasKey('linkUrl', $item['source']);
    }

    public function testListPageBeyondEndReturnsEmptyItemsWithUnchangedTotal(): void
    {
        // 5 quotes, request page 10 of size 20 => empty window, total still 5
        // (18.4).
        $this->seedQuotes(5);

        $response = $this->list(['page' => 10]);

        self::assertSame(200, $response->status);
        self::assertSame([], $response->body['items']);
        self::assertSame(5, $response->body['total']);
    }

    public function testListEmptyStockReturnsEmptyItemsWithTotalZero(): void
    {
        // No quotes at all => empty items, total 0 (18.5).
        $response = $this->list();

        self::assertSame(200, $response->status);
        self::assertSame([], $response->body['items']);
        self::assertSame(0, $response->body['total']);
    }

    // --- 7.4: a guard-allowed write reaches the handler ----------------------

    public function testAuthenticatedWriteReachesHandlerThroughGuardAndDispatcher(): void
    {
        // Wire the full write path exactly as index.php does: SessionGuard ->
        // Dispatcher -> QuoteHandler. A valid, non-expired session means the
        // guard forwards the POST to the handler, which creates the quote (7.4).
        $checkTime = new DateTimeImmutable('2024-01-01 12:00:00');
        $token = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';
        $this->store->sessions[$token] = [
            'token' => $token,
            'operatorId' => 1,
            'createdAt' => $checkTime->modify('-1 hour'),
            'expiresAt' => $checkTime->modify('+1 hour'),
        ];

        $dispatcher = new Dispatcher([Dispatcher::QUOTES => $this->handler]);
        $guard = new SessionGuard(
            $dispatcher,
            new TokenAuthenticator(new InMemorySessionRepository($this->store)),
            new FixedClock($checkTime),
        );

        $response = $guard->dispatch(new Request(
            'POST',
            'quotes',
            [],
            ['text' => 'Durch den Guard', 'sourceId' => $this->seneca, 'languageId' => $this->de],
            $token,
        ));

        self::assertSame(201, $response->status);
        self::assertSame(1, $this->quotes->count());
        $stored = $this->quotes->findById($response->body['id']);
        self::assertSame('Durch den Guard', $stored['text']);
    }
}
