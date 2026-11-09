<?php

declare(strict_types=1);

namespace Citations;

/**
 * Immutable outcome of a quote mutation such as {@see QuoteCreator::create()}
 * (Requirements 8.1, 8.3, 8.4, 13.3).
 *
 * On success it carries the id of the affected quote — on create this is the id
 * of the newly stored quote that the Zitat-API returns (8.1). On failure it
 * carries exactly one {@see ErrorCode} and no id; the caller (the Zitat-API
 * endpoint, task 12.6) derives the HTTP status and message from that code via
 * {@see ErrorCode::httpStatus()} / {@see ErrorCode::toResponseBody()}.
 *
 * The result object mirrors {@see CredentialValidationResult} so the mutation
 * layer surfaces outcomes uniformly without throwing for expected validation
 * failures.
 */
final class QuoteMutationResult
{
    private function __construct(
        public readonly bool $isSuccess,
        public readonly ?int $id,
        public readonly ?ErrorCode $error,
    ) {
    }

    /**
     * A successful mutation carrying the affected quote id (on create: the
     * generated id of the stored quote, 8.1).
     */
    public static function success(int $id): self
    {
        return new self(true, $id, null);
    }

    /**
     * A failed mutation carrying the single reason code; no quote id and, on
     * create, no quote was stored (8.3, 8.4).
     */
    public static function failure(ErrorCode $error): self
    {
        return new self(false, null, $error);
    }
}
