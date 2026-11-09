<?php

declare(strict_types=1);

namespace Citations\Http;

/**
 * Serializes a {@see Response} to a JSON body and emits it with the matching
 * HTTP status and `application/json` content type (design "Antworten sind
 * JSON", Requirements 15.7).
 *
 * The serialization (status, header line, JSON encoding) is pure and lives in
 * {@see self::serialize()}, so it can be asserted directly in a unit test; the
 * real `header()`/`echo` side effects are delegated to an injected
 * {@see ResponseEmitter}. For error responses the status already comes from the
 * {@see \Citations\ErrorCode} baked into the response body by
 * {@see Response::error()}, so this component never re-derives a mapping — it
 * just transports what the response carries.
 */
final class JsonResponder
{
    private const CONTENT_TYPE = 'application/json; charset=utf-8';

    public function __construct(
        private readonly ResponseEmitter $emitter,
    ) {
    }

    /**
     * Serialize and emit the response through the injected emitter.
     */
    public function send(Response $response): void
    {
        [$status, $headers, $body] = $this->serialize($response);

        $this->emitter->emit($status, $headers, $body);
    }

    /**
     * Produce the status, header lines and JSON body for a response, without
     * any side effect. A `null` body serializes to an empty JSON object so the
     * content type always holds.
     *
     * The JSON content type comes first, then any extra raw header lines the
     * response carries (e.g. the Auth-API's `Set-Cookie` line for the HttpOnly
     * session cookie on login/logout, 6.1/6.6). Responses with no extra headers
     * — i.e. every existing endpoint — serialize exactly as before, with the
     * content type as the sole header.
     *
     * @return array{0: int, 1: array<int, string>, 2: string}
     */
    public function serialize(Response $response): array
    {
        $payload = $response->body ?? new \stdClass();

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if ($json === false) {
            $json = '{}';
        }

        return [
            $response->status,
            ['Content-Type: ' . self::CONTENT_TYPE, ...$response->headers],
            $json,
        ];
    }
}
