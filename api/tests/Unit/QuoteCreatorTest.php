<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\QuoteCreator;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see QuoteCreator} create and edit input validation
 * (Requirements 8.2, 8.3, 8.4, 8.6, 9.2, 9.3, 9.6). Each invalid-input case
 * must return the right ErrorCode AND leave the stored quotes unchanged, for
 * both create and edit; valid edit overwrites, missing quote id ⇒ NOT_FOUND.
 */
final class QuoteCreatorTest extends TestCase
{
    private InMemoryStore $store;
    private InMemoryQuoteRepository $quotes;
    private QuoteCreator $creator;
    private int $sourceId;
    private int $languageId;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->quotes = new InMemoryQuoteRepository($this->store);
        $sources = new InMemorySourceRepository($this->store);
        $languages = new InMemoryLanguageRepository($this->store);

        $this->sourceId = $sources->insert('Seneca', []);
        $this->languageId = $languages->insert('Deutsch', 'deutsch');

        $this->creator = new QuoteCreator($this->quotes, $sources, $languages);
    }

    /** Snapshot every stored quote so a "no state change" assertion is exact. */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (array_keys($this->store->quotes) as $id) {
            $snapshot[$id] = $this->quotes->findById($id);
        }

        return $snapshot;
    }

    public function testCreateHappyPathStoresQuote(): void
    {
        $result = $this->creator->create('  Hello world  ', $this->sourceId, $this->languageId);

        self::assertTrue($result->isSuccess);
        self::assertNotNull($result->id);
        $stored = $this->quotes->findById($result->id);
        self::assertSame('Hello world', $stored['text']);
        self::assertSame(1, $this->quotes->count());
    }

    public function testCreateNullSourceFallsBackToUnknown(): void
    {
        $result = $this->creator->create('Text', null, $this->languageId);

        self::assertTrue($result->isSuccess);
        $stored = $this->quotes->findById($result->id);
        self::assertSame(InMemoryStore::UNKNOWN_SOURCE_ID, $stored['source']['id']);
    }

    /**
     * @dataProvider invalidCreateInputs
     */
    public function testInvalidCreateReturnsCodeAndChangesNothing(
        string $text,
        ?int $sourceOffset,
        bool $badLanguage,
        ErrorCode $expected,
    ): void {
        $before = $this->snapshot();

        $sourceId = $sourceOffset === null ? null : ($sourceOffset === -1 ? 9999 : $this->sourceId);
        $languageId = $badLanguage ? 9999 : $this->languageId;

        $result = $this->creator->create($text, $sourceId, $languageId);

        self::assertFalse($result->isSuccess);
        self::assertSame($expected, $result->error);
        self::assertNull($result->id);
        self::assertSame($before, $this->snapshot(), 'create must not change state on invalid input');
    }

    public static function invalidCreateInputs(): array
    {
        return [
            'empty text' => ['', 0, false, ErrorCode::MISSING_TEXT],
            'whitespace text' => ['   ', 0, false, ErrorCode::MISSING_TEXT],
            'too long text' => [str_repeat('a', 5001), 0, false, ErrorCode::INPUT_TOO_LONG],
            'invalid source' => ['ok', -1, false, ErrorCode::INVALID_SOURCE],
            'invalid language' => ['ok', 0, true, ErrorCode::INVALID_LANGUAGE],
        ];
    }

    public function testCreateAcceptsTextAtExactly5000(): void
    {
        $result = $this->creator->create(str_repeat('a', 5000), $this->sourceId, $this->languageId);
        self::assertTrue($result->isSuccess);
    }

    public function testEditOverwritesExistingQuote(): void
    {
        $created = $this->creator->create('Original', $this->sourceId, $this->languageId);
        $otherSource = (new InMemorySourceRepository($this->store))->insert('Cicero', []);

        $edited = $this->creator->edit($created->id, '  Updated  ', $otherSource, $this->languageId);

        self::assertTrue($edited->isSuccess);
        self::assertSame($created->id, $edited->id);
        $stored = $this->quotes->findById($created->id);
        self::assertSame('Updated', $stored['text']);
        self::assertSame($otherSource, $stored['source']['id']);
        self::assertSame(1, $this->quotes->count());
    }

    public function testEditMissingQuoteReturnsNotFoundAndChangesNothing(): void
    {
        $this->creator->create('Keep me', $this->sourceId, $this->languageId);
        $before = $this->snapshot();

        $result = $this->creator->edit(9999, 'New text', $this->sourceId, $this->languageId);

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::NOT_FOUND, $result->error);
        self::assertSame($before, $this->snapshot());
    }

    /**
     * @dataProvider invalidCreateInputs
     */
    public function testInvalidEditReturnsCodeAndChangesNothing(
        string $text,
        ?int $sourceOffset,
        bool $badLanguage,
        ErrorCode $expected,
    ): void {
        $created = $this->creator->create('Existing', $this->sourceId, $this->languageId);
        $before = $this->snapshot();

        $sourceId = $sourceOffset === null ? null : ($sourceOffset === -1 ? 9999 : $this->sourceId);
        $languageId = $badLanguage ? 9999 : $this->languageId;

        $result = $this->creator->edit($created->id, $text, $sourceId, $languageId);

        self::assertFalse($result->isSuccess);
        self::assertSame($expected, $result->error);
        self::assertSame($before, $this->snapshot(), 'edit must not change state on invalid input');
    }

    public function testDeleteRemovesExactlyTheTargetQuote(): void
    {
        $keep = $this->creator->create('Keep me', $this->sourceId, $this->languageId);
        $drop = $this->creator->create('Drop me', $this->sourceId, $this->languageId);
        $keptSnapshot = $this->quotes->findById($keep->id);

        $result = $this->creator->delete($drop->id);

        // (9.4) The targeted quote is gone and returned by id; nothing else moved.
        self::assertTrue($result->isSuccess);
        self::assertSame($drop->id, $result->id);
        self::assertNull($this->quotes->findById($drop->id));
        self::assertSame(1, $this->quotes->count());
        self::assertSame($keptSnapshot, $this->quotes->findById($keep->id));
    }

    public function testDeleteMissingQuoteReturnsNotFoundAndChangesNothing(): void
    {
        $this->creator->create('Keep me', $this->sourceId, $this->languageId);
        $before = $this->snapshot();

        $result = $this->creator->delete(9999);

        // (9.5) Unknown id ⇒ NOT_FOUND, no state change.
        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::NOT_FOUND, $result->error);
        self::assertNull($result->id);
        self::assertSame($before, $this->snapshot());
    }
}
