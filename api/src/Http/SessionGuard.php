<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\ErrorCode;
use Citations\TokenAuthenticator;

/**
 * Session guard middleware (design "Session-Guard (Middleware)",
 * Requirements 7.1, 7.2, 7.3, 7.5, 7.6).
 *
 * The guard wraps the {@see Dispatcher} as a {@see RequestPipeline} stage and
 * runs BEFORE any handler, i.e. before any content validation of the request
 * data (7.5). For every PROTECTED request it classifies the presented session
 * token per Requirement 6.11 via {@see TokenAuthenticator}: a missing,
 * content-invalid, not-assignable, expired (6.4) or logged-out token is NOT
 * authenticated, so the guard returns `UNAUTHORIZED` (401) and NEVER forwards
 * the request to the dispatcher — no handler runs, so no data is created,
 * changed, deleted or even read (7.1, 7.2, 18.6, 19.6).
 *
 * The protected set ({@see Dispatcher::isProtectedRoute()}) is every WRITE to
 * the Quote/Source/Language APIs PLUS the two MANAGEMENT list reads
 * GET /quotes and GET /sources (18.6, 19.6), which — unlike the other reads —
 * require a session.
 *
 * Because the guard decision is purely structural (method + path segments via
 * {@see Dispatcher::isProtectedRoute()}) and the rejection happens before any
 * resource lookup, the 401 is byte-for-byte identical whether or not the
 * addressed resource exists — no conclusions about the data store can be drawn
 * (7.6).
 *
 * The remaining READ requests (GET /search, GET /random, the item views
 * GET /quotes/{id} and GET /sources/{id}, and the language list GET /languages)
 * and the auth login/logout routes are passed straight through to the
 * dispatcher without requiring a session (7.3), as is a protected request that
 * carries a valid (authenticated, non-expired) session (7.4).
 *
 * The check time is taken from an injected {@see Clock} so expiry decisions
 * stay deterministic under test; production wires {@see SystemClock}.
 */
final class SessionGuard implements RequestPipeline
{
    /** Fixed, generic message for every rejected protected request (7.1, 7.6). */
    public const UNAUTHORIZED_MESSAGE = 'Authentication required.';

    public function __construct(
        private readonly RequestPipeline $next,
        private readonly TokenAuthenticator $authenticator,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Guard the request: reject an unauthenticated protected request with a
     * uniform 401 before dispatch, otherwise forward to the wrapped pipeline
     * (7.1–7.6, 18.6, 19.6).
     */
    public function dispatch(Request $request): Response
    {
        if (!Dispatcher::isProtectedRoute($request->method, $request->segments)) {
            // Public reads and auth routes need no session (7.3).
            return $this->next->dispatch($request);
        }

        if (!$this->authenticator->isAuthenticated($request->token, $this->clock->now())) {
            // Not authenticated per 6.11: deny before any handler/resource
            // lookup so nothing is written or read and the response does not
            // depend on whether the resource exists (7.1, 7.2, 7.6, 18.6, 19.6).
            return Response::error(ErrorCode::UNAUTHORIZED, self::UNAUTHORIZED_MESSAGE);
        }

        // Valid session: execute the protected request (7.4).
        return $this->next->dispatch($request);
    }
}
