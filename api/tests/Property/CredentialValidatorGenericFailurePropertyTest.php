<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\CredentialValidationResult;
use Citations\CredentialValidator;
use Citations\ErrorCode;
use Eris\Generator;

/**
 * Feature: citations-management, Property 15: Generische, ununterscheidbare
 * Fehlerantwort bei ungültigen Zugangsdaten.
 *
 * Requirement 6.2 demands that when invalid credentials are submitted, the
 * Auth-API denies access with an error that does NOT reveal whether the
 * username or the password was wrong. {@see CredentialValidator::validate()}
 * realizes this by returning the single generic ErrorCode::INVALID_CREDENTIALS
 * on every validity failure, with no operator id — so the two distinct failure
 * reasons collapse into one indistinguishable outcome.
 *
 * This property pins down "indistinguishable" concretely: for arbitrary
 * non-blank credentials that fail authentication, the result of the
 *   (A) "unknown username" case  — operator === null
 * and the
 *   (B) "known username, wrong password" case — a real operator whose stored
 *       hash does NOT verify against the submitted password
 * must be byte-for-byte equal on every observable field: isValid === false,
 * error === INVALID_CREDENTIALS, operatorId === null. Nothing in the returned
 * result lets a caller tell the two cases apart.
 *
 * Performance note: {@see CredentialValidator::validate()} runs one bcrypt
 * verify per call and its constructor builds a dummy hash, so bcrypt dominates
 * the runtime. We keep the property fast (and well within CI limits) three
 * ways, none of which weaken the property:
 *   1. Reuse a single validator across all iterations (validation is pure, so
 *      a shared instance behaves identically to a fresh one).
 *   2. For the core property, use a single precomputed non-matching stored
 *      hash instead of hashing per iteration — the property only needs the
 *      stored hash to NOT verify against the submitted password, which a fixed
 *      hash of an unrelated constant guarantees.
 *   3. Produce the operator hashes with an explicit low bcrypt cost via
 *      {@see self::testHash()}. password_verify() honours the cost embedded in
 *      the hash it is given, so a genuine, correctly-verifying bcrypt hash at a
 *      low cost is cryptographically a real match/non-match — only cheaper. The
 *      production default cost is irrelevant to WHAT the property asserts.
 *
 * Validates: Requirements 6.2
 */
final class CredentialValidatorGenericFailurePropertyTest extends PropertyTestCase
{
    /**
     * Alphabet for arbitrary NON-blank credential material. Every value built
     * from it contains at least one character that survives trim(), so the
     * completeness check (6.3) never short-circuits and the validity path (6.2)
     * is always exercised.
     *
     * @var list<string>
     */
    private const CONTENT = [
        'a', 'B', 'c', 'D', 'e', 'F', '1', '9', '_', '-', '.', '@', 'x', 'Z',
    ];

    /**
     * Plain text behind {@see self::$nonMatchingHash}. It contains a space and
     * the word "sentinel", neither of which the CONTENT alphabet can produce,
     * so no generated password can ever equal it — the stored hash is therefore
     * guaranteed never to verify against a submitted password in the core test.
     */
    private const SENTINEL_PASSWORD = 'fixed sentinel password';

    /**
     * Produce a genuine bcrypt hash of $plain at a low, test-only cost.
     *
     * This is the same algorithm (bcrypt) that {@see PasswordHasher::hash()}
     * selects via PASSWORD_DEFAULT; only the work factor differs. Because
     * password_verify() reads the cost from the hash string itself, a hash
     * produced here verifies exactly like a production hash would — a real
     * match stays a real match, a non-match stays a non-match — so the property
     * is cryptographically identical while each verify costs milliseconds
     * instead of ~200ms. We never assert anything about the cost; it is purely
     * a runtime concern for a property that calls bcrypt hundreds of times.
     */
    private static function testHash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    /**
     * Build an arbitrary non-blank string (at least one content character) from
     * the content alphabet, driven by shrinkable integer seeds.
     *
     * @param list<int> $picks one content-index selector per extra character
     */
    private function buildContent(int $head, array $picks): string
    {
        $out = self::CONTENT[$head % count(self::CONTENT)];
        foreach ($picks as $p) {
            $out .= self::CONTENT[$p % count(self::CONTENT)];
        }

        return $out;
    }

