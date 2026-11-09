<?php

declare(strict_types=1);

namespace Citations;

/**
 * Immutable outcome of a language mutation such as
 * {@see LanguageCreator::create()} (Requirements 12.3, 12.4, 12.5, 12.6).
 *
 * On success it carries the id of the affected language — on create this is the
 * id of the newly stored language that the Sprach-API returns, which is
 * afterwards part of the available set (12.3). On failure it carries exactly
 * one {@see ErrorCode} and no id; the caller (the Sprach-API endpoint) derives
 * the HTTP status and message from that code via {@see ErrorCode::httpStatus()}
 * / {@see ErrorCode::toResponseBody()}.
 *
 * The result object mirrors {@see QuoteMutationResult} and
 * {@see SourceMutationResult} so the mutation layer surfaces outcomes uniformly
 * without throwing for expected validation failures.
 */
final class LanguageMutationResult
{
    private function __construct(
        public readonly bool $isSuccess,
        public readonly ?int $id,
        public readonly ?ErrorCode $error,
    ) {
    }

    /**
     * A successful mutation carrying the affected language id (on create: the
     * generated id of the stored language, which is afterwards part of the
     * available set, 12.3).
     */
    public static function success(int $id): self
    {
        return new self(true, $id, null);
    }

    /**
     * A failed mutation carrying the single reason code; no language id and no
     * language was stored (12.4, 12.5, 12.6).
     */
    public static function failure(ErrorCode $error): self
    {
        return new self(false, null, $error);
    }
}
