<?php

declare(strict_types=1);

namespace Citations\Tests\Property;

use Citations\CredentialValidationResult;
use Citations\CredentialValidator;
use Citations\ErrorCode;
use Citations\PasswordHasher;
use Eris\Generator;

/**
 * Feature: citations-management, Property 16: Fehlende oder Whitespace-Zugangsdaten
 * werden ohne Passwortprüfung abgewiesen.
 *
 * For any pair of submitted credentials where at least one of username or
 * password is absent or, after trimming surrounding whitespace, empty,
 * {@see CredentialValidator::validate()} must reject with
 * MISSING_CREDENTIALS — and it must do so WITHOUT letting any password
 * verification influence the outcome (design "Auth-API" step 6.3 before 6.2;
 * authentication flow "Eingabe trimmen & pruefen (6.3)").
 *
 * The "no password check" half is the subtle one. The completeness check (6.3)
 * runs on the RAW inputs and short-circuits before the single hash comparison
 * of the validity check (6.2). This test proves the short-circuit by handing
 * the validator operator states and password values for which a validity check,
 * IF it ran, could only ever produce INVALID_CREDENTIALS or SUCCESS — never
 * MISSING_CREDENTIALS. Since the outcome is MISSING_CREDENTIALS regardless, the
 * validity path cannot have decided it. The strongest case is the "matching
 * pair with one blank field": a real operator whose stored hash verifies
 * against the supplied password, yet the other field is blank. Were the
 * password ever checked, the result would be SUCCESS; it stays
 * MISSING_CREDENTIALS, so no password verification took place.
 *
 * Validates: Requirements 6.3
 */
final class CredentialValidatorMissingCredentialsPropertyTest extends PropertyTestCase
{
    /**
     * Whitespace characters that {@see trim()} strips: space, tab, newline,
     * carriage return, NUL and vertical tab. A value built solely from these
     * (or the empty string, or null) is "blank" per the completeness rule.
     *
     * @var list<string>
     */
    private const WHITESPACE = [' ', "\t", "\n", "\r", "\0", "\x0B"];

    /**
     * Alphabet for arbitrary NON-blank field values, so the "other" field and
     * matching passwords contain at least one trimmable-surviving character.
     *
     * @var list<string>
     */
    private const CONTENT = [
        'a', 'B', 'c', 'D', 'e', 'F', '1', '9', '_', '-', '.', '@', 'x', 'Z',
    ];

    /**
     * Build a blank string (only whitespace) of the given length from the
     * whitespace alphabet, driven by shrinkable integer seeds.
     *
     * @param list<int> $picks one whitespace-index selector per character
     */
    private function buildWhitespace(array $picks): string
    {
        $out = '';
        foreach ($picks as $p) {
            $out .= self::WHITESPACE[$p % count(self::WHITESPACE)];
        }

        return $out;
    }

    /**
     * Build an arbitrary non-blank string (at least one content character) from
     * the content alphabet.
     *
     * @param list<int> $picks one content-index selector per character
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
     * Turn a "blank variant" selector into a concrete blank value: null, the
     * empty string, or an arbitrary whitespace-only string.
     *
     * @param list<int> $wsPicks whitespace seeds used when variant demands them
     */
    private function blankValue(int $variant, array $wsPicks): ?string
    {
        return match ($variant % 3) {
            0 => null,
            1 => '',
            default => $this->buildWhitespace($wsPicks === [] ? [0] : $wsPicks),
        };
    }

    /**
     * Assert the result is the expected MISSING_CREDENTIALS rejection, carrying
     * no operator id and the exact error code.
     */
    private function assertMissingCredentials(CredentialValidationResult $result, string $context): void
    {
        self::assertFalse(
            $result->isValid,
            sprintf('Expected rejection but got success (%s).', $context)
        );
        self::assertNull(
            $result->operatorId,
            sprintf('Rejection must carry no operator id (%s).', $context)
        );
        self::assertSame(
            ErrorCode::MISSING_CREDENTIALS,
            $result->error,
            sprintf('Expected MISSING_CREDENTIALS (%s).', $context)
        );
    }

