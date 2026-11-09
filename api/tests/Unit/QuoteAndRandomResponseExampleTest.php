<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\Http\Dispatcher;
use Citations\Http\QuoteHandler;
use Citations\Http\Request;
use Citations\Http\Response;
use Citations\Http\SearchHandler;
use Citations\Http\SessionGuard;
use Citations\QuoteCreator;
use Citations\RandomNumberGenerator;
use Citations\RandomQuoteSelector;
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
 * Reader-facing example tests that document the Zitat- and Zufalls-Antwort
 * response structures at the Requirement level (Requirements 3.1, 4.1, 7.4).
 *
 * Each test is a small, self-contained example of ONE requirement point,
 * driven over the in-memory repositories (and, for 7.4, the real session
 * guard + dispatcher wiring). The exhaustive endpoint behaviour lives in
 * {@see QuoteHandlerTest} and {@see SearchHandlerTest}; this file exists to
 * show, side by side, how the two read shapes differ and how an authenticated
 * write travels end-to-end.
 */
final class QuoteAndRandomResponseExampleTest extends TestCase
{
    private InMemoryStore $store;
    private InMemorySourceRepository $sources;
    private InMemoryQuoteRepository $quotes;
    private InMemoryLanguageRepository $languages;
    private int $de;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->sources = new InMemorySourceRepository($this->store);
        $this->quotes = new InMemoryQuoteRepository($this->store);
        $this->languages = new InMemoryLanguageRepository($this->store);

