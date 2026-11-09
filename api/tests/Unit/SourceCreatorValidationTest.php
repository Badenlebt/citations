<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\SourceCreator;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Validation tests for {@see SourceCreator} covering Requirements 10.2, 10.3,
 * 10.4, 10.5, 10.7: trim-empty name ⇒ MISSING_NAME; link URL without http(s)://
 * ⇒ INVALID_LINK_URL; any length-bounded field over its maximum ⇒
 * INPUT_TOO_LONG; each WITHOUT a state change, for both create and edit.
 */
final class SourceCreatorValidationTest extends TestCase
{
    private InMemoryStore $store;
    private InMemorySourceRepository $sources;
    private SourceCreator $creator;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->sources = new InMemorySourceRepository($this->store);
        $this->creator = new SourceCreator($this->sources);
    }

    /** Snapshot every stored source for an exact no-state-change assertion. */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (array_keys($this->store->sources) as $id) {
            $snapshot[$id] = $this->sources->findById($id);
        }

        return $snapshot;
    }

    /**
     * @return array<string, array{0: array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}, 1: ErrorCode}>
     */
    public static function invalidInputs(): array
    {
        return [
            'empty name' => [['', null, null, null, null], ErrorCode::MISSING_NAME],
            'whitespace name' => [['   ', null, null, null, null], ErrorCode::MISSING_NAME],
            'name too long' => [[str_repeat('a', 201), null, null, null, null], ErrorCode::INPUT_TOO_LONG],
            'birthDate too long' => [['Name', str_repeat('b', 501), null, null, null], ErrorCode::INPUT_TOO_LONG],
            'deathDate too long' => [['Name', null, str_repeat('d', 501), null, null], ErrorCode::INPUT_TOO_LONG],
            'description too long' => [['Name', null, null, str_repeat('x', 501), null], ErrorCode::INPUT_TOO_LONG],
            'linkUrl too long' => [['Name', null, null, null, 'https://e.x/' . str_repeat('a', 2000)], ErrorCode::INPUT_TOO_LONG],
            'linkUrl no scheme' => [['Name', null, null, null, 'ftp://example.com'], ErrorCode::INVALID_LINK_URL],
            'linkUrl bare host' => [['Name', null, null, null, 'example.com'], ErrorCode::INVALID_LINK_URL],
        ];
    }

    /**
     * @dataProvider invalidInputs
     * @param array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string} $args
     */
    public function testInvalidCreateReturnsCodeAndChangesNothing(array $args, ErrorCode $expected): void
    {
        $before = $this->snapshot();

        $result = $this->creator->create(...$args);

        self::assertFalse($result->isSuccess);
        self::assertSame($expected, $result->error);
        self::assertNull($result->id);
        self::assertSame($before, $this->snapshot(), 'create must not change state on invalid input');
    }

    /**
     * @dataProvider invalidInputs
     * @param array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string} $args
     */
    public function testInvalidEditReturnsCodeAndChangesNothing(array $args, ErrorCode $expected): void
    {
        $created = $this->creator->create('Existing Source', 'b', 'd', 'desc', 'https://keep.example.com');
        self::assertTrue($created->isSuccess);
        $before = $this->snapshot();

        $result = $this->creator->edit($created->id, ...$args);

        self::assertFalse($result->isSuccess);
        self::assertSame($expected, $result->error);
        self::assertSame($before, $this->snapshot(), 'edit must not change state on invalid input');
    }

    public function testBoundaryLengthsAreAccepted(): void
    {
        $result = $this->creator->create(
            str_repeat('n', 200),
            str_repeat('b', 500),
            str_repeat('d', 500),
            str_repeat('x', 500),
            'https://e.x/' . str_repeat('a', 2000 - 12),
        );

        self::assertTrue($result->isSuccess, 'fields exactly at their maximum length must be accepted');
        $stored = $this->sources->findById($result->id);
        self::assertSame(2000, mb_strlen($stored['linkUrl'], 'UTF-8'));
    }
}
