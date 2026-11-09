<?php

declare(strict_types=1);

namespace Citations\Tests\Integration;

use Citations\ConfigException;
use Citations\DatabaseConfig;
use Citations\InitialOperatorConfig;
use Citations\InitialOperatorSeeder;
use Citations\PdoConnectionFactory;
use Citations\Repository\PdoLanguageRepository;
use Citations\Repository\PdoLoginAttemptRepository;
use Citations\Repository\PdoOperatorRepository;
use Citations\Repository\PdoQuoteRepository;
use Citations\Repository\PdoSessionRepository;
use Citations\Repository\PdoSourceRepository;
use DateTimeImmutable;
use PDO;

/**
 * End-to-end PDO/MariaDB round-trip integration tests (task 11.4;
 * Requirements 15.3, 15.7, 16.2) against a real database.
 *
 * Covers:
 *   - 16.2: PHP-API <-> MariaDB round-trips through every PDO repository
 *     (quote/source/language insert-find-update-delete; operator seeding;
 *     session insert/find/delete; login_attempt record/query), asserting the
 *     normalized columns (text_normalized / name_normalized) are populated.
 *   - 15.3: a config WITHOUT a port field connects on the MariaDB default port
 *     against the live server (skipped when the test DB uses a non-default port).
 *   - 15.7 + no-stored-data-change: a forced DB error (prepared statement
 *     against a dropped table) surfaces as a driver error carrying no new state
 *     change, and a known row persisted beforehand is still present and equal
 *     afterwards.
 */
final class PdoDatabaseRoundTripIntegrationTest extends IntegrationTestCase
{

    /**
     * 16.2: quote / source / language round-trip through the PDO repositories,
     * asserting that the normalized columns are populated on write.
     */
    public function testQuoteSourceLanguageRoundTripPopulatesNormalizedColumns(): void
    {
        $pdo = $this->requireDatabase();

        $languages = new PdoLanguageRepository($pdo);
        $sources = new PdoSourceRepository($pdo);
        $quotes = new PdoQuoteRepository($pdo);

        // Language insert + case-insensitive normalized lookup.
        $languageId = $languages->insert('Français', 'francais');
        $this->assertSame(['id' => $languageId, 'name' => 'Français'], $languages->findById($languageId));
        $this->assertTrue($languages->existsByNormalizedName('francais'));
        $this->assertSame('francais', $this->scalar($pdo, 'SELECT name_normalized FROM language WHERE id = ' . $languageId));

        // Source insert + find + update + delete round-trip.
        $sourceId = $sources->insert('Épictète', ['linkUrl' => 'https://example.test/e', 'description' => 'Stoiker']);
        $source = $sources->findById($sourceId);
        $this->assertNotNull($source);
        $this->assertSame('Épictète', $source['name']);
        $this->assertSame('https://example.test/e', $source['linkUrl']);
        $this->assertFalse($source['isUnknown']);

        $this->assertTrue($sources->update($sourceId, 'Epiktet', ['description' => 'aktualisiert']));
        $this->assertSame('Epiktet', $sources->findById($sourceId)['name']);

        // Quote insert populates text_normalized; find joins language + source.
        $quoteId = $quotes->insert('Ça dépend de Toi', $sourceId, $languageId);
        $quote = $quotes->findById($quoteId);
        $this->assertNotNull($quote);
        $this->assertSame('Ça dépend de Toi', $quote['text']);
        $this->assertSame($languageId, $quote['languageId']);
        $this->assertSame('Français', $quote['languageName']);
        $this->assertSame($sourceId, $quote['source']['id']);
        $this->assertSame('ca depend de toi', $this->scalar($pdo, 'SELECT text_normalized FROM quote WHERE id = ' . $quoteId));

        // Quote update + delete.
        $this->assertTrue($quotes->update($quoteId, 'Neuer Text', $sourceId, $languageId));
        $this->assertSame('Neuer Text', $quotes->findById($quoteId)['text']);
        $this->assertSame('neuer text', $this->scalar($pdo, 'SELECT text_normalized FROM quote WHERE id = ' . $quoteId));
        $this->assertTrue($quotes->delete($quoteId));
        $this->assertNull($quotes->findById($quoteId));

        // Deleting the (now quote-free) source succeeds.
        $this->assertTrue($sources->delete($sourceId));
        $this->assertNull($sources->findById($sourceId));

        // The seeded Unbekannt-Quelle is always present (13.1).
        $this->assertTrue($sources->findUnknown()['isUnknown']);
    }

