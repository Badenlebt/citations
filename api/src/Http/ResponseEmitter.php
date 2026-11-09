<?php

declare(strict_types=1);

namespace Citations\Http;

/**
 * Seam for the side effects of sending a {@see Response}: setting the HTTP
 * status, headers and writing the body (design "Antworten sind JSON").
 *
 * Isolating the actual `http_response_code()`/`header()`/`echo` calls behind an
 * interface keeps the serialization logic unit-testable without emitting real
 * headers; tests inject a capturing fake instead.
 */
interface ResponseEmitter
{
    /**
     * Emit a fully serialized response: apply the status code, send the headers
     * (as `name: value` strings), and write the body.
     *
     * @param array<int, string> $headers
     */
    public function emit(int $status, array $headers, string $body): void;
}
