<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\Http\Dispatcher;
use Citations\Http\QuoteHandler;
use Citations\Http\Request;
use Citations\Http\SessionGuard;
use Citations\Http\SourceHandler;
use Citations\QuoteCreator;
use Citations\Repository\QuoteRepositoryInterface;
use Citations\Repository\SourceRepositoryInterface;
use Citations\SourceCreator;
use Citations\SourceDeleter;
use Citations\TokenAuthenticator;
use Citations\Tests\Fake\FixedClock;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemoryQuoteRepository;
use Citations\Tests\Fake\InMemorySessionRepository;
use Citations\Tests\Fake\InMemorySourceRepository;
use Citations\Tests\Fake\InMemoryStore;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Integration-style example test for the management list reads
 * GET /quotes and GET /sources (Requirements 18.6, 19.6) driven through the
 * FULL request pipeline exactly as `api/index.php` wires it:
 * {@see SessionGuard} -> {@see Dispatcher} -> the REAL {@see QuoteHandler} /
 * {@see SourceHandler} over the in-memory repositories.
 *
 * The {@see SessionGuardTest} (task 17.2) already proves the guard's structural
 * decision in isolation with a stub pipeline. This test adds a distinct guarantee
 * that would otherwise go unchecked: that when the guard is composed with the
 * concrete dispatcher and the concrete list handlers, an unauthenticated request
 * is rejected with a uniform 401 UNAUTHORIZED and the list handler's repository
 * read is NEVER reached — so no items/total envelope is ever produced and no data
 * is returned (18.6, 19.6). A spying repository whose {@see listAll()} flips a
 * flag is the proof of "no data access".
 *
 * It stays at the in-memory layer (no database): the live-DB integration suite
 * ({@see \Citations\Tests\Integration\IntegrationTestCase}) is strictly for the
 * PDO/MariaDB I/O path and skips without a disposable DB, whereas this guard
 * composition carries no persistence concern and is fully exercised with the
 * fakes.
 */
final class ManagementListGuardPipelineTest extends TestCase
{
    /** A well-formed but never-issued token: 64 lowercase hex chars. */
    private const UNKNOWN_TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** The token under which a valid session is seeded for the allowed-path case. */
    private const VALID_TOKEN = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    private DateTimeImmutable $checkTime;
    private InMemoryStore $store;
    private SpyingQuoteRepository $quoteSpy;
    private SpyingSourceRepository $sourceSpy;
    private SessionGuard $guard;

    protected function setUp(): void
    {
        $this->checkTime = new DateTimeImmutable('2024-01-01 12:00:00');
        $this->store = new InMemoryStore();

        $this->quoteSpy = new SpyingQuoteRepository(new InMemoryQuoteRepository($this->store));
        $this->sourceSpy = new SpyingSourceRepository(new InMemorySourceRepository($this->store));

        $quoteHandler = new QuoteHandler(
            new QuoteCreator($this->quoteSpy, $this->sourceSpy, new InMemoryLanguageRepository($this->store)),
            $this->quoteSpy,
        );
        $sourceHandler = new SourceHandler(
            new SourceCreator($this->sourceSpy),
            new SourceDeleter($this->sourceSpy, $this->quoteSpy),
            $this->sourceSpy,
        );

        $dispatcher = new Dispatcher([
            Dispatcher::QUOTES => $quoteHandler,
            Dispatcher::SOURCES => $sourceHandler,
        ]);

        $this->guard = new SessionGuard(
            $dispatcher,
            new TokenAuthenticator(new InMemorySessionRepository($this->store)),
            new FixedClock($this->checkTime),
        );
    }

    /**
     * The two management list routes crossed with each kind of unauthenticated
     * token. Every combination must be rejected with 401 before the list
     * handler reads anything (18.6, 19.6).
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function unauthenticatedListReads(): array
    {
        $malformed = 'not-a-valid-token';
        $expired = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';

        $cases = [];
        foreach (['GET /quotes' => 'quotes', 'GET /sources' => 'sources'] as $route => $path) {
            $cases["$route no token"] = [$path, null];
            $cases["$route malformed"] = [$path, $malformed];
            $cases["$route unknown 64-hex"] = [$path, self::UNKNOWN_TOKEN];
            $cases["$route expired"] = [$path, $expired];
        }

        return $cases;
    }

    #[DataProvider('unauthenticatedListReads')]
    public function testUnauthenticatedListReadReturns401AndNeverReachesRepository(string $path, ?string $token): void
    {
        // For the expired-token rows, seed an already-expired session so the
        // token is assignable yet not authenticated (6.4 expiry path).
        if ($token === 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100') {
            $this->store->sessions[$token] = [
                'token' => $token,
                'operatorId' => 1,
                'createdAt' => $this->checkTime->modify('-48 hours'),
                'expiresAt' => $this->checkTime->modify('-24 hours'),
            ];
        }

        $response = $this->guard->dispatch(new Request('GET', $path, [], [], $token));

        self::assertSame(ErrorCode::UNAUTHORIZED->httpStatus(), $response->status);
        self::assertSame('UNAUTHORIZED', $response->body['error']);
        self::assertSame(SessionGuard::UNAUTHORIZED_MESSAGE, $response->body['message']);

        // No data leaked: the 401 body is only { error, message }, never an
        // { items, total, ... } envelope (18.6, 19.6).
        self::assertArrayNotHasKey('items', $response->body);
        self::assertArrayNotHasKey('total', $response->body);

        // The list handler's repository read was never reached, so no data was
        // accessed on the way to the rejection (18.6, 19.6).
        self::assertFalse($this->quoteSpy->listAllCalled, 'quote repository listAll must not run for an unauthenticated read');
        self::assertFalse($this->sourceSpy->listAllCalled, 'source repository listAll must not run for an unauthenticated read');
    }

    /**
     * A valid session takes the request all the way through the pipeline to the
     * real list handler, which reads the repository and returns the list
     * envelope. This anchors the negative assertions above: the repository IS
     * reached precisely when the session is valid.
     *
     * @return array<string, array{0: string}>
     */
    public static function listRoutes(): array
    {
        return ['GET /quotes' => ['quotes'], 'GET /sources' => ['sources']];
    }

