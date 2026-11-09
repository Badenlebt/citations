<?php

declare(strict_types=1);

namespace Citations;

/**
 * Immutable snapshot of the initial-operator credentials taken from the setup
 * configuration ("Einrichtungskonfiguration"): the username and the precomputed
 * password hash (Requirements 17.1, 17.4; design "Einrichtung des initialen
 * Betreiber-Accounts").
 *
 * Only the password HASH is ever carried here; a plaintext password is never
 * expected, stored, or exposed (17.4). The hash is treated verbatim — its
 * surrounding characters are significant — so only an absent or
 * whitespace-only value counts as "missing".
 *
 * An instance may legitimately be incomplete: when the configuration omits the
 * username and/or the hash, {@see self::isComplete()} is false and the seeder
 * creates no account (17.5). The username is reported trimmed; the hash is
 * reported exactly as configured.
 */
final class InitialOperatorConfig
{
    private function __construct(
        public readonly string $username,
        public readonly string $passwordHash,
    ) {
    }

    /**
     * Build the config from a raw associative array (the `initialOperator`
     * section of config.php, or the assembled environment values). Missing,
     * non-scalar, or blank entries become empty strings so the result is always
     * a usable, if possibly incomplete, value object.
     *
     * The username is trimmed of surrounding whitespace. The hash is read
     * verbatim (not trimmed) so a correctly configured hash is never altered;
     * only a hash that is empty or whitespace-only is normalized to "" and thus
     * treated as missing by {@see self::isComplete()} (17.4, 17.5).
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $username = $raw['username'] ?? null;
        $username = is_scalar($username) ? trim((string) $username) : '';

        $hash = $raw['passwordHash'] ?? null;
        $hash = is_scalar($hash) ? (string) $hash : '';
        // A whitespace-only hash carries no real value: treat it as missing
        // without mutating a genuine hash (which is used verbatim).
        if (trim($hash) === '') {
            $hash = '';
        }

        return new self($username, $hash);
    }

    /**
     * An absent-everywhere config: both fields empty. Used when the setup
     * configuration has no `initialOperator` section at all, so the seeder
     * creates no account (17.5).
     */
    public static function absent(): self
    {
        return new self('', '');
    }

    /**
     * Whether both the username and the password hash are present and non-blank,
     * i.e. the configuration describes a seedable account (17.2). When false,
     * the seeder must create nothing (17.5).
     */
    public function isComplete(): bool
    {
        return $this->username !== '' && $this->passwordHash !== '';
    }
}
