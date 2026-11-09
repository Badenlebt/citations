<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\ErrorCode;

/**
 * Maps a routed {@see Request} (method + API-relative path) to one of the five
 * API-group handlers and invokes it (design "FC --> SearchAPI & QuoteAPI &
 * AuthorAPI & AuthAPI", Requirements 16.3).
 *
 * Handlers are INJECTED by key, not constructed here: task 12.1 ships the
 * dispatch skeleton and its route table, while later tasks register the real
 * Search/Quote/Source/Language/Auth handlers (12.5/12.6/12.8/12.10/12.12). The
 * route table pairs each known (method, path-pattern) with a handler key and,
 * for the collection-item routes, the name of the `{id}` path parameter to
 * extract. The path is already normalized by {@see Request} so the dispatcher
 * is agnostic to any subdirectory mount (16.3).
 *
 * Resolution outcomes:
 *   - A matching route whose handler is registered is invoked with the request
 *     and the extracted path params.
 *   - A path that matches no known pattern yields {@see ErrorCode::NOT_FOUND}.
 *   - A path that matches a known resource but with an unsupported method also
 *     yields NOT_FOUND (the API does not distinguish 405 in its error table,
 *     design "Zuordnung Fehlercode -> Statuscode").
 *   - A matched route whose handler is not (yet) registered yields a generic
 *     {@see ErrorCode::INTERNAL_ERROR}, since that is a wiring fault.
 */
final class Dispatcher implements RequestPipeline
{
    /** Handler keys for the five API groups. */
    public const SEARCH = 'search';
    public const QUOTES = 'quotes';
    public const SOURCES = 'sources';
    public const LANGUAGES = 'languages';
    public const AUTH = 'auth';

    /**
     * @param array<string, Handler> $handlers Handler keyed by API-group key
     *                                          (see the class constants).
     */
    public function __construct(
        private readonly array $handlers,
    ) {
    }

    /**
     * Whether a (method, segments) pair names a WRITE operation on the
     * Quote/Source/Language APIs that the session guard must protect BEFORE
     * execution (Requirements 7.1, 7.2, 7.5).
     *
     * This is kept here, beside {@see self::match()}, so the guard's notion of
     * "what is a write" cannot drift from the router's route table. The write
     * set is exactly:
     *   - POST /quotes, PUT|DELETE /quotes/{id}
     *   - POST /sources, PUT|DELETE /sources/{id}
     *   - POST /languages
     * Every GET is a read (7.3) and the auth login/logout routes are never
     * guarded (they establish/end the session themselves).
     *
     * The decision is purely structural: it does not require the id to resolve
     * to an existing resource, so an unauthenticated write is rejected
     * identically whether or not the addressed resource exists (7.6).
     *
     * @param list<string> $segments
     */
    public static function isWriteRoute(string $method, array $segments): bool
    {
        $count = count($segments);
        $resource = $segments[0] ?? '';

        if ($resource === 'quotes' || $resource === 'sources') {
            if ($count === 1) {
                return $method === 'POST';
            }
            if ($count === 2) {
                return in_array($method, ['PUT', 'DELETE'], true);
            }

            return false;
        }

        if ($resource === 'languages' && $count === 1) {
            return $method === 'POST';
        }

        return false;
    }

    /**
     * Whether a (method, segments) pair names a route the session guard must
     * protect BEFORE execution, i.e. one that requires a valid session
     * (Requirements 7.1, 7.2, 7.5, 18.6, 19.6).
     *
     * This is the superset the {@see SessionGuard} actually consults. It is the
     * entire WRITE set of {@see self::isWriteRoute()} PLUS the two MANAGEMENT
     * list reads that are not public, unlike the search/single-view/language
     * reads:
     *   - GET /quotes  (list all quotes for management, 18.6)
     *   - GET /sources (list all sources for management, 19.6)
     * Every OTHER read stays public (7.3): GET /quotes/{id}, GET /sources/{id},
     * GET /search, GET /random, GET /languages, and the auth login/logout
     * routes are never guarded.
     *
     * Like {@see self::isWriteRoute()} the decision is purely structural: it
     * does not require any addressed id to resolve to an existing resource, so
     * an unauthenticated request is rejected identically whether or not the
     * resource exists (7.6).
     *
     * @param list<string> $segments
     */
    public static function isProtectedRoute(string $method, array $segments): bool
    {
        if (self::isWriteRoute($method, $segments)) {
            return true;
        }

        // The management list reads are the only guarded GETs: the quotes and
        // sources COLLECTIONS (exactly one segment), not their items (18.6,
        // 19.6).
        $count = count($segments);
        $resource = $segments[0] ?? '';

        return $count === 1
            && $method === 'GET'
            && ($resource === 'quotes' || $resource === 'sources');
    }

    /**
     * Route the request to a handler and return its response, or a uniform
     * error response when no route matches / the handler is missing.
     */
    public function dispatch(Request $request): Response
    {
        $segments = $request->segments;
        $method = $request->method;

        $match = $this->match($method, $segments);
        if ($match === null) {
            return Response::error(ErrorCode::NOT_FOUND, 'Resource not found.');
        }

        [$handlerKey, $pathParams] = $match;

        $handler = $this->handlers[$handlerKey] ?? null;
        if (!$handler instanceof Handler) {
            // A known route with no registered handler is a wiring fault, not a
            // client error: surface it generically (15.7).
            return Response::error(ErrorCode::INTERNAL_ERROR, 'An internal error occurred.');
        }

        return $handler->handle($request, $pathParams);
    }

    /**
     * Resolve the (method, segments) pair to a `[handlerKey, pathParams]` tuple,
     * or null when nothing matches.
     *
     * @param list<string> $segments
     * @return array{0: string, 1: array<string, int>}|null
     */
    private function match(string $method, array $segments): ?array
    {
        $count = count($segments);
        $resource = $segments[0] ?? '';

        // Search-API: GET /search, GET /random.
        if ($count === 1 && ($resource === 'search' || $resource === 'random') && $method === 'GET') {
            return [self::SEARCH, []];
        }

        // Quote-API and Author/Source-API share the collection/item shape.
        foreach (['quotes' => self::QUOTES, 'sources' => self::SOURCES] as $name => $key) {
            if ($resource !== $name) {
                continue;
            }

            if ($count === 1) {
                // Collection: GET (list), POST (create).
                return in_array($method, ['GET', 'POST'], true) ? [$key, []] : null;
            }

            if ($count === 2) {
                // Item: GET, PUT, DELETE on /{resource}/{id}.
                $id = self::parseId($segments[1]);
                if ($id === null || !in_array($method, ['GET', 'PUT', 'DELETE'], true)) {
                    return null;
                }

                return [$key, ['id' => $id]];
            }

            return null;
        }

        // Language-API: GET /languages (list), POST /languages (create).
        if ($count === 1 && $resource === 'languages') {
            return in_array($method, ['GET', 'POST'], true) ? [self::LANGUAGES, []] : null;
        }

        // Auth-API: POST /auth/login, POST /auth/logout.
        if ($count === 2 && $resource === 'auth' && $method === 'POST'
            && in_array($segments[1], ['login', 'logout'], true)
        ) {
            return [self::AUTH, []];
        }

        return null;
    }

    /**
     * Parse a positive integer path id, or null when the segment is not a
     * positive integer (so a non-numeric `{id}` is simply NOT_FOUND).
     */
    private static function parseId(string $segment): ?int
    {
        if ($segment === '' || !ctype_digit($segment)) {
            return null;
        }

        $id = (int) $segment;

        return $id > 0 ? $id : null;
    }
}
