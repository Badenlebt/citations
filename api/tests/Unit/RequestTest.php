<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for the {@see Request} value object: method normalization,
 * API-relative path derivation with script-dir / `/api` prefix stripping
 * (Requirements 16.3), segment splitting, query capture and JSON body decoding.
 */
final class RequestTest extends TestCase
{
    public function testExplicitConstructorSplitsSegments(): void
    {
        $request = new Request('GET', 'quotes/7', ['q' => 'x'], ['text' => 'hi']);

        self::assertSame('GET', $request->method);
        self::assertSame('quotes/7', $request->path);
        self::assertSame(['quotes', '7'], $request->segments);
        self::assertSame(['q' => 'x'], $request->query);
        self::assertSame(['text' => 'hi'], $request->body);
    }

    public function testFromGlobalsUppercasesMethodAndStripsApiPrefix(): void
    {
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/api/quotes', 'SCRIPT_NAME' => '/api/index.php'],
            [],
            '',
        );

        self::assertSame('POST', $request->method);
        self::assertSame('quotes', $request->path);
        self::assertSame(['quotes'], $request->segments);
    }

    public function testFromGlobalsStripsSubdirectoryAndApiPrefix(): void
    {
        $request = Request::fromGlobals(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/citations/api/sources/42?foo=bar',
                'SCRIPT_NAME' => '/citations/api/index.php',
            ],
            ['foo' => 'bar'],
            '',
        );

        self::assertSame('sources/42', $request->path);
        self::assertSame(['sources', '42'], $request->segments);
        self::assertSame(['foo' => 'bar'], $request->query);
    }

    public function testApiRootResolvesToEmptyPath(): void
    {
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api', 'SCRIPT_NAME' => '/api/index.php'],
            [],
            '',
        );

        self::assertSame('', $request->path);
        self::assertSame([], $request->segments);
    }

    public function testDecodesJsonObjectBody(): void
    {
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/quotes', 'SCRIPT_NAME' => '/api/index.php'],
            [],
            '{"text":"Zitat","languageId":3}',
        );

        self::assertSame(['text' => 'Zitat', 'languageId' => 3], $request->body);
    }

    public function testMalformedJsonBodyBecomesEmptyArray(): void
    {
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/quotes', 'SCRIPT_NAME' => '/api/index.php'],
            [],
            '{not valid json',
        );

        self::assertSame([], $request->body);
    }

    public function testEmptyBodyBecomesEmptyArray(): void
    {
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/search', 'SCRIPT_NAME' => '/api/index.php'],
            [],
            '',
        );

        self::assertSame([], $request->body);
    }
}
