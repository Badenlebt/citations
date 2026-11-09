<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the central error-code -> HTTP-status mapping and the uniform
 * error response body shape (design.md "Zuordnung Fehlercode -> Statuscode",
 * Requirements 15.7).
 */
final class ErrorCodeTest extends TestCase
{
    /**
     * The authoritative code -> status table from design.md. Every row is
     * asserted against both the enum case value (stable string) and the
     * mapped HTTP status.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function codeStatusTable(): array
    {
        return [
            'MISSING_QUERY' => ['MISSING_QUERY', 400],
            'QUERY_TOO_LONG' => ['QUERY_TOO_LONG', 400],
            'MISSING_TEXT' => ['MISSING_TEXT', 400],
            'INVALID_SOURCE' => ['INVALID_SOURCE', 400],
            'INVALID_LANGUAGE' => ['INVALID_LANGUAGE', 400],
            'MISSING_NAME' => ['MISSING_NAME', 400],
            'INVALID_LINK_URL' => ['INVALID_LINK_URL', 400],
            'INPUT_TOO_LONG' => ['INPUT_TOO_LONG', 400],
            'MISSING_LANGUAGE_NAME' => ['MISSING_LANGUAGE_NAME', 400],
            'UNKNOWN_SOURCE_UNDELETABLE' => ['UNKNOWN_SOURCE_UNDELETABLE', 400],
            'MISSING_CREDENTIALS' => ['MISSING_CREDENTIALS', 400],
            'INVALID_CREDENTIALS' => ['INVALID_CREDENTIALS', 401],
            'UNAUTHORIZED' => ['UNAUTHORIZED', 401],
            'NOT_FOUND' => ['NOT_FOUND', 404],
            'NO_QUOTES' => ['NO_QUOTES', 404],
            'SOURCE_HAS_QUOTES' => ['SOURCE_HAS_QUOTES', 409],
            'UNKNOWN_SOURCE_RESERVED' => ['UNKNOWN_SOURCE_RESERVED', 409],
            'LANGUAGE_EXISTS' => ['LANGUAGE_EXISTS', 409],
            'RATE_LIMITED' => ['RATE_LIMITED', 429],
            'INTERNAL_ERROR' => ['INTERNAL_ERROR', 500],
        ];
    }

    #[DataProvider('codeStatusTable')]
    public function testCodeMapsToExpectedHttpStatus(string $code, int $expectedStatus): void
    {
        $errorCode = ErrorCode::from($code);

        self::assertSame(
            $code,
            $errorCode->value,
            'Enum case value must equal the stable error code string.',
        );
        self::assertSame(
            $expectedStatus,
            $errorCode->httpStatus(),
            sprintf('%s must map to HTTP %d.', $code, $expectedStatus),
        );
    }

    public function testEveryEnumCaseIsCoveredByTheTable(): void
    {
        $tableCodes = array_map(
            static fn (array $row): string => $row[0],
            array_values(self::codeStatusTable()),
        );
        $enumCodes = array_map(
            static fn (ErrorCode $case): string => $case->value,
            ErrorCode::cases(),
        );

        sort($tableCodes);
        sort($enumCodes);

        self::assertSame(
            $enumCodes,
            $tableCodes,
            'The table must cover exactly the defined error codes (no more, no less).',
        );
        self::assertCount(20, ErrorCode::cases(), 'Exactly 20 error codes are defined.');
    }

    public function testResponseBodyHasExactlyErrorAndMessageKeys(): void
    {
        $body = ErrorCode::NOT_FOUND->toResponseBody('Resource not found.');

        self::assertSame(['error', 'message'], array_keys($body));
        self::assertSame('NOT_FOUND', $body['error']);
        self::assertSame('Resource not found.', $body['message']);
    }

    public function testResponseBodyNeverLeaksInternalDetailKeys(): void
    {
        foreach (ErrorCode::cases() as $case) {
            $body = $case->toResponseBody('human-readable message');

            self::assertSame(
                ['error', 'message'],
                array_keys($body),
                sprintf('%s payload must only expose error and message.', $case->value),
            );

            foreach (['detail', 'details', 'trace', 'stack', 'stackTrace', 'sql', 'exception', 'credentials', 'password'] as $forbidden) {
                self::assertArrayNotHasKey(
                    $forbidden,
                    $body,
                    sprintf('%s payload must not expose internal key "%s".', $case->value, $forbidden),
                );
            }
        }
    }
}
