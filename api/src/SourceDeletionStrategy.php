<?php

declare(strict_types=1);

namespace Citations;

/**
 * Strategy for deleting a source that still has quotes assigned to it
 * (Requirements 11.2, 11.3, 11.4; design "`DELETE /api/sources/{id}`").
 *
 * When a source with at least one quote is deleted, the caller must choose
 * exactly one of these two strategies; the absence of a strategy is a distinct
 * state handled by the deleter as the SOURCE_HAS_QUOTES conflict (11.2), not a
 * case of this enum. The frontend surfaces exactly the two variants "Umhängen"
 * (reassign) and "Löschabbruch" (cancel) and submits the choice as `strategy`
 * (11.6).
 */
enum SourceDeletionStrategy: string
{
    /** Reassign all of the source's quotes to the Unbekannt-Quelle, then delete the source (11.3). */
    case REASSIGN = 'reassign';

    /** Abort the deletion and change nothing (11.4). */
    case CANCEL = 'cancel';
}
