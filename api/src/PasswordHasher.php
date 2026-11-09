<?php

declare(strict_types=1);

namespace Citations;

/**
 * Pure wrapper around PHP's standard password hashing primitives
 * (Requirement 6.7, design "Passwort-Hashing (Anf. 6.7)").
 *
 * The operator password is only ever handled as a cryptographic hash: a plain
 * text password is turned into a hash via {@see self::hash()} and later checked
 * against a stored hash via {@see self::verify()}. The plain text is never
 * stored, logged, mutated into a global, or returned from either function, so
 * nothing here can leak the clear text (6.7).
 *
 * Algorithm selection is delegated to PHP via PASSWORD_DEFAULT (currently
 * bcrypt). Using the managed default keeps the scheme future-proof: when PHP
 * raises the default, newly produced hashes follow automatically while
 * password_verify() still accepts previously stored hashes. No custom
 * cryptography and no third-party package are involved.
 */
final class PasswordHasher
{
    /**
     * Produce a cryptographic hash of a plain text password.
     *
     * The returned string is a self-describing hash (algorithm, cost and salt
     * are encoded in it), suitable for storage in operator.password_hash. The
     * plain text input is not retained anywhere by this call.
     *
     * Because PASSWORD_DEFAULT embeds a random salt, hashing the same password
     * twice yields two different hash strings; both still verify against the
     * original password via {@see self::verify()}.
     *
     * @param string $plainPassword The clear-text password to hash.
     * @return string The password hash to persist (never the plain text).
     */
    public static function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }

    /**
     * Check a plain text password against a previously produced hash.
     *
     * Returns true only when $plainPassword is the clear text that produced
     * $hash, and false otherwise (including when $hash is empty or not a valid
     * hash string). The comparison is performed by password_verify(), which is
     * constant-time with respect to the hash contents. Neither argument is
     * stored or returned.
     *
     * @param string $plainPassword The clear-text password to check.
     * @param string $hash          The stored password hash to check against.
     * @return bool True if the password matches the hash, false otherwise.
     */
    public static function verify(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }
}
