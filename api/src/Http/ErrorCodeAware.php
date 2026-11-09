<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\ErrorCode;

/**
 * Marker for a domain exception that carries a known {@see ErrorCode} and a
 * frontend-safe, generic message (design "Zuordnung Fehlercode -> Statuscode",
 * Requirements 15.7).
 *
 * The error boundary maps such an exception to its code, HTTP status and
 * `{ error, message }` body. Any throwable that is NOT error-code-aware is
 * treated as unexpected and mapped to a generic internal error whose body
 * discloses nothing from the original exception (no SQL, DSN, credentials, or
 * stack trace).
 *
 * {@see \Citations\ConfigException} already exposes a matching
 * `errorCode(): ErrorCode` method and is recognized by the boundary via that
 * method even though it predates this interface, so future domain exceptions
 * can either implement this interface or expose the same method.
 */
interface ErrorCodeAware
{
    public function errorCode(): ErrorCode;
}
