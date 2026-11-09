<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\CredentialValidator;
use Citations\ErrorCode;
use Citations\Http\AuthHandler;
use Citations\Http\Request;
use Citations\Http\Response;
use Citations\Http\SessionCookie;
use Citations\PasswordHasher;
use Citations\RateLimiter;
use Citations\SessionValidator;
use Citations\TokenAuthenticator;
use Citations\TokenClassification;
use Citations\Tests\Fake\FixedClock;
use Citations\Tests\Fake\FixedTokenGenerator;
use Citations\Tests\Fake\InMemoryLoginAttemptRepository;
use Citations\Tests\Fake\InMemoryOperatorRepository;
use Citations\Tests\Fake\InMemorySessionRepository;
use Citations\Tests\Fake\InMemoryStore;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see AuthHandler}, the Auth-API endpoint composition for
 * POST /api/auth/login and POST /api/auth/logout (Requirements 6.1, 6.6, 6.8,
 * 6.9; order 6.3/6.5/6.2). The handler is driven over in-memory repositories, a
 * fixed clock and a deterministic token generator. The pure building blocks
 * (credential validity/timing, the rate-limit window, expiry derivation) are
 * covered in depth by their own tests; these focus on the handler's order
 * enforcement, session creation, cookie shaping and logout behaviour.
 */
final class AuthHandlerTest extends TestCase
{
    private const TOKEN = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private InMemoryStore $store;
    private InMemoryOperatorRepository $operators;
    private InMemorySessionRepository $sessions;
    private InMemoryLoginAttemptRepository $attempts;
    private TokenAuthenticator $authenticator;
    private DateTimeImmutable $now;
    private AuthHandler $handler;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->operators = new InMemoryOperatorRepository($this->store);
        $this->sessions = new InMemorySessionRepository($this->store);
        $this->attempts = new InMemoryLoginAttemptRepository($this->store);
        $this->authenticator = new TokenAuthenticator($this->sessions);
        $this->now = new DateTimeImmutable('2024-01-01T12:00:00+00:00');

