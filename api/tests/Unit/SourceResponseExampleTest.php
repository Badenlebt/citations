<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\Http\Request;
use Citations\Http\Response;
use Citations\Http\SourceHandler;
use Citations\SourceCreator;
use Citations\SourceDeleter;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Requirement-level example tests for the Autoren-API single view
 * GET /api/sources/{id} (Requirements 5.1, 5.2).
 *
 * These are worked examples that document the public response contract rather
 * than re-proving the handler's branching (covered by
 * {@see SourceHandlerTest}):
 *   - 5.1: a fully populated source is returned EXACTLY as
 *     `{ id, name, birthDate, deathDate, description, linkUrl }`, and the
 *     internal `isUnknown` flag never leaks into the public body.
 *   - 5.2: optional fields that are not set come back as null — demonstrated for
 *     a source with no optionals at all and for a partially populated source
 *     where set and unset fields are mixed.
 *
 * The {@see SourceHandler} is driven over the in-memory repositories, assembled
 * exactly as in {@see SourceHandlerTest}, and the assertions compare the exact
 * associative array shape of the response body.
 */
final class SourceResponseExampleTest extends TestCase
{
    private InMemoryStore $store;
    private InMemorySourceRepository $sources;
    private SourceHandler $handler;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->sources = new InMemorySourceRepository($this->store);
        $quotes = new InMemoryQuoteRepository($this->store);

        $this->handler = new SourceHandler(
            new SourceCreator($this->sources),
            new SourceDeleter($this->sources, $quotes),
            $this->sources,
        );
    }

    private function get(int $id): Response
    {
        return $this->handler->handle(new Request('GET', "sources/{$id}"), ['id' => $id]);
    }

    /**
     * 5.1: a fully populated source returns the complete single view with every
     * field carrying its stored value, and nothing else — in particular the
     * internal `isUnknown` flag is absent from the public body.
     */
    public function testFullyPopulatedSourceReturnsExactSingleViewWithoutIsUnknown(): void
    {
        $id = $this->sources->insert('Marcus Aurelius', [
            'birthDate' => '121 n. Chr.',
            'deathDate' => '180 n. Chr.',
            'description' => 'Römischer Kaiser und stoischer Philosoph',
            'linkUrl' => 'https://example.test/marcus-aurelius',
        ]);

        $response = $this->get($id);

        self::assertSame(200, $response->status);
        self::assertSame([
            'id' => $id,
            'name' => 'Marcus Aurelius',
            'birthDate' => '121 n. Chr.',
            'deathDate' => '180 n. Chr.',
            'description' => 'Römischer Kaiser und stoischer Philosoph',
            'linkUrl' => 'https://example.test/marcus-aurelius',
        ], $response->body);
        self::assertArrayNotHasKey('isUnknown', $response->body);
        self::assertSame(
            ['id', 'name', 'birthDate', 'deathDate', 'description', 'linkUrl'],
            array_keys($response->body),
        );
    }

    /**
     * 5.2: a source with none of the optional fields set returns birthDate,
     * deathDate, description and linkUrl all as null, while id and name carry
     * their values.
     */
    public function testSourceWithNoOptionalFieldsReturnsThemAllAsNull(): void
    {
        $id = $this->sources->insert('Epiktet');

        $response = $this->get($id);

        self::assertSame(200, $response->status);
        self::assertSame([
            'id' => $id,
            'name' => 'Epiktet',
            'birthDate' => null,
            'deathDate' => null,
            'description' => null,
            'linkUrl' => null,
        ], $response->body);
        self::assertArrayNotHasKey('isUnknown', $response->body);
    }

    /**
     * 5.2: a partially populated source shows the mix — the set optionals carry
     * their value, the unset optionals come back as null.
     */
    public function testPartiallyPopulatedSourceReturnsSetValuesAndNullForUnset(): void
    {
        $id = $this->sources->insert('Heraklit', [
            'linkUrl' => 'https://example.test/heraklit',
        ]);

        $response = $this->get($id);

        self::assertSame(200, $response->status);
        self::assertSame([
            'id' => $id,
            'name' => 'Heraklit',
            'birthDate' => null,
            'deathDate' => null,
            'description' => null,
            'linkUrl' => 'https://example.test/heraklit',
        ], $response->body);
        self::assertArrayNotHasKey('isUnknown', $response->body);
    }
}
