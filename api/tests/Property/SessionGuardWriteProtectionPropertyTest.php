<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\Http\Request;
use Citations\Http\SessionGuard;
use Citations\TokenAuthenticator;
use Citations\Tests\Fake\FixedClock;
use Citations\Tests\Fake\InMemorySessionRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\RecordingPipeline;
use DateTimeImmutable;
use Eris\Generator;

/**
 * Feature: citations-management, Property 21: Schreibzugriff ohne gültige
 * Session verändert keine Daten.
 *
 * For EVERY write operation of the Quote/Source/Language API (the write set of
 * {@see \Citations\Http\Dispatcher::isWriteRoute()}: POST /quotes,
 * PUT|DELETE /quotes/{id}, POST /sources, PUT|DELETE /sources/{id},
 * POST /languages) and EVERY token that does NOT authenticate per Requirement
 * 6.11 — missing, content-invalid, not-assignable to a session, expired (6.4),
 * or ended by logout — the {@see SessionGuard} rejects the request with
 * UNAUTHORIZED (401) and the ENTIRE data state remains unchanged.
 *
 * The "no data change" claim is expressed at the guard layer: the guard wraps a
 * {@see RecordingPipeline} that records whether it was ever invoked. Asserting
 * the wrapped pipeline was NEVER called proves no handler ran, so nothing could
 * be created, changed or deleted (7.1, 7.2). The quantifier genuinely ranges
 * over the full space of (write route × five non-auth token kinds); the check
 * is deterministic via a {@see FixedClock} and a per-iteration seeded session
 * repository.
 *
 * Validates: Requirements 6.11, 7.1, 7.2
 */
final class SessionGuardWriteProtectionPropertyTest extends PropertyTestCase
{
    /** The deterministic instant every authentication check is evaluated at. */
    private const CHECK_EPOCH = 1_700_000_000;

    /** One hour in seconds. */
    private const SECONDS_PER_HOUR = 3600;

    /** The five non-authenticating token kinds of Requirement 6.11. */
    private const KIND_MISSING = 0;
    private const KIND_INVALID = 1;
    private const KIND_NOT_ASSIGNABLE = 2;
    private const KIND_EXPIRED = 3;
    private const KIND_LOGGED_OUT = 4;

    /**
     * The complete WRITE set as (method, path-template) pairs; `{id}` is filled
     * with a generated concrete positive id. Mirrors
     * {@see \Citations\Http\Dispatcher::isWriteRoute()}.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const WRITE_ROUTES = [
        ['POST', 'quotes'],
        ['PUT', 'quotes/{id}'],
        ['DELETE', 'quotes/{id}'],
        ['POST', 'sources'],
        ['PUT', 'sources/{id}'],
        ['DELETE', 'sources/{id}'],
        ['POST', 'languages'],
    ];

    /** A well-formed token: exactly 64 lowercase hex chars, from an int seed. */
    private function wellFormedToken(int $seed): string
    {
        return substr(hash('sha256', 'token-' . $seed), 0, 64);
    }

    public function testUnauthenticatedWriteIsRejectedAndChangesNoData(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // Index into WRITE_ROUTES (covers every write route).
            Generator\choose(0, count(self::WRITE_ROUTES) - 1),
            // Concrete positive id for the collection-item routes.
            Generator\choose(1, 100_000),
            // Which of the five non-authenticating 6.11 kinds to exercise.
            Generator\choose(self::KIND_MISSING, self::KIND_LOGGED_OUT),
            // Seed for the token value (well-formed or malformed variants).
            Generator\choose(0, PHP_INT_MAX),
            // How many hours before the check instant an expired session ended.
            Generator\choose(1, 1000)
        )->then(function (
            int $routeIndex,
            int $id,
            int $kind,
            int $tokenSeed,
            int $expiredAgoHours
        ): void {
            [$method, $template] = self::WRITE_ROUTES[$routeIndex];
            $path = str_replace('{id}', (string) $id, $template);

            $checkTime = new DateTimeImmutable('@' . self::CHECK_EPOCH);

            $store = new InMemoryStore();
            $sessions = new InMemorySessionRepository($store);
            $authenticator = new TokenAuthenticator($sessions);

            // Build a token that, by construction, does NOT authenticate at
            // $checkTime, one per 6.11 kind.
            $token = $this->buildNonAuthToken($kind, $tokenSeed, $expiredAgoHours, $checkTime, $sessions);

            $recording = new RecordingPipeline();
            $guard = new SessionGuard($recording, $authenticator, new FixedClock($checkTime));

            $request = new Request($method, $path, [], [], $token);

            $response = $guard->dispatch($request);

            $label = sprintf(
                '%s /%s (kind %d, token %s)',
                $method,
                $path,
                $kind,
                var_export($token, true)
            );

            // (6.11) The precondition: the token truly does not authenticate.
            self::assertFalse(
                $authenticator->isAuthenticated($token, $checkTime),
                'Precondition failed: token must NOT authenticate for ' . $label
            );

            // (7.1/7.6) Rejected with UNAUTHORIZED (401).
            self::assertSame(401, $response->status, 'Write must be 401 for ' . $label);
            self::assertIsArray($response->body);
            self::assertSame('UNAUTHORIZED', $response->body['error'], 'Error code must be UNAUTHORIZED for ' . $label);

            // (7.1/7.2) No handler ran => the entire data state is unchanged.
            self::assertFalse(
                $recording->called,
                'The wrapped pipeline must NEVER be invoked for an unauthenticated write: ' . $label
            );
        });
    }

    /**
     * Construct a token that does NOT authenticate at $checkTime for the given
     * 6.11 kind, seeding the session repository where the kind requires it.
     */
    private function buildNonAuthToken(
        int $kind,
        int $tokenSeed,
        int $expiredAgoHours,
        DateTimeImmutable $checkTime,
        InMemorySessionRepository $sessions
    ): ?string {
        switch ($kind) {
            case self::KIND_MISSING:
                // No token presented.
                return null;

            case self::KIND_INVALID:
                // Content-invalid: a well-formed 64-hex token uppercased so it
                // is no longer [0-9a-f]{64} and can never name a session.
                return strtoupper($this->wellFormedToken($tokenSeed));

            case self::KIND_NOT_ASSIGNABLE:
                // Well-formed but never seeded => no matching session row.
                return $this->wellFormedToken($tokenSeed);

            case self::KIND_EXPIRED:
                // Well-formed, seeded, but the session already expired at or
                // before $checkTime (6.4): expiresAt <= checkTime.
                $token = $this->wellFormedToken($tokenSeed);
                $expiresAt = $checkTime->modify('-' . ($expiredAgoHours * self::SECONDS_PER_HOUR) . ' seconds');
                $createdAt = $expiresAt->modify('-' . self::SECONDS_PER_HOUR . ' seconds');
                $sessions->insert($token, 1, $createdAt, $expiresAt);
                return $token;

            case self::KIND_LOGGED_OUT:
            default:
                // Seeded (non-expired) then deleted => logout ended it, so
                // findByToken returns null and the token is unassignable (6.6).
                $token = $this->wellFormedToken($tokenSeed);
                $createdAt = $checkTime->modify('-' . self::SECONDS_PER_HOUR . ' seconds');
                $expiresAt = $checkTime->modify('+' . self::SECONDS_PER_HOUR . ' seconds');
                $sessions->insert($token, 1, $createdAt, $expiresAt);
                $sessions->delete($token);
                return $token;
        }
    }
}
