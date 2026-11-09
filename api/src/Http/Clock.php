<?php

declare(strict_types=1);

namespace Citations\Http;

use DateTimeImmutable;

/**
 * Supplier of the current instant, injected wherever a time-dependent decision
 * must stay deterministic under test (design "Session-Guard"; session expiry
 * per 6.4).
 *
 * The session guard evaluates token expiry against "now" (6.11/7.2); taking the
 * instant through this seam — rather than calling `new DateTimeImmutable('now')`
 * inline — lets tests pin a fixed check time so an expired versus a still-valid
 * session is exercised without any sleeping or wall-clock flakiness.
 */
interface Clock
{
    /**
     * The instant to treat as "now" for this request.
     */
    public function now(): DateTimeImmutable;
}
