<?php

declare(strict_types=1);

namespace Citations;

/**
 * Immutable outcome of a source mutation such as {@see SourceCreator::create()}
 * and {@see SourceCreator::edit()} (Requirements 10.1, 10.6).
 *
 * On success it carries the id of the affected source — on create this is the
 * id of the newly stored source that the Autoren-API returns (10.1); on edit it
 * is the id of the overwritten source (10.6). On failure it carries exactly one
 * {@see ErrorCode} and no id; the caller (the Autoren-API endpoint) derives the
 * HTTP status and message from that code via {@see ErrorCode::httpStatus()} /
 * {@see ErrorCode::toResponseBody()}.
 *
 * The result object mirrors {@see QuoteMutationResult} so the mutation layer
 * surfaces outcomes uniformly without throwing for expected validation
 * failures.
 */
final class SourceMutationResult
{
    private function __construct(
        public readonly bool $isSuccess,
        public readonly ?int $id,
        public readonly ?ErrorCode $error,
    ) {
    }

    /**
     * A successful mutation carrying the affected source id (on create: the
     * generated id of the stored source, 10.1; on edit: the overwritten
     * source's id, 10.6).
     */
    public static function success(int $id): self
    {
        return new self(true, $id, null);
    }

    /**
     * A failed mutation carrying the single reason code; no source id and no
     * data was changed.
     */
    public static function failure(ErrorCode $error): self
    {
        return new self(false, null, $error);
    }
}
