<?php

declare(strict_types=1);

namespace Citations;

/**
 * Classification of a presented authentication token per Requirement 6.11 and
 * the session-guard rules 7.1/7.2 (design "Session-Guard").
 *
 * Exactly one case describes an authenticated request ({@see self::AUTHENTICATED});
 * every other case means "not authenticated" and MUST lead to access being
 * denied (`UNAUTHORIZED`, 401) without any data change. The distinct not-
 * authenticated cases exist only to make the reason explicit and testable — the
 * guard treats them uniformly and the response never reveals which one applied,
 * so no conclusions about token or resource existence can be drawn (6.11).
 */
enum TokenClassification
{
    /**
     * No token was presented at all (missing cookie/header). Not authenticated
     * (6.11: "fehlend").
     */
    case MISSING;

    /**
     * A token was presented but is not well-formed (wrong shape/characters), so
     * it can never match a stored session. Not authenticated (6.11: "inhaltlich
     * ungültig").
     */
    case INVALID;

    /**
     * A well-formed token that matches no stored session — never issued, or
     * already ended by logout. Not authenticated (6.11: "keiner Session
     * zuordenbar" / "beendet").
     */
    case NOT_ASSIGNABLE;

    /**
     * A token whose session exists but whose configured validity duration has
     * elapsed at the check time (6.4). Not authenticated (6.11: "abgelaufen").
     */
    case EXPIRED;

    /**
     * A well-formed token whose non-expired session exists and is assignable to
     * an operator. This is the ONLY case that counts as authenticated (7.4).
     */
    case AUTHENTICATED;

    /**
     * Whether this classification grants access. True only for
     * {@see self::AUTHENTICATED}; every other case is "not authenticated" and
     * must be rejected with `UNAUTHORIZED` (6.11, 7.1, 7.2).
     */
    public function isAuthenticated(): bool
    {
        return $this === self::AUTHENTICATED;
    }
}
