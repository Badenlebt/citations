<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\Http\Request;
use Citations\Http\Response;
use Citations\Http\SessionGuard;
use Citations\TokenAuthenticator;
use Citations\Tests\Fake\FixedClock;
use Citations\Tests\Fake\InMemorySessionRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\RecordingPipeline;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Example/unit tests for the {@see SessionGuard} middleware (design
 * "Session-Guard (Middleware)", Requirements 7.1, 7.2, 7.3, 7.5, 7.6).
 *
 * These cover the structural guard decision with concrete examples; the
 * universal properties (21/22) are separate property tests (tasks 12.3/12.4).
 *
 * Every assertion that `$next` was NOT invoked is the proof that no handler
 * ran, hence nothing was created, changed, or deleted for an unauthenticated
 * write (7.1, 7.2). The uniform 401 for both existing and non-existing resource
 * ids demonstrates the response is independent of the data store (7.6).
 */
final class SessionGuardTest extends TestCase
{
    /** A well-formed but never-issued token: 64 lowercase hex chars. */
    private const UNKNOWN_TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** The token under which a valid session is seeded for the 7.4 case. */
    private const VALID_TOKEN = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    private DateTimeImmutable $checkTime;
    private InMemoryStore $store;
    private TokenAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->checkTime = new DateTimeImmutable('2024-01-01 12:00:00');
        $this->store = new InMemoryStore();
        $this->authenticator = new TokenAuthenticator(new InMemorySessionRepository($this->store));
    }

    private function guard(RecordingPipeline $next): SessionGuard
    {
        return new SessionGuard($next, $this->authenticator, new FixedClock($this->checkTime));
    }

    /**
     * Write routes crossed with each kind of unauthenticated token. Each must
     * yield 401 UNAUTHORIZED without ever forwarding to `$next` (7.1, 7.2, 7.5).
     *
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function unauthenticatedWrites(): array
    {
        $malformed = 'not-a-valid-token';           // content-invalid (6.11)
        $expiredToken = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';

        $cases = [];
        foreach ([
            'POST /quotes' => ['POST', 'quotes'],
            'PUT /sources/{id}' => ['PUT', 'sources/5'],
            'DELETE /quotes/{id}' => ['DELETE', 'quotes/9'],
            'POST /languages' => ['POST', 'languages'],
        ] as $route => [$method, $path]) {
            $cases["$route no token"] = [$method, $path, null];
            $cases["$route malformed"] = [$method, $path, $malformed];
            $cases["$route unknown 64-hex"] = [$method, $path, self::UNKNOWN_TOKEN];
            $cases["$route expired"] = [$method, $path, $expiredToken];
        }

        return $cases;
    }

    #[DataProvider('unauthenticatedWrites')]
    public function testUnauthenticatedWriteIsRejectedBeforeDispatch(string $method, string $path, ?string $token): void
    {
        // For the expired-token rows, seed an already-expired session so the
        // token is assignable yet not authenticated (6.4 expiry path).
        if ($token !== null && $token === 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100') {
            $this->store->sessions[$token] = [
                'token' => $token,
                'operatorId' => 1,
                'createdAt' => $this->checkTime->modify('-48 hours'),
                'expiresAt' => $this->checkTime->modify('-24 hours'),
            ];
        }

        $next = new RecordingPipeline();
        $response = $this->guard($next)->dispatch(new Request($method, $path, [], [], $token));

        self::assertSame(ErrorCode::UNAUTHORIZED->httpStatus(), $response->status);
        self::assertSame('UNAUTHORIZED', $response->body['error']);
        self::assertSame(SessionGuard::UNAUTHORIZED_MESSAGE, $response->body['message']);
        self::assertFalse($next->called, 'the handler pipeline must not run for an unauthenticated write');
    }

    /**
     * 7.6: the 401 is byte-for-byte identical whether the addressed resource
     * exists or not, so no inference about the data store is possible. Since
     * the guard rejects before any resource lookup, the only thing varied here
     * is the id in the path; both must produce equal responses and never call
     * `$next`.
     */
    public function testUnauthenticatedWriteIsIdenticalForExistingAndMissingResource(): void
    {
        $nextExisting = new RecordingPipeline();
        $nextMissing = new RecordingPipeline();

        // id 1 is the always-present Unbekannt-Quelle; id 999999 does not exist.
        $responseExisting = $this->guard($nextExisting)
            ->dispatch(new Request('PUT', 'sources/1', [], [], null));
        $responseMissing = $this->guard($nextMissing)
            ->dispatch(new Request('PUT', 'sources/999999', [], [], null));

        self::assertSame($responseExisting->status, $responseMissing->status);
        self::assertSame($responseExisting->body, $responseMissing->body);
        self::assertFalse($nextExisting->called);
        self::assertFalse($nextMissing->called);
    }

    /**
     * 7.4: a write carrying a valid (authenticated, non-expired) session is
     * forwarded to `$next`, and the wrapped response is returned unchanged.
     */
    public function testAuthenticatedWriteIsForwardedUnchanged(): void
    {
        $this->store->sessions[self::VALID_TOKEN] = [
            'token' => self::VALID_TOKEN,
            'operatorId' => 1,
            'createdAt' => $this->checkTime->modify('-1 hour'),
            'expiresAt' => $this->checkTime->modify('+1 hour'),
        ];

        $expected = Response::created(['id' => 7]);
        $next = new RecordingPipeline($expected);

        $request = new Request('POST', 'quotes', [], [], self::VALID_TOKEN);
        $response = $this->guard($next)->dispatch($request);

        self::assertTrue($next->called, 'a valid session must reach the handler pipeline');
        self::assertSame($expected, $response);
        self::assertSame($request, $next->lastRequest);
    }

    /**
     * 7.3: every PUBLIC read route is forwarded to `$next` even with no token,
     * since those reads require no session. The management list reads
     * GET /quotes and GET /sources are deliberately absent here — they are
     * protected (18.6, 19.6) and covered by their own cases below.
     *
     * @return array<string, array{0: string}>
     */
    public static function readRoutes(): array
    {
        return [
            'GET /search' => ['search'],
            'GET /random' => ['random'],
            'GET /quotes/{id}' => ['quotes/7'],
            'GET /sources/{id}' => ['sources/4'],
            'GET /languages' => ['languages'],
        ];
    }

    #[DataProvider('readRoutes')]
    public function testReadRouteIsForwardedWithoutSession(string $path): void
    {
        $next = new RecordingPipeline();

        $response = $this->guard($next)->dispatch(new Request('GET', $path, [], [], null));

        self::assertTrue($next->called, "GET /$path must be forwarded without a session");
        self::assertSame(200, $response->status);
    }

    /**
     * 18.6 / 19.6: the two MANAGEMENT list reads GET /quotes and GET /sources
     * are protected. Crossed with each kind of unauthenticated token they must
     * yield 401 UNAUTHORIZED without ever forwarding to `$next`, so no data is
     * read (6.11 non-authenticated token set).
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function unauthenticatedListReads(): array
    {
        $malformed = 'not-a-valid-token';           // content-invalid (6.11)
        $expiredToken = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';

        $cases = [];
        foreach (['GET /quotes' => 'quotes', 'GET /sources' => 'sources'] as $route => $path) {
            $cases["$route no token"] = [$path, null];
            $cases["$route malformed"] = [$path, $malformed];
            $cases["$route unknown 64-hex"] = [$path, self::UNKNOWN_TOKEN];
            $cases["$route expired"] = [$path, $expiredToken];
        }

        return $cases;
    }

    #[DataProvider('unauthenticatedListReads')]
    public function testUnauthenticatedListReadIsRejectedBeforeDispatch(string $path, ?string $token): void
    {
        // For the expired-token rows, seed an already-expired session so the
        // token is assignable yet not authenticated (6.4 expiry path).
        if ($token === 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100') {
            $this->store->sessions[$token] = [
                'token' => $token,
                'operatorId' => 1,
                'createdAt' => $this->checkTime->modify('-48 hours'),
                'expiresAt' => $this->checkTime->modify('-24 hours'),
            ];
        }

        $next = new RecordingPipeline();
        $response = $this->guard($next)->dispatch(new Request('GET', $path, [], [], $token));

        self::assertSame(ErrorCode::UNAUTHORIZED->httpStatus(), $response->status);
        self::assertSame('UNAUTHORIZED', $response->body['error']);
        self::assertSame(SessionGuard::UNAUTHORIZED_MESSAGE, $response->body['message']);
        self::assertFalse($next->called, "GET /$path must not reach a handler without a valid session");
    }

    /**
     * 18.6 / 19.6: a management list read carrying a valid session is forwarded
     * to `$next` and the wrapped response returned unchanged.
     *
     * @return array<string, array{0: string}>
     */
    public static function protectedListReads(): array
    {
        return [
            'GET /quotes' => ['quotes'],
            'GET /sources' => ['sources'],
        ];
    }

    #[DataProvider('protectedListReads')]
    public function testAuthenticatedListReadIsForwardedUnchanged(string $path): void
    {
        $this->store->sessions[self::VALID_TOKEN] = [
            'token' => self::VALID_TOKEN,
            'operatorId' => 1,
            'createdAt' => $this->checkTime->modify('-1 hour'),
            'expiresAt' => $this->checkTime->modify('+1 hour'),
        ];

        $expected = Response::ok(['items' => [], 'total' => 0]);
        $next = new RecordingPipeline($expected);

        $request = new Request('GET', $path, [], [], self::VALID_TOKEN);
        $response = $this->guard($next)->dispatch($request);

        self::assertTrue($next->called, "GET /$path with a valid session must reach the handler pipeline");
        self::assertSame($expected, $response);
        self::assertSame($request, $next->lastRequest);
    }

    /**
     * Auth login/logout routes are never guarded — they establish/end the
     * session themselves — so they forward regardless of the presented token.
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function authRoutes(): array
    {
        return [
            'POST /auth/login no token' => ['auth/login', null],
            'POST /auth/logout no token' => ['auth/logout', null],
            'POST /auth/logout with token' => ['auth/logout', self::UNKNOWN_TOKEN],
        ];
    }

    #[DataProvider('authRoutes')]
    public function testAuthRouteIsForwardedRegardlessOfToken(string $path, ?string $token): void
    {
        $next = new RecordingPipeline();

        $response = $this->guard($next)->dispatch(new Request('POST', $path, [], [], $token));

        self::assertTrue($next->called, "POST /$path must be forwarded");
        self::assertSame(200, $response->status);
    }
}
