<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\Http\Request;
use Citations\Http\Response;
use Citations\Http\SourceHandler;
use Citations\SourceCreator;
use Citations\SourceDeleter;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see SourceHandler}, the Autoren-API endpoint composition
 * for GET/POST/PUT/DELETE /api/sources (Requirements 5.1, 5.2, 5.4, 10.1, 10.6,
 * 11.1, 11.2, 11.3, 11.4, 11.5). The handler is driven over the in-memory
 * repositories; the underlying mutation/validation rules are covered by the
 * {@see SourceCreator} and {@see SourceDeleter} tests.
 */
final class SourceHandlerTest extends TestCase
{
    private InMemoryStore $store;
    private InMemorySourceRepository $sources;
    private InMemoryQuoteRepository $quotes;
    private SourceHandler $handler;
    private int $de;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->sources = new InMemorySourceRepository($this->store);
        $this->quotes = new InMemoryQuoteRepository($this->store);

        $this->de = $this->store->allocateLanguageId();
        $this->store->languages[$this->de] = ['id' => $this->de, 'name' => 'Deutsch', 'nameNormalized' => 'deutsch'];

        $this->handler = new SourceHandler(
            new SourceCreator($this->sources),
            new SourceDeleter($this->sources, $this->quotes),
            $this->sources,
        );
    }

    private function get(int $id): Response
    {
        return $this->handler->handle(new Request('GET', "sources/{$id}"), ['id' => $id]);
    }

    private function post(array $body): Response
    {
        return $this->handler->handle(new Request('POST', 'sources', [], $body));
    }

    private function put(int $id, array $body): Response
    {
        return $this->handler->handle(new Request('PUT', "sources/{$id}", [], $body), ['id' => $id]);
    }

    private function deleteRequest(int $id, array $query = []): Response
    {
        return $this->handler->handle(new Request('DELETE', "sources/{$id}", $query), ['id' => $id]);
    }

    /**
     * Drive a bare `GET sources` (no {id}) through the handler.
     *
     * @param array<string, int|string> $query
     */
    private function list(array $query = []): Response
    {
        return $this->handler->handle(new Request('GET', 'sources', $query));
    }

    /**
     * Seed $count extra (non-Unbekannt) sources with ascending names and return
     * their ids in insertion order.
     *
     * @return list<int>
     */
    private function seedSources(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->sources->insert("Quelle {$i}");
        }

        return $ids;
    }

    // --- GET /api/sources (management list, Requirement 19) -------------------

    public function testBareCollectionGetReturnsListEnvelope(): void
    {
        // Requirement 19 DEFINES GET /sources: a bare collection GET returns 200
        // with the { items, total, page, pageSize } envelope (19.1). Even with
        // no extra sources, the ever-present Unbekannt-Quelle keeps total >= 1.
        $response = $this->list();

        self::assertSame(200, $response->status);
        self::assertSame(['items', 'total', 'page', 'pageSize'], array_keys($response->body));
        self::assertSame(1, $response->body['total']);
    }

    public function testListDefaultsToPageOneSizeTwenty(): void
    {
        // No page/pageSize params => page 1, pageSize 20 (19.1 defaults). 24
        // extra sources + the Unbekannt-Quelle => total 25, full first page 20.
        $this->seedSources(24);

        $response = $this->list();

        self::assertSame(200, $response->status);
        self::assertSame(1, $response->body['page']);
        self::assertSame(20, $response->body['pageSize']);
        self::assertSame(25, $response->body['total']);
        self::assertCount(20, $response->body['items']);
    }

    public function testListReturnsRequestedWindowAndTotal(): void
    {
        // 24 extra sources + Unbekannt => total 25. Page 2 of size 10 returns
        // the next 10 items, total unchanged, page/pageSize echoed (19.1).
        $this->seedSources(24);

        $response = $this->list(['page' => 2, 'pageSize' => 10]);

        self::assertSame(200, $response->status);
        self::assertSame(2, $response->body['page']);
        self::assertSame(10, $response->body['pageSize']);
        self::assertSame(25, $response->body['total']);
        self::assertCount(10, $response->body['items']);
    }

    public function testListClampsPageSizeAbove100ToHundred(): void
    {
        // pageSize > 100 clamps to 100, mirroring Paginator (19.1 range).
        $response = $this->list(['pageSize' => 500]);

        self::assertSame(100, $response->body['pageSize']);
    }

    public function testListClampsNonPositivePageAndPageSizeToValidMinimums(): void
    {
        // page < 1 clamps to the default page 1; a non-positive pageSize falls
        // back to the default 20 (the same normalization Paginator applies).
        $response = $this->list(['page' => 0, 'pageSize' => 0]);

        self::assertSame(1, $response->body['page']);
        self::assertSame(20, $response->body['pageSize']);
    }

    public function testListReturnsItemsInStableAscendingIdOrder(): void
    {
        // The Unbekannt-Quelle is id 1; the extra sources follow in ascending
        // id. The list must come back sorted ascending by id (19.3).
        $extra = $this->seedSources(4);
        $expectedOrder = array_merge([InMemoryStore::UNKNOWN_SOURCE_ID], $extra);
        sort($expectedOrder);

        $first = $this->list(['pageSize' => 100]);
        $actual = array_map(static fn (array $item): int => $item['id'], $first->body['items']);
        self::assertSame($expectedOrder, $actual, 'items come back sorted by ascending id (19.3)');

        // Repeating the call with the same data yields the same order (19.3).
        $second = $this->list(['pageSize' => 100]);
        self::assertSame($first->body['items'], $second->body['items']);
    }

    public function testListItemCarriesIdNameAndIsUnknownFlag(): void
    {
        // Each item carries id/name/isUnknown (19.2): the Unbekannt-Quelle has
        // isUnknown true, a normal source false.
        $cicero = $this->sources->insert('Cicero');

        $response = $this->list(['pageSize' => 100]);
        $items = $response->body['items'];

        $byId = [];
        foreach ($items as $item) {
            self::assertSame(['id', 'name', 'isUnknown'], array_keys($item));
            $byId[$item['id']] = $item;
        }

        self::assertSame('unbekannt', $byId[InMemoryStore::UNKNOWN_SOURCE_ID]['name']);
        self::assertTrue($byId[InMemoryStore::UNKNOWN_SOURCE_ID]['isUnknown']);
        self::assertSame('Cicero', $byId[$cicero]['name']);
        self::assertFalse($byId[$cicero]['isUnknown']);
    }

    public function testListPageBeyondEndReturnsEmptyItemsWithUnchangedTotal(): void
    {
        // 4 extra sources + Unbekannt => total 5. Page 10 of size 20 => empty
        // window, total still 5 (19.4).
        $this->seedSources(4);

        $response = $this->list(['page' => 10]);

        self::assertSame(200, $response->status);
        self::assertSame([], $response->body['items']);
        self::assertSame(5, $response->body['total']);
    }

    public function testListAlwaysContainsUnknownSource(): void
    {
        // With no extra sources, GET /sources still returns at least the
        // Unbekannt-Quelle: total >= 1 and the first item is the unknown source
        // (19.5).
        $response = $this->list();

        self::assertGreaterThanOrEqual(1, $response->body['total']);
        self::assertNotEmpty($response->body['items']);

        $first = $response->body['items'][0];
        self::assertSame(InMemoryStore::UNKNOWN_SOURCE_ID, $first['id']);
        self::assertTrue($first['isUnknown']);
    }

    // --- GET /api/sources/{id} ------------------------------------------------

    public function testGetReturnsSingleViewWithUnsetOptionalsAsNullAndWithoutIsUnknown(): void
    {
        // Only a name is set; the four optional fields stay unset (5.2).
        $id = $this->sources->insert('Seneca');

        $response = $this->get($id);

        self::assertSame(200, $response->status);
        self::assertSame([
            'id' => $id,
            'name' => 'Seneca',
            'birthDate' => null,
            'deathDate' => null,
            'description' => null,
            'linkUrl' => null,
        ], $response->body);
        // The public single view omits the internal isUnknown flag entirely.
        self::assertArrayNotHasKey('isUnknown', $response->body);
    }

    public function testGetReturnsAllPopulatedOptionalFields(): void
    {
        $id = $this->sources->insert('Seneca', [
            'birthDate' => '4 v. Chr.',
            'deathDate' => '65 n. Chr.',
            'description' => 'Römischer Philosoph',
            'linkUrl' => 'https://example.test/seneca',
        ]);

        $response = $this->get($id);

        self::assertSame(200, $response->status);
        self::assertSame([
            'id' => $id,
            'name' => 'Seneca',
            'birthDate' => '4 v. Chr.',
            'deathDate' => '65 n. Chr.',
            'description' => 'Römischer Philosoph',
            'linkUrl' => 'https://example.test/seneca',
        ], $response->body);
    }

    public function testGetUnknownIdReturns404NotFound(): void
    {
        $response = $this->get(9999);

        self::assertSame(404, $response->status);
        self::assertSame(ErrorCode::NOT_FOUND->value, $response->body['error']);
    }

    // --- POST /api/sources ----------------------------------------------------

    public function testPostValidReturns201WithId(): void
    {
        $response = $this->post(['name' => 'Cicero', 'birthDate' => '106 v. Chr.']);

        self::assertSame(201, $response->status);
        self::assertIsInt($response->body['id']);

        $stored = $this->sources->findById($response->body['id']);
        self::assertSame('Cicero', $stored['name']);
        self::assertSame('106 v. Chr.', $stored['birthDate']);
    }

    public function testPostMissingNameReturns400MissingName(): void
    {
        $response = $this->post(['name' => '   ']);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::MISSING_NAME->value, $response->body['error']);
    }

    public function testPostInvalidLinkUrlReturns400InvalidLinkUrl(): void
    {
        $response = $this->post(['name' => 'Cicero', 'linkUrl' => 'ftp://example.test']);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_LINK_URL->value, $response->body['error']);
    }

    public function testPostTooLongNameReturns400InputTooLong(): void
    {
        $response = $this->post(['name' => str_repeat('a', SourceCreator::MAX_NAME_LENGTH + 1)]);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INPUT_TOO_LONG->value, $response->body['error']);
    }

    public function testPostReservedUnknownNameReturns409(): void
    {
        $response = $this->post(['name' => 'Unbekannt']);

        self::assertSame(409, $response->status);
        self::assertSame(ErrorCode::UNKNOWN_SOURCE_RESERVED->value, $response->body['error']);
    }

    // --- PUT /api/sources/{id} ------------------------------------------------

    public function testPutValidSavesChangedValuesAndReturns200(): void
    {
        $id = $this->sources->insert('Alt');

        $response = $this->put($id, ['name' => 'Neu', 'description' => 'geändert']);

        self::assertSame(200, $response->status);
        self::assertSame($id, $response->body['id']);

        $stored = $this->sources->findById($id);
        self::assertSame('Neu', $stored['name']);
        self::assertSame('geändert', $stored['description']);
    }

    public function testPutUnknownIdReturns404(): void
    {
        $response = $this->put(9999, ['name' => 'Neu']);

        self::assertSame(404, $response->status);
        self::assertSame(ErrorCode::NOT_FOUND->value, $response->body['error']);
    }

    // --- DELETE /api/sources/{id} ---------------------------------------------

    public function testDeleteSourceWithoutQuotesReturns200AndRemovesSource(): void
    {
        $id = $this->sources->insert('Cicero');

        $response = $this->deleteRequest($id);

        self::assertSame(200, $response->status);
        self::assertSame($id, $response->body['id']);
        self::assertNull($this->sources->findById($id));
    }

    public function testDeleteSourceWithQuotesNoStrategyReturns409AndChangesNothing(): void
    {
        $id = $this->sources->insert('Cicero');
        $this->quotes->insert('Ein Zitat', $id, $this->de);

        $response = $this->deleteRequest($id);

        self::assertSame(409, $response->status);
        self::assertSame(ErrorCode::SOURCE_HAS_QUOTES->value, $response->body['error']);
        // Nothing changed: the source still exists and its quote still points to it (11.2).
        self::assertNotNull($this->sources->findById($id));
        self::assertSame(1, $this->quotes->countBySource($id));
    }

    public function testDeleteWithReassignStrategyMovesQuotesToUnknownThenRemovesSource(): void
    {
        $id = $this->sources->insert('Cicero');
        $this->quotes->insert('Ein Zitat', $id, $this->de);

        $response = $this->deleteRequest($id, ['strategy' => 'reassign']);

        self::assertSame(200, $response->status);
        self::assertSame($id, $response->body['id']);
        // The source is gone and its quote was reassigned to the Unbekannt-Quelle (11.3).
        self::assertNull($this->sources->findById($id));
        self::assertSame(0, $this->quotes->countBySource($id));
        self::assertSame(1, $this->quotes->countBySource(InMemoryStore::UNKNOWN_SOURCE_ID));
    }

    public function testDeleteWithCancelStrategyReturns200AndChangesNothing(): void
    {
        $id = $this->sources->insert('Cicero');
        $this->quotes->insert('Ein Zitat', $id, $this->de);

        $response = $this->deleteRequest($id, ['strategy' => 'cancel']);

        self::assertSame(200, $response->status);
        self::assertSame($id, $response->body['id']);
        // Cancel is a deliberate no-op: the source and its quote are untouched (11.4).
        self::assertNotNull($this->sources->findById($id));
        self::assertSame(1, $this->quotes->countBySource($id));
    }

    public function testDeleteUnknownSourceReturns400UndeletableAndChangesNothing(): void
    {
        $response = $this->deleteRequest(InMemoryStore::UNKNOWN_SOURCE_ID);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::UNKNOWN_SOURCE_UNDELETABLE->value, $response->body['error']);
        // The Unbekannt-Quelle remains (11.5 / 13.1).
        self::assertNotNull($this->sources->findById(InMemoryStore::UNKNOWN_SOURCE_ID));
    }
}