    #[DataProvider('listRoutes')]
    public function testAuthenticatedListReadReachesHandlerAndReturnsEnvelope(string $path): void
    {
        $this->store->sessions[self::VALID_TOKEN] = [
            'token' => self::VALID_TOKEN,
            'operatorId' => 1,
            'createdAt' => $this->checkTime->modify('-1 hour'),
            'expiresAt' => $this->checkTime->modify('+1 hour'),
        ];

        $response = $this->guard->dispatch(new Request('GET', $path, [], [], self::VALID_TOKEN));

        self::assertSame(200, $response->status);
        self::assertSame(['items', 'total', 'page', 'pageSize'], array_keys($response->body));

        $spy = $path === 'quotes' ? $this->quoteSpy : $this->sourceSpy;
        self::assertTrue($spy->listAllCalled, 'a valid session must let the list handler read the repository');
    }
}

/**
 * Decorator over a {@see QuoteRepositoryInterface} that records whether
 * {@see self::listAll()} was invoked, so a test can prove the list read is (not)
 * reached. All other calls delegate unchanged.
 */
final class SpyingQuoteRepository implements QuoteRepositoryInterface
{
    public bool $listAllCalled = false;

    public function __construct(private readonly QuoteRepositoryInterface $inner)
    {
    }

    public function listAll(int $page, int $pageSize): array
    {
        $this->listAllCalled = true;

        return $this->inner->listAll($page, $pageSize);
    }

    public function findById(int $id): ?array
    {
        return $this->inner->findById($id);
    }

    public function findByIndex(int $index): ?array
    {
        return $this->inner->findByIndex($index);
    }

    public function findAllForSearch(?int $sourceId = null): array
    {
        return $this->inner->findAllForSearch($sourceId);
    }

    public function exists(int $id): bool
    {
        return $this->inner->exists($id);
    }

    public function count(): int
    {
        return $this->inner->count();
    }

    public function insert(string $text, int $sourceId, int $languageId): int
    {
        return $this->inner->insert($text, $sourceId, $languageId);
    }

    public function update(int $id, string $text, int $sourceId, int $languageId): bool
    {
        return $this->inner->update($id, $text, $sourceId, $languageId);
    }

    public function delete(int $id): bool
    {
        return $this->inner->delete($id);
    }

    public function countBySource(int $sourceId): int
    {
        return $this->inner->countBySource($sourceId);
    }

    public function reassignSource(int $fromSourceId, int $toSourceId): int
    {
        return $this->inner->reassignSource($fromSourceId, $toSourceId);
    }
}

/**
 * Decorator over a {@see SourceRepositoryInterface} that records whether
 * {@see self::listAll()} was invoked. All other calls delegate unchanged.
 */
final class SpyingSourceRepository implements SourceRepositoryInterface
{
    public bool $listAllCalled = false;

    public function __construct(private readonly SourceRepositoryInterface $inner)
    {
    }

    public function listAll(int $page, int $pageSize): array
    {
        $this->listAllCalled = true;

        return $this->inner->listAll($page, $pageSize);
    }

    public function findById(int $id): ?array
    {
        return $this->inner->findById($id);
    }

    public function findUnknown(): array
    {
        return $this->inner->findUnknown();
    }

    public function existsByNormalizedName(string $normalizedName): bool
    {
        return $this->inner->existsByNormalizedName($normalizedName);
    }

    public function insert(string $name, array $optional = []): int
    {
        return $this->inner->insert($name, $optional);
    }

    public function update(int $id, string $name, array $optional = []): bool
    {
        return $this->inner->update($id, $name, $optional);
    }

    public function delete(int $id): bool
    {
        return $this->inner->delete($id);
    }
}
