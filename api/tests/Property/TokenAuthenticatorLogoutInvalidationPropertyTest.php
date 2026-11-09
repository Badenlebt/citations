<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\TokenAuthenticator;
use Citations\TokenClassification;
use Citations\Tests\Fake\InMemorySessionRepository;
use Citations\Tests\Fake\InMemoryStore;
use DateTimeImmutable;
use Eris\Generator;

/**
 * Feature: citations-management, Property 19: Logout macht die Session dauerhaft
 * ungültig.
 *
 * For every previously valid session — a well-formed 64-hex-char token backed by
 * a present, non-expired session — a {@see TokenAuthenticator::logout()} ends the
 * session permanently: from the logout instant onward the same token is no longer
 * assignable to any session, so {@see TokenAuthenticator::classify()} returns
 * {@see TokenClassification::NOT_ASSIGNABLE} (never authenticated) at the logout
 * instant AND at every later check time, and a repeated logout of the same token
 * reports that nothing was removed (idempotent). Code under test:
 * {@see TokenAuthenticator::logout()}, {@see TokenAuthenticator::classify()},
 * {@see TokenAuthenticator::isAuthenticated()} (design.md "Property 19").
 *
 * Validates: Requirements 6.6
 */
final class TokenAuthenticatorLogoutInvalidationPropertyTest extends PropertyTestCase
{
    /** One hour in seconds, used to translate hour offsets into instants. */
    private const SECONDS_PER_HOUR = 3600;

    /**
     * Arbitrary well-formed session tokens: exactly 64 lowercase hex chars, the
     * shape {@see TokenAuthenticator} accepts as well-formed (hex of
     * random_bytes(32)). Built from an integer seed so counterexamples shrink to
     * small, reproducible values rather than opaque blobs.
     */
    private function tokenGenerator(): Generator
    {
        return Generator\map(
            static fn (int $seed): string => substr(hash('sha256', (string) $seed), 0, 64),
            Generator\choose(0, PHP_INT_MAX)
        );
    }

    /**
     * Arbitrary creation instants spread across a wide epoch range, so the
     * property never depends on a particular absolute time.
     */
    private function createdAtGenerator(): Generator
    {
        return Generator\map(
            static fn (int $epoch): DateTimeImmutable => new DateTimeImmutable('@' . $epoch),
            Generator\choose(946_684_800, 3_000_000_000)
        );
    }

    /**
     * After logout the token is permanently unassignable: classify() returns
     * NOT_ASSIGNABLE (not authenticated) both at the logout instant and at every
     * later check time, and a second logout removes nothing (idempotent).
     */
    public function testLogoutPermanentlyInvalidatesTheSession(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            $this->tokenGenerator(),
            $this->createdAtGenerator(),
            // Validity duration in hours within the configurable band [1, 168].
            Generator\choose(1, 168),
            // Fraction (per mille) of the validity window elapsed at logout time.
            // Kept in [0, 999] so the logout instant lies STRICTLY before expiry,
            // i.e. the session is genuinely authenticated beforehand.
            Generator\choose(0, 999),
            // How far after logout the later check happens, in seconds: from the
            // same instant up to years later.
            Generator\choose(0, 400_000_000)
        )->then(function (
            string $token,
            DateTimeImmutable $createdAt,
            int $durationHours,
            int $elapsedPerMille,
            int $laterOffsetSeconds
        ): void {
            $store = new InMemoryStore();
            $sessions = new InMemorySessionRepository($store);
            $auth = new TokenAuthenticator($sessions);

            $durationSeconds = $durationHours * self::SECONDS_PER_HOUR;
            $expiresAt = $createdAt->modify('+' . $durationSeconds . ' seconds');
            $sessions->insert($token, 1, $createdAt, $expiresAt);

            // Logout happens while the session is still valid, so it was a
            // previously valid/authenticated session (precondition of Property 19).
            // intdiv keeps the offset strictly below the duration for perMille < 1000.
            $elapsedSeconds = intdiv($durationSeconds * $elapsedPerMille, 1000);
            $logoutTime = $createdAt->modify('+' . $elapsedSeconds . ' seconds');
            self::assertTrue(
                $auth->isAuthenticated($token, $logoutTime),
                sprintf(
                    'Precondition failed: session should authenticate before logout (token %s, duration %dh, elapsed %ds).',
                    $token,
                    $durationHours,
                    $elapsedSeconds
                )
            );

            // Logout ends the session and reports that a row was removed.
            self::assertTrue(
                $auth->logout($token),
                sprintf('logout() must remove the previously valid session for token %s.', $token)
            );

            // At the logout instant the token is already unassignable, never
            // authenticated.
            self::assertSame(
                TokenClassification::NOT_ASSIGNABLE,
                $auth->classify($token, $logoutTime),
                sprintf('Token must be NOT_ASSIGNABLE at the logout instant (token %s).', $token)
            );
            self::assertFalse(
                $auth->isAuthenticated($token, $logoutTime),
                sprintf('Token must not authenticate at the logout instant (token %s).', $token)
            );

            // At an arbitrary later check time the token stays unassignable: the
            // invalidation is permanent, not tied to one instant.
            $laterTime = $logoutTime->modify('+' . $laterOffsetSeconds . ' seconds');
            self::assertSame(
                TokenClassification::NOT_ASSIGNABLE,
                $auth->classify($token, $laterTime),
                sprintf('Token must stay NOT_ASSIGNABLE %d s after logout (token %s).', $laterOffsetSeconds, $token)
            );
            self::assertFalse(
                $auth->isAuthenticated($token, $laterTime),
                sprintf('Token must never authenticate after logout (token %s, +%d s).', $token, $laterOffsetSeconds)
            );

            // Logging out again removes nothing — logout is idempotent.
            self::assertFalse(
                $auth->logout($token),
                sprintf('A second logout of the same token must report no removal (token %s).', $token)
            );
        });
    }
}