    /**
     * Property 16 core: whenever the username OR the password is blank (null,
     * empty, or whitespace-only), validate() returns MISSING_CREDENTIALS —
     * regardless of the other field's value, regardless of whether an operator
     * exists, and crucially even when the non-blank side would otherwise form a
     * VALID, matching credential pair. A validity check, if it ran, could only
     * yield SUCCESS or INVALID_CREDENTIALS here, so MISSING_CREDENTIALS proves
     * the completeness check short-circuited before any password verification.
     */
    public function testBlankFieldIsRejectedBeforeAnyPasswordCheck(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // which field(s) are blank: 0 = username, 1 = password, 2 = both
            Generator\choose(0, 2),
            // blank variant per side (null / '' / whitespace)
            Generator\choose(0, 2),
            Generator\choose(0, 2),
            // whitespace seeds for whichever blank sides use whitespace
            Generator\seq(Generator\choose(0, count(self::WHITESPACE) - 1)),
            Generator\seq(Generator\choose(0, count(self::WHITESPACE) - 1)),
            // arbitrary non-blank content for the "other" (present) field
            Generator\choose(0, count(self::CONTENT) - 1),
            Generator\seq(Generator\choose(0, count(self::CONTENT) - 1)),
            // operator presence: 0 = no operator, 1 = operator whose hash
            //                    MATCHES the present field, 2 = operator whose
            //                    hash does NOT match the present field
            Generator\choose(0, 2),
            // arbitrary operator id
            Generator\choose(1, 100_000)
        )->then(function (
            int $blankSide,
            int $userVariant,
            int $passVariant,
            array $userWs,
            array $passWs,
            int $presentHead,
            array $presentTail,
            int $operatorMode,
            int $operatorId
        ): void {
            // A fresh validator per case: validation is pure, so a new instance
            // behaves identically to any other (the dummy hash only matters on
            // the validity path, which this property proves is never reached).
            $validator = new CredentialValidator();

            // The present (non-blank) value: used for whichever field is NOT
            // blanked, and also as the password a "matching" operator hashes.
            $present = $this->buildContent($presentHead, $presentTail);

            $blankUser = $this->blankValue($userVariant, $userWs);
            $blankPass = $this->blankValue($passVariant, $passWs);

            // Decide the submitted username/password per the chosen blank side.
            if ($blankSide === 0) {            // username blank, password present
                $username = $blankUser;
                $password = $present;
            } elseif ($blankSide === 1) {      // username present, password blank
                $username = $present;
                $password = $blankPass;
            } else {                            // both blank
                $username = $blankUser;
                $password = $blankPass;
            }

            // Build the operator record (or null). When it "matches", its hash
            // verifies against $present — so if the password were ever checked
            // against a non-blank password equal to $present, it would SUCCEED.
            $operator = match ($operatorMode) {
                0 => null,
                1 => [
                    'id' => $operatorId,
                    'username' => 'op',
                    'passwordHash' => PasswordHasher::hash($present),
                ],
                default => [
                    'id' => $operatorId,
                    'username' => 'op',
                    'passwordHash' => PasswordHasher::hash($present . 'X_not_matching'),
                ],
            };

            $result = $validator->validate($username, $password, $operator);

            $this->assertMissingCredentials(
                $result,
                sprintf(
                    'blankSide=%d, user=%s, pass=%s, operatorMode=%d',
                    $blankSide,
                    var_export($username, true),
                    var_export($password, true),
                    $operatorMode
                )
            );
        });
    }

    /**
     * Property 16, sharpened positive-control contrast: the most adversarial
     * input for the "no password check" claim is a genuinely MATCHING operator
     * paired with exactly one blank field. If any password verification leaked
     * into the decision, this would resolve to SUCCESS. The property requires
     * MISSING_CREDENTIALS, confirming completeness (6.3) wins before validity
     * (6.2) even when the credentials would otherwise be perfectly valid.
     */
    public function testMatchingOperatorWithOneBlankFieldStillMissing(): void
    {
        $this->limitTo($this->iterations());
        $this->forAll(
            // which single field is blank: 0 = username, 1 = password
            Generator\choose(0, 1),
            // blank variant (null / '' / whitespace)
            Generator\choose(0, 2),
            Generator\seq(Generator\choose(0, count(self::WHITESPACE) - 1)),
            // the valid, matching username + password material
            Generator\choose(0, count(self::CONTENT) - 1),
            Generator\seq(Generator\choose(0, count(self::CONTENT) - 1)),
            Generator\choose(0, count(self::CONTENT) - 1),
            Generator\seq(Generator\choose(0, count(self::CONTENT) - 1)),
            Generator\choose(1, 100_000)
        )->then(function (
            int $blankSide,
            int $blankVariant,
            array $blankWs,
            int $userHead,
            array $userTail,
            int $passHead,
            array $passTail,
            int $operatorId
        ): void {
            $validator = new CredentialValidator();

            $validUsername = $this->buildContent($userHead, $userTail);
            $validPassword = $this->buildContent($passHead, $passTail);

            // A real, matching operator: its stored hash verifies against the
            // valid password. This is the credential pair that WOULD log in.
            $operator = [
                'id' => $operatorId,
                'username' => $validUsername,
                'passwordHash' => PasswordHasher::hash($validPassword),
            ];

            $blank = $this->blankValue($blankVariant, $blankWs);

            if ($blankSide === 0) {
                $username = $blank;            // only the username is blanked
                $password = $validPassword;    // the matching password is present
            } else {
                $username = $validUsername;    // the matching username is present
                $password = $blank;            // only the password is blanked
            }

            $result = $validator->validate($username, $password, $operator);

            // Sanity: the pair really would be valid if both fields were sent.
            $full = $validator->validate($validUsername, $validPassword, $operator);
            self::assertTrue(
                $full->isValid,
                'Precondition failed: the un-blanked pair must be a valid login.'
            );

            $this->assertMissingCredentials(
                $result,
                sprintf(
                    'matching operator, blankSide=%d, user=%s, pass=%s',
                    $blankSide,
                    var_export($username, true),
                    var_export($password, true)
                )
            );
        });
    }
}
