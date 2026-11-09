<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Http\Request;
use Citations\Http\RequestPipeline;
use Citations\Http\Response;

/**
 * Test double for a {@see RequestPipeline} stage (e.g. the dispatcher the
 * {@see \Citations\Http\SessionGuard} wraps): records whether it was invoked
 * and with which request, and returns a pre-set response.
 *
 * Used by the session-guard tests to assert that an unauthenticated write is
 * rejected BEFORE dispatch — i.e. that `dispatch()` was never called, so no
 * handler ran and nothing could be created, changed or deleted (7.1, 7.2) — and
 * conversely that a valid session forwards the request and returns the wrapped
 * response unchanged (7.3, 7.4).
 */
final class RecordingPipeline implements RequestPipeline
{
    public bool $called = false;
    public ?Request $lastRequest = null;

    public function __construct(
        private readonly Response $response = new Response(200, ['ok' => true]),
    ) {
    }

    public function dispatch(Request $request): Response
    {
        $this->called = true;
        $this->lastRequest = $request;

        return $this->response;
    }
}
