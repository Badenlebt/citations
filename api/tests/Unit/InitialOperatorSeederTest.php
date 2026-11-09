<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\InitialOperatorConfig;
use Citations\InitialOperatorSeeder;
use Citations\Tests\Fake\InMemoryOperatorRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Example/integration-style tests for {@see InitialOperatorSeeder} against the
 * in-memory operator repository, covering Requirements 17.2 (seed one account),
 * 17.3 (idempotent — leave existing account unchanged), 17.4 (only a hash is
 * ever required, never a plaintext password) and 17.5 (missing/empty
 * username or hash ⇒ no account).
 */
final class InitialOperatorSeederTest extends TestCase
{
    private InMemoryStore $store;
    private InMemoryOperatorRepository $operators;
    private InitialOperatorSeeder $seeder;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->operators = new InMemoryOperatorRepository($this->store);
        $this->seeder = new InitialOperatorSeeder($this->operators);
    }

    public function testSeedsExactlyOneAccountWhenTableEmptyAndConfigComplete(): void
    {
        $hash = password_hash('irrelevant-plaintext', PASSWORD_DEFAULT);
        $config = InitialOperatorConfig::fromArray([
            'username'     => 'admin',
            'passwordHash' => $hash,
        ]);

        $created = $this->seeder->seed($config);

        // (17.2) Exactly one account with the configured values exists afterwards.
        self::assertTrue($created);
        self::assertCount(1, $this->store->operators);

        $account = $this->operators->findByUsername('admin');
        self::assertNotNull($account);
        self::assertSame('admin', $account['username']);
        self::assertSame($hash, $account['passwordHash'], 'the configured hash is stored verbatim (17.4)');
    }

    public function testDoesNotCreateAnotherAccountWhenOneAlreadyExists(): void
    {
        // Pre-existing account, possibly renamed from the initial username.
        $existingHash = password_hash('existing', PASSWORD_DEFAULT);
        $existingId = $this->operators->insert('renamed-operator', $existingHash);

        $config = InitialOperatorConfig::fromArray([
            'username'     => 'admin',
            'passwordHash' => password_hash('other', PASSWORD_DEFAULT),
        ]);

        $created = $this->seeder->seed($config);

        // (17.3) Nothing created; the existing account is untouched.
        self::assertFalse($created);
        self::assertCount(1, $this->store->operators);
        self::assertNull($this->operators->findByUsername('admin'));

        $existing = $this->operators->findByUsername('renamed-operator');
        self::assertNotNull($existing);
        self::assertSame($existingId, $existing['id']);
        self::assertSame($existingHash, $existing['passwordHash']);
    }

    public function testSeedIsIdempotentAcrossRepeatedRuns(): void
    {
        $config = InitialOperatorConfig::fromArray([
            'username'     => 'admin',
            'passwordHash' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        self::assertTrue($this->seeder->seed($config), 'first run seeds the account (17.2)');
        self::assertFalse($this->seeder->seed($config), 'second run creates nothing (17.3)');
        self::assertFalse($this->seeder->seed($config), 'further runs still create nothing (17.3)');

        self::assertCount(1, $this->store->operators);
    }

    public function testCreatesNoAccountWhenUsernameMissing(): void
    {
        $config = InitialOperatorConfig::fromArray([
            'passwordHash' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        $created = $this->seeder->seed($config);

        // (17.5) Empty table but no username ⇒ nothing created.
        self::assertFalse($created);
        self::assertCount(0, $this->store->operators);
    }

    public function testCreatesNoAccountWhenHashMissing(): void
    {
        $config = InitialOperatorConfig::fromArray([
            'username'     => 'admin',
            'passwordHash' => '',
        ]);

        $created = $this->seeder->seed($config);

        // (17.5) Empty table but empty hash ⇒ nothing created.
        self::assertFalse($created);
        self::assertCount(0, $this->store->operators);
    }

    public function testCreatesNoAccountWhenUsernameIsWhitespaceOnly(): void
    {
        $config = InitialOperatorConfig::fromArray([
            'username'     => '   ',
            'passwordHash' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        self::assertFalse($this->seeder->seed($config));
        self::assertCount(0, $this->store->operators);
    }

    public function testCreatesNoAccountWhenHashIsWhitespaceOnly(): void
    {
        $config = InitialOperatorConfig::fromArray([
            'username'     => 'admin',
            'passwordHash' => "  \t  ",
        ]);

        self::assertFalse($this->seeder->seed($config));
        self::assertCount(0, $this->store->operators);
    }

    public function testCreatesNoAccountForAbsentConfig(): void
    {
        self::assertFalse($this->seeder->seed(InitialOperatorConfig::absent()));
        self::assertCount(0, $this->store->operators);
    }

    public function testHashWithSurroundingWhitespaceIsStoredVerbatim(): void
    {
        // A genuine (non-blank) hash is used exactly as configured; the seeder
        // never trims a real hash (17.4).
        $hash = '  $2y$10$abcdefghijklmnopqrstuv  ';
        $config = InitialOperatorConfig::fromArray([
            'username'     => 'admin',
            'passwordHash' => $hash,
        ]);

        self::assertTrue($this->seeder->seed($config));
        $account = $this->operators->findByUsername('admin');
        self::assertNotNull($account);
        self::assertSame($hash, $account['passwordHash']);
    }
}
