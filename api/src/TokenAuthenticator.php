<?php

declare(strict_types=1);

namespace Citations;

use Citations\Repository\SessionRepositoryInterface;
use DateTimeImmutable;

/**
 * Token classification (Requirement 6.11) and logout (6.6) for the Auth-API and
 * the session guard (design "Session-Guard", "POST /api/auth/logout").
 *
 * {@see self::classify()} maps a presented token to exactly one
 * {@see TokenClassification}: a missing, malformed, unassignable (no matching
 * session — never issued or already ended), or expired token is NOT
 * authenticated; only a present, well-formed, assignable, non-expired session
 * counts as authenticated (6.11, 7.1, 7.2, 7.4). The guard denies every
 * non-authenticated case with `UNAUTHORIZED` and makes no data change.
 *
 * {@see self::logout()} deletes the session row via the repository so the token
 * becomes unassignable and grants no further access (6.6): a subsequent
 * classify() of the same token returns {@see TokenClassification::NOT_ASSIGNABLE}.
 *
 * The expiry decision is kept deterministic by injecting the check time; the
 * only I/O is the session lookup/delete behind
 * {@see SessionRepositoryInterface}. Expiry is evaluated against the session's
 * stored `expiresAt`, which {@see SessionValidator::expiresAt()} derived from
 * the creation time and the clamped duration at login — so "valid" means the
 * same thing here as in {@see SessionValidator::isValid()}: checkTime strictly
 * before expiry (6.1, 6.4).
 */
final class TokenAuthenticator
{
    public function __construct(
        private readonly SessionRepositoryInterface $sessions
    ) {
    }

    /**
     * Classify a presented token per Requirement 6.11.
     *
     * Decision order, each short-circuiting:
     *   1. MISSING (6.11 "fehlend"): no token was presented (null or, after
     *      trimming, empty).
     *   2. INVALID (6.11 "inhaltlich ungültig"): the token is present but not
     *      well-formed, so it cannot name a stored session; see
     *      {@see self::isWellFormed()}.
     *   3. NOT_ASSIGNABLE (6.11 "keiner Session zuordenbar"/"beendet"): the
     *      token is well-formed but no session row matches it — never issued or
     *      already ended by logout.
     *   4. EXPIRED (6.11 "abgelaufen", via 6.4): a session exists but the check
     *      time is at or after its expiry.
     *   5. AUTHENTICATED (7.4): a well-formed, assignable, non-expired session.
     *
     * Only case 5 grants access; cases 1–4 all mean "not authenticated".
     *
     * @param string|null      $token     The raw presented token (null if absent).
     * @param DateTimeImmutable $checkTime The instant authentication is evaluated.
     */
    public function classify(?string $token, DateTimeImmutable $checkTime): TokenClassification
    {
        if ($token === null || trim($token) === '') {
            return TokenClassification::MISSING;
        }

        if (!$this->isWellFormed($token)) {
            return TokenClassification::INVALID;
        }

        $session = $this->sessions->findByToken($token);
        if ($session === null) {
            return TokenClassification::NOT_ASSIGNABLE;
        }

        // Valid exactly while checkTime < expiresAt, matching
        // SessionValidator::isValid(); at or after the expiry instant the
        // session is expired (6.1, 6.4).
        if ($checkTime >= $session['expiresAt']) {
            return TokenClassification::EXPIRED;
        }

        return TokenClassification::AUTHENTICATED;
    }

    /**
     * Convenience predicate: whether the presented token authenticates the
     * request at the check time (6.11). Equivalent to
     * `classify($token, $checkTime)->isAuthenticated()`.
     */
    public function isAuthenticated(?string $token, DateTimeImmutable $checkTime): bool
    {
        return $this->classify($token, $checkTime)->isAuthenticated();
    }

    /**
     * End a session on logout (Requirement 6.6).
     *
     * Deletes the session row for the token so it can no longer be assigned to
     * any session: after this, {@see self::classify()} returns NOT_ASSIGNABLE
     * for the same token and the token grants no further access. Returns true
     * when a session was removed, false when none existed (already ended,
     * unknown, or malformed token) — logout is idempotent from the caller's
     * point of view either way.
     *
     * A null, empty/whitespace, or malformed token can never match a stored
     * session, so no delete is attempted and false is returned.
     *
     * @param string|null $token The raw token whose session should be ended.
     * @return bool True if a session was deleted, false if there was none.
     */
    public function logout(?string $token): bool
    {
        if ($token === null || trim($token) === '' || !$this->isWellFormed($token)) {
            return false;
        }

        return $this->sessions->delete($token);
    }

    /**
     * Whether a presented token is well-formed, i.e. has the shape a session
     * token can have (6.11 "inhaltlich ungültig" guard).
     *
     * Tokens are issued as the hex encoding of random_bytes(32) (design "token"
     * data model: CHAR(64) hex), so a well-formed token is exactly 64 lowercase
     * hexadecimal characters. Anything else is content-invalid and cannot name
     * a stored session, so it is rejected before any repository lookup.
     */
    private function isWellFormed(string $token): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $token) === 1;
    }
}
