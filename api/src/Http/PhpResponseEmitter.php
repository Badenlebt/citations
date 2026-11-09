<?php

declare(strict_types=1);

namespace Citations\Http;

/**
 * The production {@see ResponseEmitter}: applies the HTTP status, sends the
 * headers and writes the body using PHP's native output primitives.
 *
 * This is the only place the real `http_response_code()`, `header()` and output
 * side effects live, so the rest of the HTTP layer stays pure and testable. It
 * is exercised by `api/index.php` at runtime rather than in unit tests, which
 * inject a capturing fake instead.
 *
 * @codeCoverageIgnore
 */
final class PhpResponseEmitter implements ResponseEmitter
{
    public function emit(int $status, array $headers, string $body): void
    {
        http_response_code($status);

        foreach ($headers as $header) {
            header($header, true);
        }

        echo $body;
    }
}
