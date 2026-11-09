<?php

declare(strict_types=1);

namespace Citations;

/**
 * Central, authoritative definition of every API error code and the HTTP
 * status it maps to (design.md "Zuordnung Fehlercode -> Statuscode").
 *
 * The enum case value is the machine-readable, stable error code string that
 * ends up in the uniform error payload `{ error, message }`. {@see httpStatus()}
 * is the single place from which endpoints derive the status for a code, so the
 * mapping stays consistent across the whole API (Requirements 15.7).
 */
enum ErrorCode: string
{
    case MISSING_QUERY = 'MISSING_QUERY';
    case QUERY_TOO_LONG = 'QUERY_TOO_LONG';
    case MISSING_TEXT = 'MISSING_TEXT';
    case INVALID_SOURCE = 'INVALID_SOURCE';
    case INVALID_LANGUAGE = 'INVALID_LANGUAGE';
    case MISSING_NAME = 'MISSING_NAME';
    case INVALID_LINK_URL = 'INVALID_LINK_URL';
    case INPUT_TOO_LONG = 'INPUT_TOO_LONG';
    case MISSING_LANGUAGE_NAME = 'MISSING_LANGUAGE_NAME';
    case UNKNOWN_SOURCE_UNDELETABLE = 'UNKNOWN_SOURCE_UNDELETABLE';
    case MISSING_CREDENTIALS = 'MISSING_CREDENTIALS';
    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    case UNAUTHORIZED = 'UNAUTHORIZED';
    case NOT_FOUND = 'NOT_FOUND';
    case NO_QUOTES = 'NO_QUOTES';
    case SOURCE_HAS_QUOTES = 'SOURCE_HAS_QUOTES';
    case UNKNOWN_SOURCE_RESERVED = 'UNKNOWN_SOURCE_RESERVED';
    case LANGUAGE_EXISTS = 'LANGUAGE_EXISTS';
    case RATE_LIMITED = 'RATE_LIMITED';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';

    /**
     * The HTTP status code mapped to this error code.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::MISSING_QUERY,
            self::QUERY_TOO_LONG,
            self::MISSING_TEXT,
            self::INVALID_SOURCE,
            self::INVALID_LANGUAGE,
            self::MISSING_NAME,
            self::INVALID_LINK_URL,
            self::INPUT_TOO_LONG,
            self::MISSING_LANGUAGE_NAME,
            self::UNKNOWN_SOURCE_UNDELETABLE,
            self::MISSING_CREDENTIALS => 400,

            self::INVALID_CREDENTIALS,
            self::UNAUTHORIZED => 401,

            self::NOT_FOUND,
            self::NO_QUOTES => 404,

            self::SOURCE_HAS_QUOTES,
            self::UNKNOWN_SOURCE_RESERVED,
            self::LANGUAGE_EXISTS => 409,

            self::RATE_LIMITED => 429,

            self::INTERNAL_ERROR => 500,
        };
    }

    /**
     * Build the uniform error response body for this code.
     *
     * The payload is intentionally limited to exactly the two public keys
     * `error` (this stable code) and `message` (a human-readable, frontend
     * display text). It never carries internal details such as stack traces,
     * SQL or credentials.
     *
     * @return array{error: string, message: string}
     */
    public function toResponseBody(string $message): array
    {
        return [
            'error' => $this->value,
            'message' => $message,
        ];
    }
}