    /**
     * Derive a password guaranteed NOT to verify against the hash of $password.
     *
     * bcrypt only hashes the first 72 bytes of its input, so appending a suffix
     * does NOT produce a different password once $password already reaches that
     * length — both strings share the first 72 bytes and therefore verify
     * against the same hash. To stay correct for arbitrary generated lengths we
     * mutate the FIRST byte instead: prepend '#', a non-whitespace character
     * the CONTENT alphabet never produces. The result differs from $password
     * within bcrypt's hashed prefix (byte 0) so it can never match, and because
     * '#' is not whitespace the validator's trim() keeps it non-blank — so the
     * completeness check (6.3) is not triggered and the validity path (6.2) is
     * the thing under test.
     */
    private function differentPassword(string $password): string
    {
        return '#' . $password;
    }

    /**
     * Assert the result is a generic validity failure: rejected, carrying the
     * exact INVALID_CREDENTIALS code and no operator id.
     */
    private function assertInvalidCredentials(CredentialValidationResult $result, string $context): void
    {
        self::assertFalse(
            $result->isValid,
            sprintf('Expected rejection but got success (%s).', $context)
        );
        self::assertSame(
            ErrorCode::INVALID_CREDENTIALS,
            $result->error,
            sprintf('Expected INVALID_CREDENTIALS (%s).', $context)
        );
        self::assertNull(
            $result->operatorId,
            sprintf('A failed validation must carry no operator id (%s).', $context)
        );
    }

    /**
     * Property 15 core: for arbitrary non-blank credentials that do not
     * authenticate, the "unknown username" failure and the "known username,
     * wrong password" failure are indistinguishable.
     *
     * The known operator stores a hash of a fixed sentinel password that no
     * generated password can equal, so case (B) is genuinely a wrong-password
     * failure (never an accidental success). Case (A) passes operator = null.
     * Both must yield the identical generic failure, and the two results must
     * be equal field-by-field.
     */
    public function testUnknownUsernameAndWrongPasswordFailuresAreIndistinguishable(): void
    {
        // Built once for the whole property run (validation is pure, so a single
        // shared instance behaves identically to a fresh one each iteration).
        $validator = new CredentialValidator();
        // A stored hash guaranteed never to verify against any generated
        // password, so the "known username" case is a real wrong-password fail.
        $nonMatchingHash = self::testHash(self::SENTINEL_PASSWORD);

        $this->limitTo($this->iterations());
        $this->forAll(
            // arbitrary non-blank submitted username
            Generator\choose(0, count(self::CONTENT) - 1),
            Generator\seq(Generator\choose(0, count(self::CONTENT) - 1)),
            // arbitrary non-blank submitted password (the one that will fail)
            Generator\choose(0, count(self::CONTENT) - 1),
            Generator\seq(Generator\choose(0, count(self::CONTENT) - 1)),
            // arbitrary operator id for the "known username" case
            Generator\choose(1, 100_000)
        )->then(function (
            int $userHead,
            array $userTail,
            int $passHead,
            array $passTail,
            int $operatorId
        ) use ($validator, $nonMatchingHash): void {
            $username = $this->buildContent($userHead, $userTail);
            $submittedPassword = $this->buildContent($passHead, $passTail);

            // Case B's operator: a real record whose stored hash is of the
            // sentinel password, which the CONTENT alphabet cannot spell, so
            // verify() against $submittedPassword can only fail -> wrong-password.
            $knownOperator = [
                'id' => $operatorId,
                'username' => $username,
                'passwordHash' => $nonMatchingHash,
            ];

            // (A) unknown username: no operator record.
            $unknownUsername = $validator->validate($username, $submittedPassword, null);
            // (B) known username, wrong password: real operator, non-matching.
            $wrongPassword = $validator->validate($username, $submittedPassword, $knownOperator);

            $context = sprintf(
                'user=%s, pass=%s, operatorId=%d',
                var_export($username, true),
                var_export($submittedPassword, true),
                $operatorId
            );

            // Each failure is the generic INVALID_CREDENTIALS rejection ...
            $this->assertInvalidCredentials($unknownUsername, 'unknown username; ' . $context);
            $this->assertInvalidCredentials($wrongPassword, 'wrong password; ' . $context);

            // ... and, field-by-field, the two outcomes cannot be told apart.
            self::assertSame(
                $unknownUsername->isValid,
                $wrongPassword->isValid,
                sprintf('isValid differs between the two failure cases (%s).', $context)
            );
            self::assertSame(
                $unknownUsername->error,
                $wrongPassword->error,
                sprintf('error code differs between the two failure cases (%s).', $context)
            );
            self::assertSame(
                $unknownUsername->operatorId,
                $wrongPassword->operatorId,
                sprintf('operatorId differs between the two failure cases (%s).', $context)
            );
        });
    }

