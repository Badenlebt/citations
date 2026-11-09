<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\TokenGenerator;

/**
 * Deterministic {@see TokenGenerator} test double returning a preset token, so
 * the Auth-API login flow's session row and Set-Cookie value can be asserted
 * exactly without any randomness (Requirement 6.1).
 *
 * The default token is a valid 64-lowercase-hex string, matching the shape the
 * production generator produces and {@see \Citations\TokenAuthenticator} treats
 * as well-formed (6.11).
 */
final class FixedTokenGenerator implements TokenGenerator
{
    public function __construct(
        private readonly string $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    ) {
    }

    public function generate(): string
    {
        return $this->token;
    }
}
