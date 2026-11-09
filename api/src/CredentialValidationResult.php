<?php

declare(strict_types=1);

namespace Citations;

/**
 * Immutable outcome of {@see CredentialValidator::validate()} (Requirements
 * 6.2, 6.3, 6.10).
 *
 * On success it carries the authenticated operator's id so the caller can build
 * a session. On failure it carries exactly one ErrorCode — either
 * MISSING_CREDENTIALS (6.3) or the generic INVALID_CREDENTIALS (6.2) — and no
 * operator id. The failure value is identical for the "unknown username" and
 * "wrong password" cases, so the result cannot be used to tell them apart (6.2).
 */
final class CredentialValidationResult
{
    private function __construct(
        public readonly bool $isValid,
        public readonly ?int $operatorId,
        public readonly ?ErrorCode $error,
    ) {
    }

    /**
     * A successful validation for the given authenticated operator id.
     */
    public static function success(int $operatorId): self
    {
        return new self(true, $operatorId, null);
    }

    /**
     * A failed validation carrying the generic reason code (never a detail that
     * distinguishes unknown-username from wrong-password).
     */
    public static function failure(ErrorCode $error): self
    {
        return new self(false, null, $error);
    }
}
