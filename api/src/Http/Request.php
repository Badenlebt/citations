<?php

declare(strict_types=1);

namespace Citations\Http;

/**
 * Immutable value object describing a single incoming HTTP request as the
 * dispatcher sees it (design "PHP Front-Controller `api/index.php`",
 * Requirements 16.3).
 *
 * The object carries only the normalized facts the router needs: the HTTP
 * method (upper-cased), the request path with the script/`/api` prefix already
 * stripped (so routing works identically whether the app is mounted at the web
 * root or in a subdirectory, 16.3), the exploded path segments, the query
 * parameters and the decoded JSON request body (an empty array when there is
 * no body or it is not a JSON object).
 *
 * Construction is split so the class stays testable without real superglobals:
 * the constructor takes explicit, already-normalized values, while
 * {@see self::fromGlobals()} is the thin adapter that reads PHP's request
 * environment and `php://input`.
 */
final class Request
{
    /** @var list<string> */
    public readonly array $segments;

    /**
     * @param string               $method The upper-cased HTTP method.
     * @param string               $path   The path under the API root, leading
     *                                      slash optional, e.g. `quotes/7`.
     * @param array<string, mixed> $query  Decoded query parameters.
     * @param array<string, mixed> $body   Decoded JSON body (object => assoc
     *                                      array); empty when absent/not an object.
     * @param string|null          $token  The raw session token presented with
     *                                      the request (from the HttpOnly session
     *                                      cookie), or null when none was sent.
     *                                      The session guard classifies it per
     *                                      6.11 before any write executes (7.1).
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly ?string $token = null,
    ) {
        $this->segments = self::splitPath($path);
    }

    /**
     * Build a request from PHP's superglobals and raw input stream.
     *
     * The API-relative path is derived by stripping, in order, the directory of
     * the running script (so a subdirectory mount is transparent) and a leading
     * `api` segment, from the request URI path (16.3). The JSON body is decoded
     * only for methods that carry one; a malformed or non-object body yields an
     * empty array rather than throwing, leaving per-endpoint validation to the
     * handlers.
     *
     * The session token, if any, is read from the HttpOnly session cookie
     * (named {@see SessionCookie::NAME}); the session guard later classifies it
     * per 6.11 and rejects unauthenticated writes before any handler runs (7.1,
     * 7.5). A missing cookie yields a null token, which the guard treats as
     * "not authenticated".
     *
     * @param array<string, mixed>|null $server  Defaults to $_SERVER.
     * @param array<string, mixed>|null $query   Defaults to $_GET.
     * @param string|null               $rawBody Raw request body; defaults to php://input.
     * @param array<string, mixed>|null $cookies Request cookies; defaults to $_COOKIE.
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $query = null,
        ?string $rawBody = null,
        ?array $cookies = null,
    ): self {
        $server = $server ?? $_SERVER;
        $query = $query ?? $_GET;
        $cookies = $cookies ?? $_COOKIE;

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        $uriPath = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $uriPath = is_string($uriPath) ? $uriPath : '/';

        $scriptDir = str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '')));
        $path = self::normalizePath($uriPath, $scriptDir);

        if ($rawBody === null) {
            $raw = file_get_contents('php://input');
            $rawBody = $raw === false ? '' : $raw;
        }

        return new self($method, $path, $query, self::decodeBody($rawBody), self::readToken($cookies));
    }

    /**
     * Extract the session token from the request cookies, reading only the one
     * cookie named {@see SessionCookie::NAME} (the HttpOnly session cookie set
     * at login, 6.1). Returns null when the cookie is absent or not a string;
     * the token's shape/validity is not judged here — that is the session
     * guard's job via token classification (6.11, 7.1).
     *
     * @param array<string, mixed> $cookies
     */
    private static function readToken(array $cookies): ?string
    {
        $token = $cookies[SessionCookie::NAME] ?? null;

        return is_string($token) ? $token : null;
    }

    /**
     * Strip the script directory prefix and a leading `api` segment from the
     * raw URI path, returning the API-relative path without surrounding slashes
     * (16.3).
     */
    private static function normalizePath(string $uriPath, string $scriptDir): string
    {
        $path = '/' . ltrim($uriPath, '/');
        $scriptDir = '/' . trim($scriptDir, '/');

        if ($scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }

        $path = trim($path, '/');

        if ($path === 'api') {
            return '';
        }
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        return trim($path, '/');
    }

    /**
     * Decode a raw request body as a JSON object into an associative array.
     * Anything that is not a JSON object (empty, scalar, array, or malformed)
     * becomes an empty array, leaving validation to the handlers.
     *
     * @return array<string, mixed>
     */
    private static function decodeBody(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Explode a path into its non-empty segments.
     *
     * @return list<string>
     */
    private static function splitPath(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return [];
        }

        return array_values(array_filter(
            explode('/', $trimmed),
            static fn (string $segment): bool => $segment !== '',
        ));
    }
}
