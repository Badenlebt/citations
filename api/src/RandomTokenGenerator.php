<?php

declare(strict_types=1);

namespace Citations;

/**
 * Production {@see TokenGenerator} backed by a cryptographically secure random
 * source (Auth-API, Requirement 6.1).
 *
 * Each call returns the hex encoding of `random_bytes(32)`, i.e. 64 lowercase
 * hexadecimal characters that no client can predict — matching the token shape
 * {@see TokenAuthenticator} treats as well-formed (6.11) and the CHAR(64) hex
 * column in the data model. `random_bytes()` throws on an insufficient entropy
 * source; that failure propagates to the front-controller error boundary and
 * surfaces as a generic 500 (15.7) rather than yielding a weak token.
 */
final class RandomTokenGenerator implements TokenGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
