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
use Citations\Tests\Fake\FixedClock;
use Citations\Tests\Fake\FixedTokenGenerator;
use Citations\Tests\Fake\InMemoryLoginAttemptRepository;
use Citations\Tests\Fake\InMemoryOperatorRepository;
use Citations\Tests\Fake\InMemorySessionRepository;
use Citations\Tests\Fake\InMemoryStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Requirement-level EXAMPLE tests for the Auth-API login flow, documenting the
 * behaviour a reader expects from the acceptance criteria themselves rather
 * than the handler's internal order (that order, and the pure building blocks,
 * are covered by {@see AuthHandlerTest}, {@see \Citations\Tests\Unit\CredentialValidatorTest}
 * and friends). Each test here is a worked example of one criterion:
 *
 *   - Requirement 6.1  — valid credentials establish a session and return a
 *     time-bounded HttpOnly session cookie carrying the token.
 *   - Requirement 6.8  — several distinct operator accounts each log in with
 *     their OWN password and resolve to their OWN operator id/session; one
 *     account's password never authenticates another.
 *   - Requirement 6.10 — the unknown-username and wrong-password failures are
 *     STRUCTURALLY indistinguishable: identical status and byte-for-byte
 *     identical body. (The timing balance is a property of the pure
 *     {@see CredentialValidator}; here we assert the observable response is the
 *     same, which is what a client could otherwise use to probe for usernames.)
 *
 * The handler is assembled over the same in-memory world, fixed clock and
 * deterministic token generator used elsewhere, so sessions and cookies can be
 * asserted exactly without any randomness or wall-clock dependency.
 */
