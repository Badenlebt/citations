<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

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
use Eris\Generator;

/**
 * Feature: citations-management, Property 22: Lesende Endpunkte sind ohne
 * Session erreichbar.
 *
 * For EVERY read endpoint — search (`GET /search`), random quote
 * (`GET /random`), the quote and source single views (`GET /quotes/{id}`,
 * `GET /sources/{id}`) and the public language list (`GET /languages`) — a
 * request WITHOUT a session is NOT rejected with UNAUTHORIZED: the
 * {@see SessionGuard} forwards it straight to the wrapped handler pipeline, so
 * the returned response is exactly the pipeline's response and never a 401 /
 * {@see ErrorCode::UNAUTHORIZED}. In particular the language list is publicly
 * retrievable without a session (12.7). Because the guard's write/read decision
 * is purely structural ({@see \Citations\Http\Dispatcher::isWriteRoute()}), the
 * forwarding holds regardless of the presented token; the required case per
 * 7.3/12.7 is "no session" (null token), which this property centers on while
 * also ranging over arbitrary tokens to show reads are never session-gated.
 * Code under test: {@see SessionGuard::dispatch()} for every read route.
 *
 * Validates: Requirements 7.3, 12.7
 */
final class PublicReadEndpointsPropertyTest extends PropertyTestCase
{
    /** Fixed check instant: reads must forward independently of the clock. */
    private const CHECK_TIME = '2024-01-01 12:00:00';

    /**
     * The five read endpoints under test. Item routes carry a placeholder the
     * generator replaces with a concrete positive id; collection/singleton
     * reads ignore it. Keyed by a human-readable label used in assertions.
     *
     * @var array<string, array{method: string, template: string, hasId: bool}>
     */
    private const READ_ENDPOINTS = [
        'GET /search' => ['method' => 'GET', 'template' => 'search', 'hasId' => false],
        'GET /random' => ['method' => 'GET', 'template' => 'random', 'hasId' => false],
        'GET /quotes/{id}' => ['method' => 'GET', 'template' => 'quotes/{id}', 'hasId' => true],
        'GET /sources/{id}' => ['method' => 'GET', 'template' => 'sources/{id}', 'hasId' => true],
        'GET /languages' => ['method' => 'GET', 'template' => 'languages', 'hasId' => false],
    ];

    /**
     * Pick one read endpoint uniformly by its label, so the quantifier genuinely
     * ranges over ALL five read endpoints rather than a single fixed one.
     */
    private function endpointLabelGenerator(): Generator
    {
        return Generator\elements(array_keys(self::READ_ENDPOINTS));
    }

    /**
     * Presented token generator. The REQUIRED case per 7.3/12.7 is "no session"
     * (null); additionally we range over arbitrary token strings — malformed,
     * well-formed-but-unknown 64-hex, and empty — to show that reads are
     * forwarded regardless of the token, never session-gated. None of these
     * tokens is backed by a seeded session.
     */
    private function tokenGenerator(): Generator
    {
        return Generator\oneOf(
            // The core case: no session at all.
            Generator\constant(null),
            // Empty / whitespace token (classified MISSING for writes).
            Generator\elements(['', '   ']),
            // Content-invalid token shapes.
            Generator\elements(['not-a-token', 'deadbeef', '12345']),
            // Well-formed-but-unknown 64-hex tokens, derived from a seed so
            // counterexamples shrink to small, reproducible values.
            Generator\map(
                static fn (int $seed): string => substr(hash('sha256', (string) $seed), 0, 64),
                Generator\choose(0, PHP_INT_MAX)
            ),
        );
    }

    private function guard(RecordingPipeline $next): SessionGuard
    {
        $authenticator = new TokenAuthenticator(new InMemorySessionRepository(new InMemoryStore()));

        return new SessionGuard($next, $authenticator, new FixedClock(new DateTimeImmutable(self::CHECK_TIME)));
    }

    /**
     * For every read endpoint and every (missing/arbitrary) token, the guard
     * forwards the request to the handler pipeline instead of short-circuiting
     * with UNAUTHORIZED (7.3). The returned response is exactly the pipeline's
     * preset response and its status is not 401.
     */
    public function testReadEndpointsAreReachableWithoutSession(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->endpointLabelGenerator(),
            // Concrete positive id for the item routes.
            Generator\choose(1, 1_000_000),
            $this->tokenGenerator()
        )->then(function (string $label, int $id, ?string $token): void {
            $endpoint = self::READ_ENDPOINTS[$label];
            $path = $endpoint['hasId']
                ? str_replace('{id}', (string) $id, $endpoint['template'])
                : $endpoint['template'];

            // A distinctive preset response proves the guard returned the
            // pipeline's response and did not synthesize its own.
            $forwarded = new Response(200, ['ok' => true, 'endpoint' => $label]);
            $next = new RecordingPipeline($forwarded);

            $request = new Request($endpoint['method'], $path, [], [], $token);
            $response = $this->guard($next)->dispatch($request);

            $tokenLabel = $token === null ? 'no token' : sprintf('token "%s"', $token);

            // (b) The read WAS forwarded to the handler pipeline (7.3).
            self::assertTrue(
                $next->called,
                sprintf('%s (%s) must be forwarded to the handler pipeline.', $label, $tokenLabel)
            );
            self::assertSame(
                $request,
                $next->lastRequest,
                sprintf('%s (%s) must forward the original request unchanged.', $label, $tokenLabel)
            );

            // (a) The guard did NOT short-circuit with UNAUTHORIZED.
            self::assertSame(
                $forwarded,
                $response,
                sprintf('%s (%s) must return the pipeline response, not a guard-synthesized one.', $label, $tokenLabel)
            );
            self::assertNotSame(
                ErrorCode::UNAUTHORIZED->httpStatus(),
                $response->status,
                sprintf('%s (%s) must not be rejected with a 401 status.', $label, $tokenLabel)
            );
            self::assertSame(
                200,
                $response->status,
                sprintf('%s (%s) must reach the handler (status 200).', $label, $tokenLabel)
            );
            // If the response ever carried an error body, it must not be UNAUTHORIZED.
            if (is_array($response->body) && isset($response->body['error'])) {
                self::assertNotSame(
                    ErrorCode::UNAUTHORIZED->value,
                    $response->body['error'],
                    sprintf('%s (%s) must not carry an UNAUTHORIZED error body.', $label, $tokenLabel)
                );
            }
        });
    }

    /**
     * Explicit sub-property for 12.7: the public language list `GET /languages`
     * is retrievable without any session — forwarded to the handler pipeline and
     * never rejected with UNAUTHORIZED — for every check against a null token.
     */
    public function testLanguageListIsPublicWithoutSession(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // Range over the token even though the required case is null, to
            // cover empty/arbitrary presented values too.
            $this->tokenGenerator()
        )->then(function (?string $token): void {
            $forwarded = new Response(200, ['items' => []]);
            $next = new RecordingPipeline($forwarded);

            $request = new Request('GET', 'languages', [], [], $token);
            $response = $this->guard($next)->dispatch($request);

            self::assertTrue($next->called, 'GET /languages must be forwarded without a session (12.7).');
            self::assertSame($forwarded, $response, 'GET /languages must return the public list response (12.7).');
            self::assertNotSame(ErrorCode::UNAUTHORIZED->httpStatus(), $response->status);
        });
    }
}
