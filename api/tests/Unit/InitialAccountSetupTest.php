<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\CredentialValidator;
use Citations\ErrorCode;
use Citations\InitialOperatorConfig;
use Citations\InitialOperatorSeeder;
use Citations\PasswordHasher;
use Citations\Tests\Fake\InMemoryOperatorRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Example/integration-style tests tying the idempotent initial-account seeding
 * to the subsequent login outcome, exercised end-to-end across the real
 * components ({@see InitialOperatorSeeder}, the operator repository,
 * {@see PasswordHasher} and {@see CredentialValidator}).
 *
 * Where {@see InitialOperatorSeederTest} verifies the seeder's idempotency in
 * isolation, these tests prove what the seeding actually buys: a seeded account
 * is usable for login (17.2), a pre-existing account is left untouched and the
 * config's username never shadows it (17.3), and with no seeded account a login
 * attempt is refused via the generic INVALID_CREDENTIALS path (17.5).
 *
 * The login step is wired realistically: the submitted username is looked up
 * via {@see InMemoryOperatorRepository::findByUsername()} and the resulting
 * record (or null) is handed to a single shared {@see CredentialValidator}
 * instance, matching how the login endpoint composes these parts.
 */
final class InitialAccountSetupTest extends TestCase
{
    private InMemoryStore $store;
    private InMemoryOperatorRepository $operators;
    private InitialOperatorSeeder $seeder;
    private CredentialValidator $validator;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->operators = new InMemoryOperatorRepository($this->store);
        $this->seeder = new InitialOperatorSeeder($this->operators);
        $this->validator = new CredentialValidator();
    }

    /**
     * Attempt a login exactly as the endpoint would: look the username up and
     * validate the submitted credentials against the found record (or null).
     */
    private function attemptLogin(?string $username, ?string $password)
    {
        $operator = $username === null ? null : $this->operators->findByUsername($username);

        return $this->validator->validate($username, $password, $operator);
    }

    public function testSeededAccountCanLogInWithTheConfiguredPassword(): void
    {
        // (17.2) Empty table + a COMPLETE config holding only the username and a
        // real hash of the plaintext.
        $plaintext = 'correct horse battery staple';
        $config = InitialOperatorConfig::fromArray([
            'username'     => 'admin',
            'passwordHash' => PasswordHasher::hash($plaintext),
        ]);

        $created = $this->seeder->seed($config);

        self::assertTrue($created, 'the seeder inserts an account into the empty table (17.2)');
        self::assertCount(1, $this->store->operators, 'exactly one account is created (17.2)');

        $seeded = $this->operators->findByUsername('admin');
        self::assertNotNull($seeded);

        // The seeded account is actually usable: the correct plaintext logs in.
        $success = $this->attemptLogin('admin', $plaintext);
        self::assertTrue($success->isValid, 'the correct password logs the seeded account in (17.2)');
        self::assertSame($seeded['id'], $success->operatorId, 'login resolves to the seeded operator id');
        self::assertNull($success->error);

        // Only the hash was stored: a wrong password for the same username is
        // refused with the generic INVALID_CREDENTIALS.
        $wrong = $this->attemptLogin('admin', 'not the password');
        self::assertFalse($wrong->isValid);
        self::assertSame(ErrorCode::INVALID_CREDENTIALS, $wrong->error);
    }

    public function testExistingAccountIsUntouchedAndConfigUsernameNeverShadowsIt(): void
    {
        // A pre-existing operator with a different username and password.
        $existingPlaintext = 'original-secret';
        $existingHash = PasswordHasher::hash($existingPlaintext);
        $existingId = $this->operators->insert('existing-operator', $existingHash);

        // Running the seeder with a DIFFERENT complete config.
        $config = InitialOperatorConfig::fromArray([
            'username'     => 'admin',
            'passwordHash' => PasswordHasher::hash('a-different-password'),
        ]);

        $created = $this->seeder->seed($config);

        // (17.3) Nothing created; the table is unchanged — same single account,
        // same id, same hash.
        self::assertFalse($created, 'an already-populated table is left as-is (17.3)');
        self::assertCount(1, $this->store->operators);

        $existing = $this->operators->findByUsername('existing-operator');
        self::assertNotNull($existing);
        self::assertSame($existingId, $existing['id'], 'the existing operator keeps its id (17.3)');
        self::assertSame($existingHash, $existing['passwordHash'], 'the existing hash is unchanged (17.3)');

        // The pre-existing account still logs in with its own password.
        $success = $this->attemptLogin('existing-operator', $existingPlaintext);
        self::assertTrue($success->isValid);
        self::assertSame($existingId, $success->operatorId);

        // The config's username was never seeded, so it cannot log in at all.
        self::assertNull($this->operators->findByUsername('admin'), "the config's username is absent (17.3)");
        $configLogin = $this->attemptLogin('admin', 'a-different-password');
        self::assertFalse($configLogin->isValid);
        self::assertSame(ErrorCode::INVALID_CREDENTIALS, $configLogin->error);
    }

    public function testLoginIsRefusedWhenNoAccountWasSeededFromIncompleteConfig(): void
    {
        // (17.5) Empty table + an INCOMPLETE config ⇒ nothing is seeded.
        $config = InitialOperatorConfig::fromArray([
            'username'     => 'admin',
            'passwordHash' => '',
        ]);

        $created = $this->seeder->seed($config);

        self::assertFalse($created, 'an incomplete config seeds nothing (17.5)');
        self::assertCount(0, $this->store->operators, 'no account exists (17.5)');

        // With no seeded account, a login attempt for the intended username with
        // non-blank credentials is refused with the generic INVALID_CREDENTIALS
        // (unknown username path, 17.5).
        $result = $this->attemptLogin('admin', 'any-password');
        self::assertFalse($result->isValid);
        self::assertSame(ErrorCode::INVALID_CREDENTIALS, $result->error);
        self::assertNull($result->operatorId);
    }

    public function testLoginIsRefusedForAnyUsernameWhenConfigIsAbsent(): void
    {
        // (17.5) Empty table + an absent-everywhere config ⇒ nothing is seeded.
        $created = $this->seeder->seed(InitialOperatorConfig::absent());

        self::assertFalse($created);
        self::assertCount(0, $this->store->operators);

        // Any username with non-blank credentials is refused identically.
        foreach (['admin', 'root', 'someone-else'] as $username) {
            $result = $this->attemptLogin($username, 'any-password');
            self::assertFalse($result->isValid, "login for '$username' is refused (17.5)");
            self::assertSame(ErrorCode::INVALID_CREDENTIALS, $result->error);
            self::assertNull($result->operatorId);
        }
    }
}
