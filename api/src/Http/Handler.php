<?php

declare(strict_types=1);

namespace Citations\Http;

/**
 * The seam between the dispatcher and a per-API endpoint group (design "PHP
 * REST-API": Such-, Zitat-, Autoren-, Sprach- und Auth-API).
 *
 * A handler receives the routed {@see Request} — including any extracted path
 * parameters exposed through the dispatcher — and returns a {@see Response}.
 * The front controller (task 12.1) ships only the dispatch and JSON/error
 * plumbing against this interface; the concrete Search/Quote/Source/Language/
 * Auth handlers are registered by later tasks (12.5/12.6/12.8/12.10/12.12).
 *
 * A handler may signal an error either by returning {@see Response::error()} or
 * by throwing an exception that exposes an {@see \Citations\ErrorCode}; any
 * other throwable is mapped to a generic internal error by the dispatcher
 * boundary (15.7).
 */
interface Handler
{
    /**
     * Handle a routed request and produce a response.
     *
     * @param Request              $request    The routed request.
     * @param array<string, int>   $pathParams Extracted path parameters such as
     *                                          `['id' => 7]` for `/quotes/{id}`.
     */
    public function handle(Request $request, array $pathParams = []): Response;
}
