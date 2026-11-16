<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\Http\Dispatcher;
use Citations\Http\LanguageMappingHandler;
use Citations\Http\Request;
use Citations\Http\Response;
use Citations\Http\SessionGuard;
use Citations\LanguageMappingService;
use Citations\TextNormalizer;
use Citations\TokenAuthenticator;
use Citations\Tests\Fake\FixedClock;
use Citations\Tests\Fake\InMemoryLanguageRepository;
use Citations\Tests\Fake\InMemorySessionRepository;
use Citations\Tests\Fake\InMemoryStore;
use Citations\Tests\Fake\InMemoryUiLanguageMappingRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see LanguageMappingHandler} (Requirements 9.3-9.8, 9.13),
 * driven through SessionGuard -> Dispatcher -> handler over in-memory fakes.
 */
final class LanguageMappingHandlerTest extends TestCase
{
    private const TOKEN = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    private InMemoryUiLanguageMappingRepository $mappings;
    private SessionGuard $pipeline;
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2024-01-01 12:00:00');
        $store = new InMemoryStore();
        $languages = new InMemoryLanguageRepository($store);
        $this->mappings = new InMemoryUiLanguageMappingRepository($store);
        $this->a = $languages->insert('Deutsch', TextNormalizer::normalize('Deutsch'));
        $this->b = $languages->insert('English', TextNormalizer::normalize('English'));

        $store->sessions[self::TOKEN] = [
            'token' => self::TOKEN,
            'operatorId' => 1,
            'createdAt' => $now->modify('-1 hour'),
            'expiresAt' => $now->modify('+1 hour'),
        ];

        $handler = new LanguageMappingHandler(
            new LanguageMappingService($this->mappings, $languages),
            $this->mappings,
        );
        $this->pipeline = new SessionGuard(
            new Dispatcher([Dispatcher::LANGUAGE_MAPPINGS => $handler]),
            new TokenAuthenticator(new InMemorySessionRepository($store)),
            new FixedClock($now),
        );
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $path, array $body = [], ?string $token = self::TOKEN): Response
    {
        return $this->pipeline->dispatch(new Request($method, $path, [], $body, $token));
    }

    public function testGetIsPublicAndReturnsNullsWhenEmpty(): void
    {
        $response = $this->call('GET', 'language-mappings', [], null);

        self::assertSame(200, $response->status);
        self::assertSame(['de' => null, 'en' => null], $response->body);
    }

    public function testGetReturnsIdsForMappedLanguages(): void
    {
        $this->mappings->assign('de', $this->a);

        $response = $this->call('GET', 'language-mappings', [], null);

        self::assertSame(200, $response->status);
        self::assertSame(['de' => $this->a, 'en' => null], $response->body);
    }

    public function testPutAssignsAndReplacesMapping(): void
    {
        $first = $this->call('PUT', 'language-mappings/de', ['languageId' => $this->a]);
        self::assertSame(200, $first->status);
        self::assertSame(['uiLanguage' => 'de', 'languageId' => $this->a], $first->body);

        $second = $this->call('PUT', 'language-mappings/de', ['languageId' => $this->b]);
        self::assertSame(200, $second->status);
        self::assertSame(['uiLanguage' => 'de', 'languageId' => $this->b], $second->body);
        self::assertSame(['de' => $this->b], $this->mappings->findAll());
    }

    public function testPutReturns409WhenLanguageMappedToOtherUiLanguage(): void
    {
        $this->call('PUT', 'language-mappings/de', ['languageId' => $this->a]);

        $response = $this->call('PUT', 'language-mappings/en', ['languageId' => $this->a]);

        self::assertSame(ErrorCode::LANGUAGE_ALREADY_MAPPED->httpStatus(), $response->status);
        self::assertSame('LANGUAGE_ALREADY_MAPPED', $response->body['error']);
        self::assertSame(['de' => $this->a], $this->mappings->findAll());
    }

    public function testPutReturns400InvalidUiLanguage(): void
    {
        $response = $this->call('PUT', 'language-mappings/fr', ['languageId' => $this->a]);

        self::assertSame(400, $response->status);
        self::assertSame('INVALID_UI_LANGUAGE', $response->body['error']);
        self::assertSame([], $this->mappings->findAll());
    }

    public function testPutReturns400InvalidLanguageForUnknownMissingOrNonIntegerId(): void
    {
        foreach ([['languageId' => 999], [], ['languageId' => 'abc']] as $body) {
            $response = $this->call('PUT', 'language-mappings/de', $body);

            self::assertSame(400, $response->status);
            self::assertSame('INVALID_LANGUAGE', $response->body['error']);
        }
        self::assertSame([], $this->mappings->findAll());
    }

    public function testDeleteIsIdempotent(): void
    {
        $this->mappings->assign('de', $this->a);

        $first = $this->call('DELETE', 'language-mappings/de');
        $second = $this->call('DELETE', 'language-mappings/de');

        foreach ([$first, $second] as $response) {
            self::assertSame(200, $response->status);
            self::assertSame(['uiLanguage' => 'de', 'languageId' => null], $response->body);
        }
        self::assertSame([], $this->mappings->findAll());
    }

    public function testDeleteReturns400InvalidUiLanguage(): void
    {
        $response = $this->call('DELETE', 'language-mappings/xx');

        self::assertSame(400, $response->status);
        self::assertSame('INVALID_UI_LANGUAGE', $response->body['error']);
    }

    public function testWritesWithoutSessionReturn401AndLeaveStateUnchanged(): void
    {
        $this->mappings->assign('de', $this->a);

        foreach ([
            ['PUT', 'language-mappings/en', ['languageId' => $this->b]],
            ['DELETE', 'language-mappings/de', []],
        ] as [$method, $path, $body]) {
            $response = $this->call($method, $path, $body, null);

            self::assertSame(401, $response->status);
            self::assertSame('UNAUTHORIZED', $response->body['error']);
        }
        self::assertSame(['de' => $this->a], $this->mappings->findAll());
    }
}
