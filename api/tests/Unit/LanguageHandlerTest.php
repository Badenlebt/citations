<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\Http\LanguageHandler;
use Citations\Http\Request;
use Citations\Http\Response;
use Citations\LanguageCreator;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see LanguageHandler}, the Sprach-API endpoint composition
 * for GET/POST /api/languages (Requirements 12.1, 12.2, 12.3, 12.7). The handler
 * is driven over the in-memory repository; the underlying create validation
 * rules are covered in depth by the {@see LanguageCreator} tests, so these focus
 * on the handler's method branching, response shaping and the GET/POST
 * interaction (a created language becoming visible in the list).
 */
final class LanguageHandlerTest extends TestCase
{
    private InMemoryStore $store;
    private InMemoryLanguageRepository $languages;
    private LanguageHandler $handler;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->languages = new InMemoryLanguageRepository($this->store);
        $this->handler = new LanguageHandler(
            new LanguageCreator($this->languages),
            $this->languages,
        );
    }

    private function get(): Response
    {
        return $this->handler->handle(new Request('GET', 'languages'));
    }

    private function post(array $body): Response
    {
        return $this->handler->handle(new Request('POST', 'languages', [], $body));
    }

    private function seed(string $name, string $normalized): int
    {
        return $this->languages->insert($name, $normalized);
    }

    // --- GET /api/languages ---------------------------------------------------

    public function testGetEmptyStockReturnsEmptyList(): void
    {
        $response = $this->get();

        self::assertSame(200, $response->status);
        self::assertSame([], $response->body);
    }

    public function testGetReturnsFullListOfIdNameRecords(): void
    {
        $de = $this->seed('Deutsch', 'deutsch');
        $en = $this->seed('Englisch', 'englisch');

        $response = $this->get();

        self::assertSame(200, $response->status);
        self::assertSame([
            ['id' => $de, 'name' => 'Deutsch'],
            ['id' => $en, 'name' => 'Englisch'],
        ], $response->body);
    }

    // --- POST /api/languages --------------------------------------------------

    public function testPostValidReturns201WithStoredIdAndName(): void
    {
        $response = $this->post(['name' => 'Französisch']);

        self::assertSame(201, $response->status);
        self::assertIsInt($response->body['id']);
        self::assertSame('Französisch', $response->body['name']);

        $stored = $this->languages->findById($response->body['id']);
        self::assertSame('Französisch', $stored['name']);
    }

    public function testPostTrimsNameBeforeStoring(): void
    {
        $response = $this->post(['name' => '  Italienisch  ']);

        self::assertSame(201, $response->status);
        self::assertSame('Italienisch', $response->body['name']);
    }

    public function testCreatedLanguageAppearsInSubsequentList(): void
    {
        // The stock is dynamically extendable (12.2): after a create the new
        // language is part of the available set returned by GET (12.3).
        self::assertSame([], $this->get()->body);

        $created = $this->post(['name' => 'Spanisch']);
        self::assertSame(201, $created->status);

        $list = $this->get();
        self::assertSame(200, $list->status);
        self::assertSame([
            ['id' => $created->body['id'], 'name' => 'Spanisch'],
        ], $list->body);
    }

    public function testPostMissingNameReturns400MissingLanguageName(): void
    {
        $response = $this->post([]);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::MISSING_LANGUAGE_NAME->value, $response->body['error']);
    }

    public function testPostEmptyNameReturns400MissingLanguageName(): void
    {
        $response = $this->post(['name' => '   ']);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::MISSING_LANGUAGE_NAME->value, $response->body['error']);
    }

    public function testPostDuplicateNameCaseInsensitiveReturns409LanguageExists(): void
    {
        $first = $this->post(['name' => 'Deutsch']);
        self::assertSame(201, $first->status);

        $second = $this->post(['name' => 'deutsch']);

        self::assertSame(409, $second->status);
        self::assertSame(ErrorCode::LANGUAGE_EXISTS->value, $second->body['error']);
        // Nothing new was stored: the stock still holds exactly one language.
        self::assertCount(1, $this->languages->findAll());
    }

    public function testPostTooLongNameReturns400InputTooLong(): void
    {
        $response = $this->post(['name' => str_repeat('a', LanguageCreator::MAX_NAME_LENGTH + 1)]);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INPUT_TOO_LONG->value, $response->body['error']);
    }

    // --- Method branching -----------------------------------------------------

    public function testUnsupportedMethodReturns404(): void
    {
        $response = $this->handler->handle(new Request('DELETE', 'languages'));

        self::assertSame(404, $response->status);
        self::assertSame(ErrorCode::NOT_FOUND->value, $response->body['error']);
    }
}
