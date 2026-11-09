<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Http\Handler;
use Citations\Http\Request;
use Citations\Http\Response;

/**
 * Test double for a {@see Handler}: records the last request and path params it
 * received and returns a pre-set response, or throws a pre-set throwable.
 *
 * Lets the dispatcher/front-controller tests assert that the right handler was
 * invoked with the right extracted `{id}` without any real endpoint logic.
 */
final class RecordingHandler implements Handler
{
    public bool $called = false;
    public ?Request $lastRequest = null;
    /** @var array<string, int> */
    public array $lastPathParams = [];

    public function __construct(
        private readonly Response $response = new Response(200, ['ok' => true]),
        private readonly ?\Throwable $throwable = null,
    ) {
    }

    public function handle(Request $request, array $pathParams = []): Response
    {
        $this->called = true;
        $this->lastRequest = $request;
        $this->lastPathParams = $pathParams;

        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        return $this->response;
    }
}
