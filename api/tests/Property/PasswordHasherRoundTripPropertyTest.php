<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\PasswordHasher;
use Eris\Generator;

/**
 * Feature: citations-management, Property 20: Passwort-Hash-Round-Trip.
 *
 * For every password, verifying the hash produced from that password against
 * the same password succeeds, verifying against a different password fails,
 * and the stored hash is never byte-identical to the plain text (the clear
 * text is not stored). Code under test: {@see PasswordHasher::hash()} and
 * {@see PasswordHasher::verify()}, thin wrappers over password_hash()
 * (PASSWORD_DEFAULT) and password_verify() (design.md "Passwort-Hashing").
 *
 * Passwords are kept short on purpose: password_hash() with PASSWORD_DEFAULT
 * (bcrypt) is CPU-intensive and ignores input beyond 72 bytes, so short ASCII
 * passwords keep the property fast while still exercising the round trip for
 * >= 100 iterations.
 *
 * Validates: Requirements 6.7
 */
final class PasswordHasherRoundTripPropertyTest extends PropertyTestCase
{
    /**
     * Printable ASCII range used to build arbitrary short passwords. Excluding
     * control bytes keeps counterexamples readable; bcrypt treats the input as
     * an opaque byte string regardless.
     */
    private function passwordGenerator(): Generator
    {
        $chars = Generator\choose(33, 126); // printable ASCII, no space/control

        return Generator\map(
            // A guaranteed leading character makes the password non-empty;
            // the remaining (possibly empty) sequence keeps it short and
            // arbitrary. bcrypt ignores input beyond 72 bytes anyway.
            static fn (array $parts): string => chr($parts[0]) . implode(
                '',
                array_map('chr', $parts[1])
            ),
            Generator\tuple($chars, Generator\seq($chars))
        );
    }

    /**
     * Round trip: verify(password, hash(password)) is always true.
     */
    public function testHashVerifiesAgainstSamePassword(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll($this->passwordGenerator())
            ->then(function (string $password): void {
                $hash = PasswordHasher::hash($password);

                self::assertTrue(
                    PasswordHasher::verify($password, $hash),
                    sprintf('Round trip failed for %s', bin2hex($password))
                );
            });
    }

    /**
     * A different password never verifies against another password's hash.
     */
    public function testHashRejectsDifferentPassword(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll($this->passwordGenerator(), $this->passwordGenerator())
            ->then(function (string $password, string $other): void {
                if ($password === $other) {
                    // Only meaningful when the two passwords actually differ.
                    return;
                }

                $hash = PasswordHasher::hash($password);

                self::assertFalse(
                    PasswordHasher::verify($other, $hash),
                    sprintf(
                        'Hash of %s wrongly verified against %s',
                        bin2hex($password),
                        bin2hex($other)
                    )
                );
            });
    }

    /**
     * The produced hash is never equal to the plain text (no clear-text
     * storage), and is a non-empty self-describing hash string.
     */
    public function testHashIsNeverPlaintext(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll($this->passwordGenerator())
            ->then(function (string $password): void {
                $hash = PasswordHasher::hash($password);

                self::assertNotSame(
                    $password,
                    $hash,
                    sprintf('Hash equals plain text for %s', bin2hex($password))
                );
                self::assertNotEmpty($hash);
            });
    }
}
