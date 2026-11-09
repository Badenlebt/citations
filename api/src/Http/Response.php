<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\ErrorCode;

/**
 * Immutable HTTP response as produced by a handler: an HTTP status code and a
 * JSON-serializable body (design "Antworten sind JSON", Requirements 15.7).
 *
 * The body is whatever the JSON emitter will serialize — typically an
 * associative array, a list, or a scalar. Error responses are built through
 * {@see self::error()} so their status and `{ error, message }` body always
 * come from the single {@see ErrorCode} source of truth, keeping the
 * code -> status mapping consistent across every endpoint (15.7).
 */
final class Response
{
    /**
     * A response may optionally carry extra raw header lines beyond the JSON
     * content type — the Auth-API uses this to attach the `Set-Cookie` line that
     * sets the HttpOnly session cookie on login (6.1) and clears it on logout
     * (6.6). The header list defaults to empty, so every existing success/error
     * response keeps its previous behaviour and the {@see JsonResponder} still
     * only adds the content type for them.
     *
     * @param int                $status  HTTP status code.
     * @param mixed              $body    JSON-serializable response body.
     * @param array<int, string> $headers Extra raw header lines (e.g. a
     *                                     `Set-Cookie: ...` line) emitted after
     *                                     the JSON content type. Defaults to none.
     */
    public function __construct(
        public readonly int $status,
        public readonly mixed $body = null,
        public readonly array $headers = [],
    ) {
    }

    /**
     * Return a copy of this response with one extra raw header line appended
     * (e.g. a `Set-Cookie` line), leaving status and body untouched. Used by the
     * Auth-API to attach the session cookie to the login/logout response (6.1,
     * 6.6).
     */
    public function withHeader(string $headerLine): self
    {
        return new self($this->status, $this->body, [...$this->headers, $headerLine]);
    }

    /**
     * A successful response with an explicit status (defaults to 200).
     */
    public static function ok(mixed $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    /**
     * A `201 Created` response.
     */
    public static function created(mixed $body): self
    {
        return new self(201, $body);
    }

    /**
     * An error response whose status and body are both derived from the given
     * {@see ErrorCode}, so the mapping stays uniform (15.7). The body is exactly
     * the `{ error, message }` shape; no internal detail is ever added.
     */
    public static function error(ErrorCode $code, string $message): self
    {
        return new self($code->httpStatus(), $code->toResponseBody($message));
    }
}