final class LoginExampleTest extends TestCase
{
    private InMemoryStore $store;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->now = new DateTimeImmutable('2024-06-01T09:30:00+00:00');
    }

    /**
     * Build an AuthHandler over the shared store with a given token generator,
     * so distinct logins can be driven to distinct session tokens (6.8).
     */
    private function handlerWithToken(string $token): AuthHandler
    {
        $sessions = new InMemorySessionRepository($this->store);

        return new AuthHandler(
            new CredentialValidator(),
            new RateLimiter(new InMemoryLoginAttemptRepository($this->store)),
            new InMemoryOperatorRepository($this->store),
            $sessions,
            new TokenAuthenticator($sessions),
            new FixedTokenGenerator($token),
            new FixedClock($this->now),
            null, // unconfigured => SessionValidator default 24h
        );
    }

    private function seedOperator(string $username, string $password): int
    {
        return (new InMemoryOperatorRepository($this->store))
            ->insert($username, PasswordHasher::hash($password));
    }

    private function login(AuthHandler $handler, string $username, string $password): Response
    {
        return $handler->handle(new Request('POST', 'auth/login', [], [
            'username' => $username,
            'password' => $password,
        ]));
    }

    private function setCookie(Response $response): ?string
    {
        foreach ($response->headers as $line) {
            if (str_starts_with($line, 'Set-Cookie:')) {
                return $line;
            }
        }

        return null;
    }

    // --- 6.1: successful login establishes a time-bounded session ------------

    public function testValidCredentialsEstablishSessionAndReturnHttpOnlyCookie(): void
    {
        $token = str_repeat('1', 64);
        $id = $this->seedOperator('operator', 'S3cret pass phrase');

        $response = $this->login($this->handlerWithToken($token), 'operator', 'S3cret pass phrase');

        // The API confirms the login with the resolved operator id and no
        // credential material in the body (6.1, 6.7).
        self::assertSame(200, $response->status);
        self::assertSame(['ok' => true, 'operatorId' => $id], $response->body);

        // A session row now exists for the generated token, bound to that
        // operator, created at "now" and expiring at now + the configured
        // (default 24h) duration (6.1, derived via SessionValidator).
        $session = $this->store->sessions[$token] ?? null;
        self::assertNotNull($session);
        self::assertSame($id, $session['operatorId']);
        self::assertEquals($this->now, $session['createdAt']);
        self::assertEquals(
            SessionValidator::expiresAt($this->now, null),
            $session['expiresAt'],
        );

        // The response hands back a time-bounded, HttpOnly session cookie that
        // carries exactly the token (6.1).
        $setCookie = $this->setCookie($response);
        self::assertNotNull($setCookie);
        self::assertStringContainsString(SessionCookie::NAME . '=' . $token, $setCookie);
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('Max-Age=' . (24 * 60 * 60), $setCookie);
    }

    // --- 6.8: multiple operator accounts, each independent -------------------

    public function testEachOperatorLogsInIndependentlyWithItsOwnCredentials(): void
    {
        // Three distinct accounts coexist (6.8).
        $aliceToken = str_repeat('a', 64);
        $bobToken = str_repeat('b', 64);
        $carolToken = str_repeat('c', 64);

        $alice = $this->seedOperator('alice', 'pw-alice');
        $bob = $this->seedOperator('bob', 'pw-bob');
        $carol = $this->seedOperator('carol', 'pw-carol');

        $aliceResponse = $this->login($this->handlerWithToken($aliceToken), 'alice', 'pw-alice');
        $bobResponse = $this->login($this->handlerWithToken($bobToken), 'bob', 'pw-bob');
        $carolResponse = $this->login($this->handlerWithToken($carolToken), 'carol', 'pw-carol');

        // Each account authenticates with its OWN password and resolves to its
        // OWN operator id.
        self::assertSame(200, $aliceResponse->status);
        self::assertSame($alice, $aliceResponse->body['operatorId']);
        self::assertSame(200, $bobResponse->status);
        self::assertSame($bob, $bobResponse->body['operatorId']);
        self::assertSame(200, $carolResponse->status);
        self::assertSame($carol, $carolResponse->body['operatorId']);

        // Three distinct sessions accumulated in the shared store, each bound to
        // the right operator.
        self::assertCount(3, $this->store->sessions);
        self::assertSame($alice, $this->store->sessions[$aliceToken]['operatorId']);
        self::assertSame($bob, $this->store->sessions[$bobToken]['operatorId']);
        self::assertSame($carol, $this->store->sessions[$carolToken]['operatorId']);

        // The three operators (and their sessions) are genuinely distinct.
        self::assertCount(3, array_unique([$alice, $bob, $carol]));
        self::assertCount(3, array_unique([$aliceToken, $bobToken, $carolToken]));
    }

    public function testOneOperatorsPasswordDoesNotAuthenticateAnother(): void
    {
        // Alice's password must not log Bob in: cross credentials are rejected
        // generically (6.8 account isolation, enforced via 6.2/6.10).
        $this->seedOperator('alice', 'pw-alice');
        $this->seedOperator('bob', 'pw-bob');

        $response = $this->login($this->handlerWithToken(str_repeat('d', 64)), 'bob', 'pw-alice');

        self::assertSame(401, $response->status);
        self::assertSame(ErrorCode::INVALID_CREDENTIALS->value, $response->body['error']);
        // No session was created for the mismatched attempt.
        self::assertSame([], $this->store->sessions);
    }

    // --- 6.10: unknown username and wrong password are indistinguishable -----

    public function testUnknownUsernameAndWrongPasswordReturnIdenticalResponse(): void
    {
        // A real operator exists; "ghost" does not.
        $this->seedOperator('operator', 'right-password');

        $unknownUsername = $this->login(
            $this->handlerWithToken(str_repeat('e', 64)),
            'ghost',
            'any-password',
        );
        $wrongPassword = $this->login(
            $this->handlerWithToken(str_repeat('f', 64)),
            'operator',
            'wrong-password',
        );

        // Same status and byte-for-byte identical body: a client cannot tell the
        // two failure modes apart from the response alone, so the response never
        // reveals whether a username exists (6.10, generic 6.2 error).
        self::assertSame(401, $unknownUsername->status);
        self::assertSame($unknownUsername->status, $wrongPassword->status);
        self::assertSame(ErrorCode::INVALID_CREDENTIALS->value, $unknownUsername->body['error']);
        self::assertSame($unknownUsername->body, $wrongPassword->body);
        self::assertSame(
            json_encode($unknownUsername->body),
            json_encode($wrongPassword->body),
        );

        // Neither failing attempt establishes a session.
        self::assertSame([], $this->store->sessions);
    }
}
