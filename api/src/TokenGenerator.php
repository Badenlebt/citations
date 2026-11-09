<?php

declare(strict_types=1);

namespace Citations;

/**
 * Seam producing a fresh session token for a successful login (Auth-API,
 * Requirement 6.1; design "token" data model: CHAR(64) hex).
 *
 * A token is the hex encoding of 32 random bytes, i.e. exactly 64 lowercase
 * hexadecimal characters — the same shape {@see TokenAuthenticator} accepts as
 * well-formed (6.11). Generation is behind an interface so the production
 * implementation can use a cryptographically secure random source while unit
 * tests inject a deterministic double and assert the exact token that ends up
 * in the session row and the Set-Cookie header.
 */
interface TokenGenerator
{
    /**
     * Produce a new session token: 64 lowercase hex characters (6.1).
     */
    public function generate(): string;
}