        $this->de = $this->languages->insert('Deutsch', 'deutsch');
    }

    // --- 4.1: GET /api/quotes/{id} single view ------------------------------

    /**
     * Requirement 4.1: the single-quote view returns EXACTLY
     * `{ id, text, languageId, languageName, source: { id, name, linkUrl } }`,
     * i.e. the source block includes `linkUrl` (unlike the search/random item
     * shape). Here the source carries a concrete link.
     */
    public function testSingleQuoteViewReturnsFullShapeIncludingSourceLinkUrl(): void
    {
        $seneca = $this->sources->insert('Seneca', ['linkUrl' => 'https://example.test/seneca']);
        $id = $this->quotes->insert('Non vitae sed scholae discimus', $seneca, $this->de);

        $response = $this->quoteHandler()->handle(
            new Request('GET', "quotes/{$id}"),
            ['id' => $id],
        );

        self::assertSame(200, $response->status);
        self::assertSame([
            'id' => $id,
            'text' => 'Non vitae sed scholae discimus',
            'languageId' => $this->de,
            'languageName' => 'Deutsch',
            'source' => [
                'id' => $seneca,
                'name' => 'Seneca',
                'linkUrl' => 'https://example.test/seneca',
            ],
        ], $response->body);
        // The single view ALWAYS carries the source.linkUrl key (4.1).
        self::assertArrayHasKey('linkUrl', $response->body['source']);
    }

    /**
     * Requirement 4.1: a source without a link still surfaces the key, as
     * `linkUrl: null` — the field is part of the shape, not omitted.
     */
    public function testSingleQuoteViewSurfacesNullLinkUrlWhenSourceHasNoLink(): void
    {
        $anon = $this->sources->insert('Unbekannter Autor', []);
        $id = $this->quotes->insert('Ein Zitat ohne Link', $anon, $this->de);

        $response = $this->quoteHandler()->handle(
            new Request('GET', "quotes/{$id}"),
            ['id' => $id],
        );

        self::assertSame(200, $response->status);
        self::assertArrayHasKey('linkUrl', $response->body['source']);
        self::assertNull($response->body['source']['linkUrl']);
    }

    // --- 3.1: GET /api/random one complete quote ----------------------------

    /**
     * Requirement 3.1: `GET /api/random` returns exactly ONE quote record with
     * the complete structure (id, text, languageId, languageName, source).
     * With a fixed RNG draw and a seeded stock the pick is deterministic, so
     * the example asserts the concrete record that comes back.
     */
    public function testRandomReturnsExactlyOneCompleteQuoteRecord(): void
    {
        $seneca = $this->sources->insert('Seneca', ['linkUrl' => 'https://example.test/seneca']);
        // Two quotes in stock; a draw of 0.0 deterministically selects the
        // first by the stable ascending-id ordering.
        $first = $this->quotes->insert('Vita sine litteris mors est', $seneca, $this->de);
        $this->quotes->insert('Zweites Zitat', $seneca, $this->de);

        $response = $this->searchHandler(0.0)->handle(new Request('GET', 'random'));

        self::assertSame(200, $response->status);
        // One single quote record (NOT a list/envelope): the id addresses the
        // deterministically selected quote.
        self::assertSame($first, $response->body['id']);
        self::assertSame('Vita sine litteris mors est', $response->body['text']);
        self::assertSame($this->de, $response->body['languageId']);
        self::assertSame('Deutsch', $response->body['languageName']);
        self::assertSame($seneca, $response->body['source']['id']);
        self::assertSame('Seneca', $response->body['source']['name']);

        // The random/search item shape carries id+name but NOT linkUrl, which
        // is the one difference from the single view above (design "Antwort").
        self::assertSame(['id', 'name'], array_keys($response->body['source']));
        self::assertArrayNotHasKey('linkUrl', $response->body['source']);
        // The record itself is a single quote, not a paginated envelope.
        self::assertSame(
            ['id', 'text', 'languageId', 'languageName', 'source'],
            array_keys($response->body),
        );
    }

    // --- 7.4: an authenticated write executes end-to-end --------------------

    /**
     * Requirement 7.4: a write accompanied by a VALID session is executed.
     * This example wires the full protected path exactly as `index.php` does —
     * SessionGuard -> Dispatcher -> QuoteHandler — seeds one valid, non-expired
     * session under a 64-hex token, and sends `POST /api/quotes` carrying that
     * token. The guard forwards it, the handler creates the quote, and the
     * stored record proves the write took effect.
     */
    public function testWriteWithValidSessionReachesHandlerAndCreatesQuote(): void
    {
        $seneca = $this->sources->insert('Seneca', []);

        $now = new DateTimeImmutable('2024-01-01 12:00:00');
        $token = str_repeat('ab12', 16); // 64 hex chars
        $this->store->sessions[$token] = [
            'token' => $token,
            'operatorId' => 1,
            'createdAt' => $now->modify('-1 hour'),
            'expiresAt' => $now->modify('+1 hour'),
        ];

        $guard = new SessionGuard(
            new Dispatcher([Dispatcher::QUOTES => $this->quoteHandler()]),
            new TokenAuthenticator(new InMemorySessionRepository($this->store)),
            new FixedClock($now),
        );

        $response = $guard->dispatch(new Request(
            'POST',
            'quotes',
            [],
            ['text' => 'Mit gueltiger Session gespeichert', 'sourceId' => $seneca, 'languageId' => $this->de],
            $token,
        ));

        // The write was executed (201 + { id }), not rejected as unauthenticated.
        self::assertSame(201, $response->status);
        self::assertIsInt($response->body['id']);

        // And it actually persisted through the whole pipeline.
        self::assertSame(1, $this->quotes->count());
        $stored = $this->quotes->findById($response->body['id']);
        self::assertSame('Mit gueltiger Session gespeichert', $stored['text']);
        self::assertSame($seneca, $stored['source']['id']);
        self::assertSame($this->de, $stored['languageId']);
    }

    // --- collaborators ------------------------------------------------------

    private function quoteHandler(): QuoteHandler
    {
        return new QuoteHandler(
            new QuoteCreator($this->quotes, $this->sources, $this->languages),
            $this->quotes,
        );
    }

    private function searchHandler(float $randomDraw): SearchHandler
    {
        return new SearchHandler(
            $this->quotes,
            $this->languages,
            new RandomQuoteSelector($this->quotes, $this->fixedRng($randomDraw)),
        );
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
