<?php

declare(strict_types=1);

namespace Citations\Http;

/**
 * Orchestrates the request lifecycle for the PHP front controller: run the
 * {@see Dispatcher} inside a single error-mapping boundary and emit the
 * resulting JSON (design "PHP Front-Controller `api/index.php`",
 * Requirements 15.7, 16.3).
 *
 * Every path — a normal handler response, a handler that throws a domain
 * exception, and any unexpected throwable (including a config/DB failure
 * surfaced as {@see \Citations\ConfigException}) — funnels through the same
 * {@see ErrorMapper}, so no failure can escape as an uncaught error that would
 * leak detail (15.7). The actual entry point `api/index.php` stays thin and
 * only builds the {@see Request} and the collaborators, then calls
 * {@see self::run()}.
 */
final class FrontController
{
    public function __construct(
        private readonly RequestPipeline $dispatcher,
        private readonly JsonResponder $responder,
        private readonly ErrorMapper $errorMapper,
    ) {
    }

    /**
     * Handle one request end-to-end: dispatch within the error boundary, then
     * emit the JSON response.
     */
    public function run(Request $request): void
    {
        try {
            $response = $this->dispatcher->dispatch($request);
        } catch (\Throwable $error) {
            $response = $this->errorMapper->toResponse($error);
        }

        $this->responder->send($response);
    }
}