    /**
     * 16.2: operator seeding + session + login_attempt round-trips.
     */
    public function testOperatorSessionAndLoginAttemptRoundTrip(): void
    {
        $pdo = $this->requireDatabase();

        $operators = new PdoOperatorRepository($pdo);
        $sessions = new PdoSessionRepository($pdo);
        $attempts = new PdoLoginAttemptRepository($pdo);

        // Idempotent operator seeding (only the hash is ever stored).
        $hash = password_hash('irrelevant-for-storage', PASSWORD_DEFAULT);
        $seeder = new InitialOperatorSeeder($operators);
        $this->assertFalse($operators->hasAny());
        $this->assertTrue($seeder->seed(InitialOperatorConfig::fromArray([
            'username' => 'admin',
            'passwordHash' => $hash,
        ])));
        $this->assertTrue($operators->hasAny());
        // Running the seed again is a no-op (17.3).
        $this->assertFalse($seeder->seed(InitialOperatorConfig::fromArray([
            'username' => 'other',
            'passwordHash' => $hash,
        ])));

        $operator = $operators->findByUsername('admin');
        $this->assertNotNull($operator);
        $this->assertSame($hash, $operator['passwordHash']);
        $operatorId = $operator['id'];

        // Session insert / find / delete, timestamps round-tripping exactly.
        $created = new DateTimeImmutable('2024-01-01 10:00:00');
        $expires = new DateTimeImmutable('2024-01-02 10:00:00');
        $token = str_repeat('a', 64);
        $sessions->insert($token, $operatorId, $created, $expires);
        $session = $sessions->findByToken($token);
        $this->assertNotNull($session);
        $this->assertSame($operatorId, $session['operatorId']);
        $this->assertSame($created->format('Y-m-d H:i:s'), $session['createdAt']->format('Y-m-d H:i:s'));
        $this->assertSame($expires->format('Y-m-d H:i:s'), $session['expiresAt']->format('Y-m-d H:i:s'));
        $this->assertTrue($sessions->delete($token));
        $this->assertNull($sessions->findByToken($token));

        // Login-attempt record + window query.
        $base = new DateTimeImmutable('2024-02-01 12:00:00');
        $attempts->record('admin', $base, false);
        $attempts->record('admin', $base->modify('+1 minute'), false);
        $attempts->record('admin', $base->modify('+2 minute'), true);
        $since = $base->modify('-1 minute');
        $this->assertSame(2, $attempts->countFailedSince('admin', $since));
        $failures = $attempts->failedAttemptsSince('admin', $since);
        $this->assertCount(2, $failures);
        $this->assertSame(
            $base->format('Y-m-d H:i:s'),
            $failures[0]->format('Y-m-d H:i:s'),
            'Failures are returned oldest-first.',
        );
    }

    /**
     * 15.3 (live): a config WITHOUT a port field connects to the live MariaDB on
     * the default port (3306). Skipped when the test DB runs on a non-default
     * port, since the default-port connect could not succeed there — the pure
     * default-port resolution is still asserted in
     * {@see CredentialConfigIntegrationTest::testMissingPortResolvesToMariaDbDefault()}.
     */
    public function testConfigWithoutPortConnectsOnDefaultPort(): void
    {
        // Ensure a DB is configured/reachable at all (otherwise skip).
        $this->requireDatabase();

        $configured = self::integrationConfig();
        $this->assertNotNull($configured);

        if (self::hasExplicitPort() && $configured->port !== DatabaseConfig::DEFAULT_PORT) {
            $this->markTestSkipped(
                'Test database uses a non-default port; the default-port connect '
                . 'assertion (15.3) only applies when the server listens on 3306.',
            );
        }

        // Rebuild a config WITHOUT any port so DatabaseConfig falls back to the
        // MariaDB default (15.3), then connect for real.
        $noPort = DatabaseConfig::fromArray([
            'host'     => $configured->host,
            'name'     => $configured->name,
            'user'     => $configured->user,
            'password' => $configured->password,
            // no 'port' key
        ]);
        $this->assertSame(DatabaseConfig::DEFAULT_PORT, $noPort->port);

        $pdo = (new PdoConnectionFactory())->connect($noPort, ['connectTimeoutSeconds' => 3]);
        $this->assertSame('1', (string) $pdo->query('SELECT 1')->fetchColumn());
    }

    /**
     * 15.7 + no stored-data change: force an internal DB error (a prepared
     * statement against a dropped table) and assert that a known row persisted
     * beforehand is unchanged afterwards. The failure is a driver error here
     * (the generic-500 mapping of such errors is the front-controller's job,
     * task 12.1); what matters at the I/O layer is that the failed statement
     * leaves previously stored data intact.
     */
    public function testForcedDbErrorLeavesStoredDataUnchanged(): void
    {
        $pdo = $this->requireDatabase();

        $languages = new PdoLanguageRepository($pdo);
        $sources = new PdoSourceRepository($pdo);
        $quotes = new PdoQuoteRepository($pdo);

        // Persist a known, verifiable row.
        $languageId = $languages->insert('Deutsch', 'deutsch');
        $sourceId = $sources->findUnknown()['id'];
        $quoteId = $quotes->insert('Bewahrter Text', $sourceId, $languageId);
        $before = $quotes->findById($quoteId);
        $this->assertNotNull($before);

        // Force a DB error: run a statement against a table that does not exist.
        $errorRaised = false;
        try {
            $pdo->prepare('UPDATE quote_does_not_exist SET text = :t WHERE id = :id')
                ->execute([':t' => 'tampered', ':id' => $quoteId]);
        } catch (\PDOException) {
            $errorRaised = true;
        }
        $this->assertTrue($errorRaised, 'The statement against a missing table must raise a DB error.');

        // The known row is still present and equal (no stored-data change).
        $after = $quotes->findById($quoteId);
        $this->assertSame($before, $after, 'A failed statement must not change previously stored data.');
        $this->assertSame('Bewahrter Text', $after['text']);
    }

    /**
     * Fetch a single scalar column value as a string. Used to inspect the
     * normalized columns that the public repository records intentionally hide.
     */
    private function scalar(PDO $pdo, string $sql): string
    {
        return (string) $pdo->query($sql)->fetchColumn();
    }
}
