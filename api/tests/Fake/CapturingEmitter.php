<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Http\ResponseEmitter;

/**
 * Test double for a {@see ResponseEmitter} that captures the emitted status,
 * headers and body instead of sending real HTTP output, so the JSON responder
 * and front-controller can be asserted without side effects.
 */
final class CapturingEmitter implements ResponseEmitter
{
    public ?int $status = null;
    /** @var array<int, string> */
    public array $headers = [];
    public ?string $body = null;

    public function emit(int $status, array $headers, string $body): void
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }
}