        $this->handler = new AuthHandler(
            new CredentialValidator(),
            new RateLimiter($this->attempts),
            $this->operators,
            $this->sessions,
            $this->authenticator,
            new FixedTokenGenerator(self::TOKEN),
            new FixedClock($this->now),
            null, // unconfigured => SessionValidator default 24h
        );
    }

    private function login(array $body): Response
    {
        return $this->handler->handle(new Request('POST', 'auth/login', [], $body));
    }

    private function logout(?string $token): Response
    {
        return $this->handler->handle(new Request('POST', 'auth/logout', [], [], $token));
    }

    private function seedOperator(string $username, string $password): int
    {
        return $this->operators->insert($username, PasswordHasher::hash($password));
    }

    private function recordFailures(string $username, int $count, DateTimeImmutable $at): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->attempts->record($username, $at, false);
        }
    }

    // --- Successful login (6.1, 6.8) -----------------------------------------

    public function testSuccessfulLoginCreatesSessionAndSetsHttpOnlyCookie(): void
    {
        $id = $this->seedOperator('admin', 'correct horse');

        $response = $this->login(['username' => 'admin', 'password' => 'correct horse']);

        self::assertSame(200, $response->status);
        self::assertSame(['ok' => true, 'operatorId' => $id], $response->body);

        // A session row was inserted with the generated token and an expiry of
        // now + the default 24h (6.1/6.4).
        $session = $this->sessions->findByToken(self::TOKEN);
        self::assertNotNull($session);
        self::assertSame($id, $session['operatorId']);
        self::assertEquals($this->now, $session['createdAt']);
        self::assertEquals(
            SessionValidator::expiresAt($this->now, null),
            $session['expiresAt'],
        );

        // The response sets an HttpOnly cookie named citations_session carrying
        // the token (6.1).
        $setCookie = $this->setCookie($response);
        self::assertNotNull($setCookie);
        self::assertStringContainsString(SessionCookie::NAME . '=' . self::TOKEN, $setCookie);
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('Path=/', $setCookie);
    }

    public function testMultipleOperatorsCanEachLogIn(): void
    {
        // Multiple accounts are supported, initially one (6.8).
        $alice = $this->seedOperator('alice', 'pw-alice');
        $bob = $this->seedOperator('bob', 'pw-bob');

        $a = $this->login(['username' => 'alice', 'password' => 'pw-alice']);
        self::assertSame(200, $a->status);
        self::assertSame($alice, $a->body['operatorId']);

        $b = $this->login(['username' => 'bob', 'password' => 'pw-bob']);
        self::assertSame(200, $b->status);
        self::assertSame($bob, $b->body['operatorId']);
    }

    // --- Login order 6.9: completeness (6.3) -> rate limit (6.5) -> validity (6.2) ---

    public function testBlankCredentialsReturn400AndDoNotConsultRateLimiter(): void
    {
        // Seed enough failures that the username would be locked IF the limiter
        // were consulted — but a blank request must short-circuit at 6.3 before
        // the limiter and must record no attempt.
        $this->recordFailures('admin', RateLimiter::MAX_FAILED_ATTEMPTS, $this->now->sub(new DateInterval('PT1M')));
        $before = count($this->store->loginAttempts);

        $response = $this->login(['username' => '   ', 'password' => '']);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::MISSING_CREDENTIALS->value, $response->body['error']);
        // No attempt was recorded for the blank pre-check (6.3).
        self::assertCount($before, $this->store->loginAttempts);
        // No session, no cookie set.
        self::assertNull($this->setCookie($response));
    }

    public function testMissingPasswordFieldReturns400MissingCredentials(): void
    {
        $this->seedOperator('admin', 'pw');

        $response = $this->login(['username' => 'admin']);

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::MISSING_CREDENTIALS->value, $response->body['error']);
    }

    public function testRateLimitedUsernameReturns429BeforeValidity(): void
    {
        // Valid credentials, but the username is already locked by five recent
        // failures (6.5): the handler must return 429 BEFORE checking validity.
        $this->seedOperator('admin', 'correct');
        $this->recordFailures('admin', RateLimiter::MAX_FAILED_ATTEMPTS, $this->now->sub(new DateInterval('PT1M')));

        $response = $this->login(['username' => 'admin', 'password' => 'correct']);

        self::assertSame(429, $response->status);
        self::assertSame(ErrorCode::RATE_LIMITED->value, $response->body['error']);
        // No session was created despite the valid credentials.
        self::assertNull($this->sessions->findByToken(self::TOKEN));
    }

    public function testWrongPasswordReturns401AndRecordsFailedAttempt(): void
    {
        $this->seedOperator('admin', 'correct');
        $before = count($this->store->loginAttempts);

        $response = $this->login(['username' => 'admin', 'password' => 'wrong']);

        self::assertSame(401, $response->status);
        self::assertSame(ErrorCode::INVALID_CREDENTIALS->value, $response->body['error']);

        // A failed attempt was recorded for the rate-limit window (6.5).
        self::assertCount($before + 1, $this->store->loginAttempts);
        $last = $this->store->loginAttempts[array_key_last($this->store->loginAttempts)];
        self::assertSame('admin', $last['username']);
        self::assertFalse($last['success']);
    }

    public function testUnknownUsernameReturns401Generic(): void
    {
        $response = $this->login(['username' => 'ghost', 'password' => 'whatever']);

        self::assertSame(401, $response->status);
        self::assertSame(ErrorCode::INVALID_CREDENTIALS->value, $response->body['error']);
    }

    public function testSuccessfulLoginRecordsSuccessAttempt(): void
    {
        $this->seedOperator('admin', 'correct');

        $this->login(['username' => 'admin', 'password' => 'correct']);

        $last = $this->store->loginAttempts[array_key_last($this->store->loginAttempts)];
        self::assertSame('admin', $last['username']);
        self::assertTrue($last['success']);
    }

    // --- Logout (6.6) ---------------------------------------------------------

    public function testLogoutEndsSessionAndClearsCookie(): void
    {
        $id = $this->seedOperator('admin', 'correct');
        $login = $this->login(['username' => 'admin', 'password' => 'correct']);
        self::assertSame(200, $login->status);
        self::assertSame(
            TokenClassification::AUTHENTICATED,
            $this->authenticator->classify(self::TOKEN, $this->now),
        );

        $response = $this->logout(self::TOKEN);

        self::assertSame(200, $response->status);
        // The session row is gone: the token is no longer assignable (6.6).
        self::assertNull($this->sessions->findByToken(self::TOKEN));
        self::assertSame(
            TokenClassification::NOT_ASSIGNABLE,
            $this->authenticator->classify(self::TOKEN, $this->now),
        );

        // The response clears the cookie: empty value, Max-Age=0, HttpOnly.
        $setCookie = $this->setCookie($response);
        self::assertNotNull($setCookie);
        self::assertStringContainsString(SessionCookie::NAME . '=;', $setCookie);
        self::assertStringContainsString('Max-Age=0', $setCookie);
        self::assertStringContainsString('HttpOnly', $setCookie);
        // Keep $id referenced so the intent (one operator's session) is clear.
        self::assertIsInt($id);
    }

    public function testLogoutWithNoTokenIsIdempotentlyOk(): void
    {
        $response = $this->logout(null);

        self::assertSame(200, $response->status);
        self::assertNotNull($this->setCookie($response));
    }

    public function testLogoutWithUnknownTokenIsIdempotentlyOk(): void
    {
        $response = $this->logout(self::TOKEN);

        self::assertSame(200, $response->status);
    }

    // --- Method / action branching -------------------------------------------

    public function testNonPostMethodReturns404(): void
    {
        $response = $this->handler->handle(new Request('GET', 'auth/login'));

        self::assertSame(404, $response->status);
        self::assertSame(ErrorCode::NOT_FOUND->value, $response->body['error']);
    }

    public function testUnknownAuthActionReturns404(): void
    {
        $response = $this->handler->handle(new Request('POST', 'auth/refresh', [], []));

        self::assertSame(404, $response->status);
        self::assertSame(ErrorCode::NOT_FOUND->value, $response->body['error']);
    }

    /**
     * Extract the Set-Cookie header line from a response, or null if absent.
     */
    private function setCookie(Response $response): ?string
    {
        foreach ($response->headers as $line) {
            if (str_starts_with($line, 'Set-Cookie:')) {
                return $line;
            }
        }

        return null;
    }
}
