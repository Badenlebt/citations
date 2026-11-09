<?php

declare(strict_types=1);

namespace Citations\Tests\Fake;

use DateTimeImmutable;

/**
 * Shared, mutable in-memory backing store for the fake repositories
 * (task 2.3). It lets the Quote/Source/Language/Operator/Session/LoginAttempt
 * fakes operate on one consistent world so that FK/reference behaviour can be
 * modelled across repositories (e.g. a quote always resolves to an existing
 * source and language for its joined read shape).
 *
 * The store is a plain data holder with no business rules: validation and the
 * Unbekannt-Quelle invariants live in the mutation layer under test, not here.
 * The single thing it pre-initializes is the always-present Unbekannt-Quelle
 * (id 1, name 'unbekannt', is_unknown = true) seeded exactly like db/seed.sql
 * (Requirement 13.1).
 *
 * Identifiers are assigned from monotonically increasing counters, mirroring
 * AUTO_INCREMENT so ids are never reused after deletion.
 *
 * @phpstan-type SourceRow array{id: int, name: string, nameNormalized: string, birthDate: string|null, deathDate: string|null, description: string|null, linkUrl: string|null, isUnknown: bool}
 * @phpstan-type LanguageRow array{id: int, name: string, nameNormalized: string}
 * @phpstan-type QuoteRow array{id: int, text: string, sourceId: int, languageId: int}
 * @phpstan-type OperatorRow array{id: int, username: string, passwordHash: string}
 * @phpstan-type SessionRow array{token: string, operatorId: int, createdAt: DateTimeImmutable, expiresAt: DateTimeImmutable}
 * @phpstan-type LoginAttemptRow array{username: string, attemptedAt: DateTimeImmutable, success: bool}
 */
final class InMemoryStore
{
    /** Id of the pre-seeded Unbekannt-Quelle (Requirement 13.1). */
    public const UNKNOWN_SOURCE_ID = 1;

    /** @var array<int, array{id: int, name: string, nameNormalized: string, birthDate: string|null, deathDate: string|null, description: string|null, linkUrl: string|null, isUnknown: bool}> keyed by source id */
    public array $sources = [];

    /** @var array<int, array{id: int, name: string, nameNormalized: string}> keyed by language id */
    public array $languages = [];

    /** @var array<int, array{id: int, text: string, sourceId: int, languageId: int}> keyed by quote id */
    public array $quotes = [];

    /** @var array<int, array{id: int, username: string, passwordHash: string}> keyed by operator id */
    public array $operators = [];

    /** @var array<string, array{token: string, operatorId: int, createdAt: DateTimeImmutable, expiresAt: DateTimeImmutable}> keyed by session token */
    public array $sessions = [];

    /** @var list<array{username: string, attemptedAt: DateTimeImmutable, success: bool}> append-only attempt log */
    public array $loginAttempts = [];

    private int $nextSourceId = 1;
    private int $nextLanguageId = 1;
    private int $nextQuoteId = 1;
    private int $nextOperatorId = 1;

    public function __construct()
    {
        // Seed exactly one Unbekannt-Quelle, matching db/seed.sql (13.1).
        $id = $this->allocateSourceId();
        $this->sources[$id] = [
            'id' => $id,
            'name' => 'unbekannt',
            'nameNormalized' => 'unbekannt',
            'birthDate' => null,
            'deathDate' => null,
            'description' => null,
            'linkUrl' => null,
            'isUnknown' => true,
        ];
    }

    public function allocateSourceId(): int
    {
        return $this->nextSourceId++;
    }

    public function allocateLanguageId(): int
    {
        return $this->nextLanguageId++;
    }

    public function allocateQuoteId(): int
    {
        return $this->nextQuoteId++;
    }

    public function allocateOperatorId(): int
    {
        return $this->nextOperatorId++;
    }
}
