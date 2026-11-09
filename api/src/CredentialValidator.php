<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure, side-effect-free credential validation for the Auth-API login flow
 * (Requirements 6.2, 6.3, 6.10; design "Auth-API" and authentication flow 6.3
 * -> 6.5 -> 6.2).
 *
 * This component owns two of the three login checks: the completeness check
 * (6.3) and the generic validity check (6.2), performed in a timing-balanced
 * way (6.10). The rate-limit check (6.5) sits between them in the overall order
 * (6.9) and is handled elsewhere (task 6.6); this class is deliberately
 * unaware of it so each check stays independently testable.
 *
 * Layer cut: the actual operator lookup is I/O and happens at the endpoint
 * against OperatorRepositoryInterface. The caller passes the looked-up operator
 * record (or null when the username is unknown) into {@see self::validate()},
 * keeping this logic free of I/O and globals and thus property-testable.
 *
 * The password plain text is only ever handed to {@see PasswordHasher::verify()}
 * for the constant-time comparison; it is never stored, logged or returned
 * (6.7).
 */
final class CredentialValidator
{
    /**
     * A hash of a random, unknowable password, computed once per instance with
     * the same cost as real operator hashes. When the username is unknown we
     * still run password_verify() against this dummy so that the "unknown
     * username" and "known username, wrong password" paths perform the same
     * work and cannot be told apart by timing (6.10).
     */
    private readonly string $dummyHash;

    public function __construct()
    {
        // random_bytes gives a value no client can predict; bin2hex keeps it a
        // valid UTF-8 string for hashing. The resulting hash never verifies
        // against any attacker-supplied password, so the unknown-username path
        // always fails validity (6.2) while staying timing-balanced (6.10).
        $this->dummyHash = PasswordHasher::hash(bin2hex(random_bytes(32)));
    }

    /**
     * Validate submitted login credentials against the looked-up operator.
     *
     * Order of checks mirrors the per-component slice of the login order (6.9):
     *   1. Completeness (6.3): if the username OR password is missing or, after
     *      trimming surrounding whitespace, empty, the request is rejected with
     *      MISSING_CREDENTIALS and NO password verification is performed. This
     *      is checked on the RAW inputs before any hashing work.
     *   2. Validity (6.2, 6.10): otherwise a password comparison is always run.
     *      When $operator is null (unknown username) the comparison runs against
     *      an internal dummy hash so the unknown-username and wrong-password
     *      paths are indistinguishable by timing (6.10). A failed comparison
     *      yields the generic INVALID_CREDENTIALS, which is byte-for-byte the
     *      same regardless of whether the username existed (6.2).
     *
     * The returned result never reveals which part was wrong on failure: both
     * the unknown-username and the wrong-password cases return the identical
     * INVALID_CREDENTIALS error.
     *
     * @param string|null $username The raw submitted username (null if absent).
     * @param string|null $password The raw submitted password (null if absent).
     * @param array{id: int, username: string, passwordHash: string}|null $operator
     *        The operator record looked up for the submitted username, or null
     *        when no operator with that username exists.
     * @return CredentialValidationResult Success carrying the operator id, or a
     *         failure carrying MISSING_CREDENTIALS or INVALID_CREDENTIALS.
     */
    public function validate(?string $username, ?string $password, ?array $operator): CredentialValidationResult
    {
        // Step 1 (6.3): completeness on the raw inputs. A null, empty, or
        // whitespace-only username or password short-circuits here WITHOUT any
        // password check.
        if ($this->isBlank($username) || $this->isBlank($password)) {
            return CredentialValidationResult::failure(ErrorCode::MISSING_CREDENTIALS);
        }

        // From here both inputs are non-blank strings.
        /** @var string $password */

        // Step 2 (6.2, 6.10): always perform exactly one hash comparison. Use
        // the real operator hash when the username is known, otherwise the
        // dummy hash. Both branches do the same amount of work.
        $hash = $operator === null ? $this->dummyHash : $operator['passwordHash'];
        $passwordMatches = PasswordHasher::verify($password, $hash);

        // A match only counts when there actually was an operator. The dummy
        // hash is built from an unknowable value, so $passwordMatches is false
        // on the unknown-username path regardless, but this guard makes the
        // intent explicit and robust.
        if ($operator !== null && $passwordMatches) {
            return CredentialValidationResult::success($operator['id']);
        }

        return CredentialValidationResult::failure(ErrorCode::INVALID_CREDENTIALS);
    }

    /**
     * Whether a submitted credential field is missing or, after trimming
     * surrounding whitespace, empty (6.3). trim() covers spaces, tabs and
     * newlines; a value consisting solely of such characters is treated as
     * absent.
     */
    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
