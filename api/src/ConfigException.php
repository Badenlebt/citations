<?php

declare(strict_types=1);

namespace Citations;

/**
 * Raised when the setup configuration / credential source cannot be used to
 * build a database connection (Requirements 15.2, 15.6; design "Fehlende
 * Konfiguration / DB-Ausfall").
 *
 * This is the single failure signal for two situations the request bootstrap
 * must abort on BEFORE any DB connect is attempted:
 *   - a required credential field (host/address, database name, user or
 *     password) is missing or blank (15.2), and
 *   - the credential source itself is missing or unreadable (15.6).
 *
 * Every instance maps to the generic {@see ErrorCode::INTERNAL_ERROR} (HTTP
 * 500). The message is a fixed, generic string that deliberately never names a
 * specific credential field value, nor the path or name of the credential
 * source, so nothing sensitive can leak into a log, response, or stack trace
 * frame built from it (15.2, 15.6). Callers translate this into the uniform
 * `{ error, message }` body without adding any detail.
 */
final class ConfigException extends \RuntimeException
{
    /**
     * The error code every configuration failure maps to.
     */
    public function errorCode(): ErrorCode
    {
        return ErrorCode::INTERNAL_ERROR;
    }

    /**
     * A required credential field (host/address, database name, user or
     * password) was absent or blank at request start (15.2).
     *
     * The message names only the generic notion "a required field", never which
     * field or its value.
     */
    public static function missingCredentialField(): self
    {
        return new self('Configuration is incomplete.');
    }

    /**
     * The credential source was missing or could not be read (15.6).
     *
     * The message never discloses the path or name of the source, nor whether
     * it was absent versus unreadable, so no location information leaks.
     */
    public static function unreadableSource(): self
    {
        return new self('Configuration is unavailable.');
    }
}