    /**
     * Property 15, positive control: the same validator DOES authenticate when
     * the known operator's stored hash actually verifies against the submitted
     * password. This guards against a degenerate implementation that returns
     * INVALID_CREDENTIALS unconditionally (which would trivially satisfy the
     * indistinguishability test). Here the "known, correct" case must SUCCEED
     * with the operator id, proving the failures above are real failures and
     * not a blanket rejection — and the wrong-password failure for that very
     * operator stays identical to the unknown-username failure.
     */
    public function testMatchingCredentialsSucceedSoFailuresAreMeaningful(): void
    {
        $validator = new CredentialValidator();

        $this->limitTo($this->iterations());
        $this->forAll(
            Generator\choose(0, count(self::CONTENT) - 1),
            Generator\seq(Generator\choose(0, count(self::CONTENT) - 1)),
            Generator\choose(0, count(self::CONTENT) - 1),
            Generator\seq(Generator\choose(0, count(self::CONTENT) - 1)),
            Generator\choose(1, 100_000)
        )->then(function (
            int $userHead,
            array $userTail,
            int $passHead,
            array $passTail,
            int $operatorId
        ) use ($validator): void {
            $username = $this->buildContent($userHead, $userTail);
            $password = $this->buildContent($passHead, $passTail);

            $operator = [
                'id' => $operatorId,
                'username' => $username,
                'passwordHash' => self::testHash($password),
            ];

            $context = sprintf(
                'user=%s, pass=%s, operatorId=%d',
                var_export($username, true),
                var_export($password, true),
                $operatorId
            );

            // Correct credentials -> success with the operator id and no error.
            $success = $validator->validate($username, $password, $operator);
            self::assertTrue(
                $success->isValid,
                sprintf('Matching credentials must authenticate (%s).', $context)
            );
            self::assertSame(
                $operatorId,
                $success->operatorId,
                sprintf('Success must carry the operator id (%s).', $context)
            );
            self::assertNull(
                $success->error,
                sprintf('Success must carry no error code (%s).', $context)
            );

            // The SAME operator with a wrong password fails generically, and
            // that failure is identical to the unknown-username failure. The
            // wrong password differs from $password at its first byte (see
            // differentPassword), so bcrypt's 72-byte truncation cannot make a
            // long password collide with its own hash.
            $wrongSubmitted = $this->differentPassword($password);
            $wrongPassword = $validator->validate($username, $wrongSubmitted, $operator);
            $unknownUsername = $validator->validate($username, $wrongSubmitted, null);

            $this->assertInvalidCredentials($wrongPassword, 'wrong password; ' . $context);
            $this->assertInvalidCredentials($unknownUsername, 'unknown username; ' . $context);
            self::assertSame(
                $unknownUsername->error,
                $wrongPassword->error,
                sprintf('Failure codes must match across cases (%s).', $context)
            );
            self::assertSame(
                $unknownUsername->operatorId,
                $wrongPassword->operatorId,
                sprintf('Failure operatorId must match across cases (%s).', $context)
            );
        });
    }
}
