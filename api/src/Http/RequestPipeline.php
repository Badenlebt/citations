<?php

declare(strict_types=1);

namespace Citations\Http;

/**
 * A stage in the front-controller request pipeline that turns a routed
 * {@see Request} into a {@see Response} (design "PHP Front-Controller").
 *
 * Both the {@see Dispatcher} (the terminal stage that routes to a handler) and
 * the {@see SessionGuard} (the middleware that rejects unauthenticated writes
 * before dispatch, 7.1/7.5) implement this one method, so the
 * {@see FrontController} can run either interchangeably and the guard can wrap
 * the dispatcher without the controller knowing a guard is present.
 */
interface RequestPipeline
{
    /**
     * Process one request and produce its response.
     */
    public function dispatch(Request $request): Response;
}
