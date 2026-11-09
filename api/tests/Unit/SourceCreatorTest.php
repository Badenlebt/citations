<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\SourceCreator;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for the create/edit happy path of {@see SourceCreator}
 * (Requirements 10.1, 10.3, 10.4, 10.6). Covers the positive round-trip and
 * the link-URL prefix rule that is in scope for this path.
 */
final class SourceCreatorTest extends TestCase
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

    public function testCreateTrimsNameStoresOptionalFieldsAndReturnsId(): void
    {
        $result = $this->creator->create(
            '  Marcus Aurelius  ',
            '121-04-26',
            '180-03-17',
            'Roman emperor and Stoic philosopher.',
            'https://example.com/aurelius',
        );

        self::assertTrue($result->isSuccess);
        self::assertNull($result->error);
        self::assertNotNull($result->id);

        $stored = $this->sources->findById($result->id);
        self::assertNotNull($stored);
        self::assertSame('Marcus Aurelius', $stored['name']);
        self::assertSame('121-04-26', $stored['birthDate']);
        self::assertSame('180-03-17', $stored['deathDate']);
        self::assertSame('Roman emperor and Stoic philosopher.', $stored['description']);
        self::assertSame('https://example.com/aurelius', $stored['linkUrl']);
        self::assertFalse($stored['isUnknown']);
    }

    public function testCreateWithoutOptionalFieldsStoresNulls(): void
    {
        $result = $this->creator->create('Seneca');

        self::assertTrue($result->isSuccess);
        $stored = $this->sources->findById($result->id);
        self::assertNotNull($stored);
        self::assertSame('Seneca', $stored['name']);
        self::assertNull($stored['birthDate']);
        self::assertNull($stored['deathDate']);
        self::assertNull($stored['description']);
        self::assertNull($stored['linkUrl']);
    }

    public function testCreateTreatsWhitespaceOnlyOptionalFieldsAsUnset(): void
    {
        $result = $this->creator->create('Epictetus', '   ', '', '  ', '   ');

        self::assertTrue($result->isSuccess);
        $stored = $this->sources->findById($result->id);
        self::assertNotNull($stored);
        self::assertNull($stored['birthDate']);
        self::assertNull($stored['deathDate']);
        self::assertNull($stored['description']);
        self::assertNull($stored['linkUrl']);
    }

    public function testCreateAcceptsHttpAndHttpsLinkUrlCaseInsensitively(): void
    {
        $http = $this->creator->create('A', linkUrl: 'HTTP://example.com');
        $https = $this->creator->create('B', linkUrl: 'HttpS://example.com');

        self::assertTrue($http->isSuccess);
        self::assertTrue($https->isSuccess);
    }

    public function testCreateRejectsLinkUrlWithoutHttpPrefix(): void
    {
        $result = $this->creator->create('A', linkUrl: 'ftp://example.com');

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::INVALID_LINK_URL, $result->error);
        self::assertNull($result->id);
    }

    public function testCreateAcceptsNameAtBoundaryLengths(): void
    {
        $one = $this->creator->create('x');
        $twoHundred = $this->creator->create(str_repeat('a', 200));

        self::assertTrue($one->isSuccess);
        self::assertTrue($twoHundred->isSuccess);
    }

    public function testEditOverwritesExistingSource(): void
    {
        $created = $this->creator->create('Old Name', description: 'old');
        self::assertTrue($created->isSuccess);

        $edited = $this->creator->edit(
            $created->id,
            '  New Name  ',
            '100',
            '200',
            'new description',
            'https://new.example.com',
        );

        self::assertTrue($edited->isSuccess);
        self::assertSame($created->id, $edited->id);

        $stored = $this->sources->findById($created->id);
        self::assertNotNull($stored);
        self::assertSame('New Name', $stored['name']);
        self::assertSame('100', $stored['birthDate']);
        self::assertSame('200', $stored['deathDate']);
        self::assertSame('new description', $stored['description']);
        self::assertSame('https://new.example.com', $stored['linkUrl']);
    }

    public function testEditClearsOptionalFieldsWhenOmitted(): void
    {
        $created = $this->creator->create('Name', 'b', 'd', 'desc', 'https://x.example.com');
        self::assertTrue($created->isSuccess);

        $edited = $this->creator->edit($created->id, 'Name');

        self::assertTrue($edited->isSuccess);
        $stored = $this->sources->findById($created->id);
        self::assertNotNull($stored);
        self::assertNull($stored['birthDate']);
        self::assertNull($stored['deathDate']);
        self::assertNull($stored['description']);
        self::assertNull($stored['linkUrl']);
    }

    public function testEditReturnsNotFoundForMissingSource(): void
    {
        $result = $this->creator->edit(999, 'Whoever');

        self::assertFalse($result->isSuccess);
        self::assertSame(ErrorCode::NOT_FOUND, $result->error);
    }
}
