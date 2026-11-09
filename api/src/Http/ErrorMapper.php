<?php

declare(strict_types=1);

namespace Citations\Http;

use Citations\ErrorCode;

/**
 * The single catch-all that turns any throwable raised while handling a request
 * into a uniform error {@see Response} (design "Zuordnung Fehlercode ->
 * Statuscode", Requirements 15.7).
 *
 * Two cases, deliberately distinct:
 *   1. An error-code-aware exception (one implementing {@see ErrorCodeAware} or
 *      exposing an `errorCode(): ErrorCode` method, e.g.
 *      {@see \Citations\ConfigException}) is mapped to its own code, status and
 *      a generic message. Its own message is used only when it is safe — these
 *      exceptions are authored to carry a fixed, non-sensitive text — otherwise
 *      a generic per-code message is substituted.
 *   2. ANY other throwable (PDOException, TypeError, a raw RuntimeException,
 *      etc.) is unexpected. It is mapped to {@see ErrorCode::INTERNAL_ERROR}
 *      (500) with a fixed generic message. The original message, SQL, DSN,
 *      credentials and stack trace are never read into the response, so nothing
 *      sensitive can leak (15.7).
 */
final class ErrorMapper
{
    /** Fixed, non-sensitive message for any unexpected failure (15.7). */
    public const GENERIC_INTERNAL_MESSAGE = 'An internal error occurred.';

    /**
     * Map a throwable to a uniform error response.
     */
    public function toResponse(\Throwable $error): Response
    {
        $code = $this->extractErrorCode($error);

        if ($code === null) {
            // Unexpected failure: never surface the original detail (15.7).
            return Response::error(ErrorCode::INTERNAL_ERROR, self::GENERIC_INTERNAL_MESSAGE);
        }

        if ($code === ErrorCode::INTERNAL_ERROR) {
            // Even a known internal error gets the fixed generic message rather
            // than the exception's own text, as a belt-and-braces guard (15.7).
            return Response::error(ErrorCode::INTERNAL_ERROR, self::GENERIC_INTERNAL_MESSAGE);
        }

        return Response::error($code, $error->getMessage());
    }

    /**
     * Read the {@see ErrorCode} carried by an exception, or null when it is an
     * ordinary/unexpected throwable. Recognizes both the {@see ErrorCodeAware}
     * interface and the pre-existing `errorCode()` method shape.
     */
    private function extractErrorCode(\Throwable $error): ?ErrorCode
    {
        if ($error instanceof ErrorCodeAware) {
            return $error->errorCode();
        }

        if (method_exists($error, 'errorCode')) {
            $code = $error->errorCode();

            return $code instanceof ErrorCode ? $code : null;
        }

        return null;
    }
}
