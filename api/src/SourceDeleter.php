<?php

declare(strict_types=1);

namespace Citations;

use Citations\Repository\QuoteRepositoryInterface;
use Citations\Repository\SourceRepositoryInterface;

/**
 * Delete-source mutation of the Autoren-API against the repository boundary
 * (Requirements 11.1, 11.2, 11.3, 11.4; design "`DELETE /api/sources/{id}`").
 *
 * Deletion depends on whether the source still has quotes and, if so, on the
 * chosen {@see SourceDeletionStrategy}:
 *   - No quotes assigned → the source is removed and success returned (11.1).
 *   - Quotes assigned, NO strategy (null) → SOURCE_HAS_QUOTES, the operation is
 *     halted and nothing is changed; the caller must decide between exactly
 *     `reassign` and `cancel` (11.2).
 *   - strategy REASSIGN → every quote of the source is reassigned to the
 *     Unbekannt-Quelle and then the source is removed, as one atomic operation
 *     (11.3). The reassign and the delete happen through the repositories; the
 *     PDO implementation wraps them in a single transaction so no partial state
 *     can persist (design "Transaktionale Integrität", Property 36).
 *   - strategy CANCEL → nothing is changed; the deletion is aborted by
 *     deliberate choice and reported as a (no-op) success, not an error (11.4).
 *
 * Outcomes are returned as a {@see SourceMutationResult} rather than thrown, so
 * the endpoint maps a reason {@see ErrorCode} to its HTTP status uniformly.
 *
 * The Unbekannt-Quelle is undeletable (11.5, 13.1, Property 38): any attempt to
 * delete it — with or without a strategy — is rejected with
 * UNKNOWN_SOURCE_UNDELETABLE before any has-quotes/strategy handling, changing
 * nothing, so exactly one Unbekannt-Quelle always remains. The reserved-name
 * invariant (13.2) lives in {@see SourceCreator}. The Unbekannt-Quelle also
 * serves as the reassign target via
 * {@see SourceRepositoryInterface::findUnknown()}.
 */
final class SourceDeleter
{
    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly QuoteRepositoryInterface $quotes,
    ) {
    }

    /**
     * Delete the source with the given id, applying the strategy only when the
     * source still has quotes.
     *
     * @param int                          $id       Id of the source to delete.
     * @param SourceDeletionStrategy|null  $strategy The chosen strategy, or null
     *                                               when none was supplied (11.2).
     */
    public function delete(int $id, ?SourceDeletionStrategy $strategy = null): SourceMutationResult
    {
        $source = $this->sources->findById($id);

        // A source that does not exist is simply not found; nothing changes.
        if ($source === null) {
            return SourceMutationResult::failure(ErrorCode::NOT_FOUND);
        }

        // (11.5 / 13.1 / Property 38) The Unbekannt-Quelle is undeletable — a
        // delete attempt, with or without a strategy, is rejected before any
        // has-quotes/strategy handling and changes nothing. This also preserves
        // the "exactly one Unbekannt-Quelle" invariant, since the only such row
        // can never be removed.
        if ($source['isUnknown']) {
            return SourceMutationResult::failure(ErrorCode::UNKNOWN_SOURCE_UNDELETABLE);
        }

        $quoteCount = $this->quotes->countBySource($id);

        // (11.1) No quotes assigned: remove the source directly.
        if ($quoteCount === 0) {
            $this->sources->delete($id);

            return SourceMutationResult::success($id);
        }

        // The source has quotes: a strategy is required.
        if ($strategy === null) {
            // (11.2) Halt and change nothing; the caller must choose.
            return SourceMutationResult::failure(ErrorCode::SOURCE_HAS_QUOTES);
        }

        return match ($strategy) {
            // (11.3) Reassign all quotes to the Unbekannt-Quelle, then delete
            // the source — one atomic operation (no quote is lost, Property 36).
            SourceDeletionStrategy::REASSIGN => $this->reassignThenDelete($id),

            // (11.4) Abort: change nothing. Cancelling is a deliberate user
            // choice, not an error — the source and its quotes are left exactly
            // as they were and the (still existing) source id is returned.
            SourceDeletionStrategy::CANCEL => SourceMutationResult::success($id),
        };
    }

    /**
     * Reassign every quote of the source to the Unbekannt-Quelle and then remove
     * the source (Requirement 11.3). The quotes are preserved, only their source
     * assignment changes.
     */
    private function reassignThenDelete(int $id): SourceMutationResult
    {
        $unknownId = $this->sources->findUnknown()['id'];

        $this->quotes->reassignSource($id, $unknownId);
        $this->sources->delete($id);

        return SourceMutationResult::success($id);
    }
}
