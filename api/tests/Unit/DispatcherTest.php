<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\Http\Dispatcher;
use Citations\Http\Request;
use Citations\Http\Response;
use Citations\Tests\Fake\RecordingHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for the {@see Dispatcher}: correct (method, path) routing to
 * each API group, path-prefix stripping with and without a subdirectory mount,
 * `{id}` extraction, and uniform errors for unknown routes / unsupported
 * methods (design "FC --> SearchAPI & QuoteAPI & AuthorAPI & AuthAPI",
 * Requirements 16.3).
 */
final class DispatcherTest extends TestCase
{
    /** @var array<string, RecordingHandler> */
    private array $handlers;
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->handlers = [
            Dispatcher::SEARCH => new RecordingHandler(),
            Dispatcher::QUOTES => new RecordingHandler(),
            Dispatcher::SOURCES => new RecordingHandler(),
            Dispatcher::LANGUAGES => new RecordingHandler(),
            Dispatcher::AUTH => new RecordingHandler(),
        ];
        $this->dispatcher = new Dispatcher($this->handlers);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function routeTable(): array
    {
        return [
            'GET search' => ['GET', 'search', Dispatcher::SEARCH],
            'GET random' => ['GET', 'random', Dispatcher::SEARCH],
            'GET quotes' => ['GET', 'quotes', Dispatcher::QUOTES],
            'POST quotes' => ['POST', 'quotes', Dispatcher::QUOTES],
            'GET quote item' => ['GET', 'quotes/7', Dispatcher::QUOTES],
            'PUT quote item' => ['PUT', 'quotes/7', Dispatcher::QUOTES],
            'DELETE quote item' => ['DELETE', 'quotes/7', Dispatcher::QUOTES],
            'GET sources' => ['GET', 'sources', Dispatcher::SOURCES],
            'POST sources' => ['POST', 'sources', Dispatcher::SOURCES],
            'PUT source item' => ['PUT', 'sources/42', Dispatcher::SOURCES],
            'DELETE source item' => ['DELETE', 'sources/42', Dispatcher::SOURCES],
            'GET languages' => ['GET', 'languages', Dispatcher::LANGUAGES],
            'POST languages' => ['POST', 'languages', Dispatcher::LANGUAGES],
            'POST auth login' => ['POST', 'auth/login', Dispatcher::AUTH],
            'POST auth logout' => ['POST', 'auth/logout', Dispatcher::AUTH],
        ];
    }

    #[DataProvider('routeTable')]
    public function testRoutesToExpectedHandler(string $method, string $path, string $expectedKey): void
    {
        $response = $this->dispatcher->dispatch(new Request($method, $path));

        self::assertSame(200, $response->status);
        self::assertTrue($this->handlers[$expectedKey]->called, "$method /$path should reach $expectedKey");

        foreach ($this->handlers as $key => $handler) {
            if ($key !== $expectedKey) {
                self::assertFalse($handler->called, "$key must not be called for $method /$path");
            }
        }
    }

    public function testExtractsIdPathParamForQuoteItem(): void
    {
        $this->dispatcher->dispatch(new Request('PUT', 'quotes/123'));

        self::assertSame(['id' => 123], $this->handlers[Dispatcher::QUOTES]->lastPathParams);
    }

    public function testExtractsIdPathParamForSourceItem(): void
    {
        $this->dispatcher->dispatch(new Request('DELETE', 'sources/55'));

        self::assertSame(['id' => 55], $this->handlers[Dispatcher::SOURCES]->lastPathParams);
    }

    public function testUnknownRouteYieldsNotFound(): void
    {
        $response = $this->dispatcher->dispatch(new Request('GET', 'does/not/exist'));

        self::assertSame(ErrorCode::NOT_FOUND->httpStatus(), $response->status);
        self::assertSame('NOT_FOUND', $response->body['error']);
        foreach ($this->handlers as $handler) {
            self::assertFalse($handler->called);
        }
    }

    public function testUnsupportedMethodOnKnownResourceYieldsNotFound(): void
    {
        // DELETE on the quotes collection (no id) is not a defined route.
        $response = $this->dispatcher->dispatch(new Request('DELETE', 'quotes'));

        self::assertSame(ErrorCode::NOT_FOUND->httpStatus(), $response->status);
        self::assertFalse($this->handlers[Dispatcher::QUOTES]->called);
    }

    public function testNonNumericIdYieldsNotFound(): void
    {
        $response = $this->dispatcher->dispatch(new Request('GET', 'quotes/abc'));

        self::assertSame(ErrorCode::NOT_FOUND->httpStatus(), $response->status);
        self::assertFalse($this->handlers[Dispatcher::QUOTES]->called);
    }

    public function testMissingRegisteredHandlerYieldsInternalError(): void
    {
        $dispatcher = new Dispatcher([]); // nothing registered

        $response = $dispatcher->dispatch(new Request('GET', 'search'));

        self::assertSame(ErrorCode::INTERNAL_ERROR->httpStatus(), $response->status);
        self::assertSame('INTERNAL_ERROR', $response->body['error']);
    }

    public function testPrefixStrippingWorksWithoutSubdirectory(): void
    {
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/quotes/9', 'SCRIPT_NAME' => '/api/index.php'],
            [],
            '',
        );

        $this->dispatcher->dispatch($request);

        self::assertTrue($this->handlers[Dispatcher::QUOTES]->called);
        self::assertSame(['id' => 9], $this->handlers[Dispatcher::QUOTES]->lastPathParams);
    }

    /**
     * The guard consults {@see Dispatcher::isProtectedRoute()}: it is the write
     * set PLUS the two management list reads GET /quotes and GET /sources
     * (18.6, 19.6), while every other read and the auth routes stay public
     * (7.3). The decision is purely structural — no resource lookup.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function protectedRouteTable(): array
    {
        return [
            // Management list reads: protected (18.6, 19.6).
            'GET /quotes' => ['GET', 'quotes', true],
            'GET /sources' => ['GET', 'sources', true],
            // Writes: protected (unchanged from isWriteRoute).
            'POST /quotes' => ['POST', 'quotes', true],
            'PUT /quotes/{id}' => ['PUT', 'quotes/7', true],
            'DELETE /sources/{id}' => ['DELETE', 'sources/42', true],
            'POST /languages' => ['POST', 'languages', true],
            // Public reads: NOT protected (7.3).
            'GET /quotes/{id}' => ['GET', 'quotes/7', false],
            'GET /sources/{id}' => ['GET', 'sources/4', false],
            'GET /languages' => ['GET', 'languages', false],
            'GET /search' => ['GET', 'search', false],
            'GET /random' => ['GET', 'random', false],
            // Auth routes are never protected.
            'POST /auth/login' => ['POST', 'auth/login', false],
            'POST /auth/logout' => ['POST', 'auth/logout', false],
        ];
    }

    #[DataProvider('protectedRouteTable')]
    public function testIsProtectedRouteClassifiesStructurally(string $method, string $path, bool $expected): void
    {
        $segments = $path === '' ? [] : explode('/', $path);

        self::assertSame($expected, Dispatcher::isProtectedRoute($method, $segments));
    }

    public function testPrefixStrippingWorksWithSubdirectoryMount(): void
    {
        $request = Request::fromGlobals(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/citations/api/sources/4?x=1',
                'SCRIPT_NAME' => '/citations/api/index.php',
            ],
            [],
            '',
        );

        $this->dispatcher->dispatch($request);

        self::assertTrue($this->handlers[Dispatcher::SOURCES]->called);
        self::assertSame(['id' => 4], $this->handlers[Dispatcher::SOURCES]->lastPathParams);
    }
}
