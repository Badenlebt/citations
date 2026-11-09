<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\CredentialValidator;
use Citations\ErrorCode;
use Citations\RateLimiter;
use Citations\Repository\OperatorRepositoryInterface;
use Citations\Repository\SessionRepositoryInterface;
use Citations\SessionValidator;
use Citations\TokenAuthenticator;
use Citations\TokenGenerator;

/**
 * Auth-API endpoint handler for POST /api/auth/login and POST /api/auth/logout
 * (Requirements 6.1, 6.6, 6.8, 6.9; also 6.2/6.3/6.5 for the login order;
 * design "Auth-API").
 *
 * One handler serves the whole /auth group; it branches by the second path
 * segment (`login` vs `logout`) and the method (POST). These routes are NOT
 * guarded by the {@see SessionGuard}: login must work without a session and
 * logout is a no-op when unauthenticated (confirmed by
 * {@see Dispatcher::isWriteRoute()}, which treats auth as a non-write route).
 *
 * LOGIN enforces the exact order of Requirement 6.9: completeness (6.3) first,
 * then the rate-limit check (6.5), then validity (6.2). Concretely:
 *   1. If the username or password is missing/blank, return 400
 *      MISSING_CREDENTIALS WITHOUT consulting the rate limiter and WITHOUT
 *      recording an attempt (6.3) — a blank request must never influence the
 *      lock window.
 *   2. Else if the username is rate-limited, return 429 RATE_LIMITED BEFORE any
 *      credential verification, even when the supplied creds would be valid
 *      (6.5).
 *   3. Else run {@see CredentialValidator::validate()} against the operator
 *      looked up by username (or null), record the attempt (success/failure)
 *      for the rate-limit window, and on failure return 401 INVALID_CREDENTIALS
 *      (generic, 6.2/6.10 — the pure validator does the timing-balanced compare).
 *   4. On success create a session (token from the injected generator;
 *      createdAt = the injected clock's now; expiresAt via
 *      {@see SessionValidator::expiresAt()} with the configured duration,
 *      1–168h default 24h), persist it, and return 200 with a Set-Cookie header
 *      carrying the token in the HttpOnly session cookie (6.1, 6.4). Multiple
 *      operator accounts are supported, initially one (6.8); the response body
 *      never leaks the password or hash.
 *
 * LOGOUT reads the presented token from the request cookie, ends the session
 * via {@see TokenAuthenticator::logout()} so the token grants no further access
 * (6.6), and returns 200 with a cookie-clearing Set-Cookie. It is idempotent:
 * an absent/unknown token still yields 200.
 *
 * All collaborators are injected through the constructor (no globals) so the
 * handler is driven in unit tests with in-memory repositories, a fixed clock
 * and a deterministic token generator.
 */
final class AuthHandler implements Handler
{
    public function __construct(
        private readonly CredentialValidator $validator,
        private readonly RateLimiter $rateLimiter,
        private readonly OperatorRepositoryInterface $operators,
        private readonly SessionRepositoryInterface $sessions,
        private readonly TokenAuthenticator $authenticator,
        private readonly TokenGenerator $tokens,
        private readonly Clock $clock,
        private readonly ?int $sessionDurationHours,
    ) {
    }

    public function handle(Request $request, array $pathParams = []): Response
    {
        if ($request->method !== 'POST') {
            return $this->notFound();
        }

        $action = $request->segments[1] ?? '';

        return match ($action) {
            'login' => $this->login($request),
            'logout' => $this->logout($request),
            default => $this->notFound(),
        };
    }

    /**
     * POST /api/auth/login enforcing the Requirement 6.9 order
     * (completeness 6.3 -> rate limit 6.5 -> validity 6.2).
     */
    private function login(Request $request): Response
    {
        $username = $this->stringField($request->body, 'username');
        $password = $this->stringField($request->body, 'password');

        // Step 1 (6.3): completeness FIRST, on the raw inputs. A blank request
        // is rejected before the rate limiter is ever consulted and without
        // recording an attempt, so blank traffic can never move the lock window.
        if ($this->isBlank($username) || $this->isBlank($password)) {
            return Response::error(ErrorCode::MISSING_CREDENTIALS, 'Username and password are required.');
        }

        // From here the username is a non-blank string.
        /** @var string $username */

        $now = $this->clock->now();

        // Step 2 (6.5): rate-limit check BEFORE revealing validity. A locked
        // username is rejected with 429 even when the credentials are valid.
        if ($this->rateLimiter->isLocked($username, $now)) {
            return Response::error(ErrorCode::RATE_LIMITED, 'Too many login attempts. Please try again later.');
        }

        // Step 3 (6.2, 6.10): validity. The operator lookup is the only I/O; the
        // pure validator runs the timing-balanced comparison (dummy hash on an
        // unknown username) and never reveals which part was wrong.
        $operator = $this->operators->findByUsername($username);
        $result = $this->validator->validate($username, $password, $operator);

        // Record the attempt outcome for the rate-limit window (6.5): both
        // failures (which count toward the lock) and successes are stored.
        $this->rateLimiter->record($username, $now, $result->isValid);

        if (!$result->isValid) {
            return Response::error(ErrorCode::INVALID_CREDENTIALS, 'Invalid username or password.');
        }

        // Step 4 (6.1, 6.4, 6.8): create and persist the session, then set the
        // HttpOnly cookie carrying the token. The body carries no credential.
        $token = $this->tokens->generate();
        $expiresAt = SessionValidator::expiresAt($now, $this->sessionDurationHours);
        $this->sessions->insert($token, (int) $result->operatorId, $now, $expiresAt);

        return Response::ok(['ok' => true, 'operatorId' => $result->operatorId])
            ->withHeader(SessionCookie::setHeader($token, $now, $expiresAt));
    }

    /**
     * POST /api/auth/logout: end the session for the presented token (6.6) and
     * clear the cookie. Idempotent — an absent/unknown token still returns 200.
     */
    private function logout(Request $request): Response
    {
        $this->authenticator->logout($request->token);

        return Response::ok(['ok' => true])
            ->withHeader(SessionCookie::clearHeader());
    }

    /**
     * Read a body field as a string, or null when absent or not a string. The
     * completeness check (6.3) then treats a missing/non-string field as blank.
     *
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Whether a submitted credential field is missing or, after trimming,
     * empty (6.3) — the same notion the pure {@see CredentialValidator} uses.
     */
    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    private function notFound(): Response
    {
        return Response::error(ErrorCode::NOT_FOUND, 'Resource not found.');
    }
}
