<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use Citations\Repository\LoginAttemptRepositoryInterface;
use DateTimeImmutable;

/**
 * Interface-identical in-memory {@see LoginAttemptRepositoryInterface} test
 * double (task 2.3) feeding the rate-limit window (Requirement 6.5).
 *
 * Attempts are appended in record order. The window queries count/return
 * failed attempts whose timestamp is at or after `$since` (inclusive lower
 * bound, matching the ">= since" contract); {@see self::failedAttemptsSince()}
 * returns them oldest-first so the limiter can locate the fifth failure of a
 * burst and lock from that point. Ordering is by timestamp, with ties broken
 * by record insertion order for a stable, repeatable result.
 */
final class InMemoryLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    public function __construct(private InMemoryStore $store)
    {
    }

    public function record(string $username, DateTimeImmutable $attemptedAt, bool $success): void
    {
        $this->store->loginAttempts[] = [
            'username' => $username,
            'attemptedAt' => $attemptedAt,
            'success' => $success,
        ];
    }

    public function countFailedSince(string $username, DateTimeImmutable $since): int
    {
        return count($this->failedAttemptsSince($username, $since));
    }

    public function failedAttemptsSince(string $username, DateTimeImmutable $since): array
    {
        /** @var list<array{index: int, at: DateTimeImmutable}> $matches */
        $matches = [];
        foreach ($this->store->loginAttempts as $index => $attempt) {
            if ($attempt['success']) {
                continue;
            }
            if ($attempt['username'] !== $username) {
                continue;
            }
            if ($attempt['attemptedAt'] < $since) {
                continue;
            }
            $matches[] = ['index' => $index, 'at' => $attempt['attemptedAt']];
        }

        // Oldest first; stable on equal timestamps via original record order.
        usort($matches, static function (array $a, array $b): int {
            return $a['at'] <=> $b['at'] ?: $a['index'] <=> $b['index'];
        });

        return array_map(static fn (array $m): DateTimeImmutable => $m['at'], $matches);
    }
}
