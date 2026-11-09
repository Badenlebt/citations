<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ConfigException;
use Citations\ErrorCode;
use Citations\Http\Dispatcher;
use Citations\Http\ErrorCodeAware;
use Citations\Http\ErrorMapper;
use Citations\Http\FrontController;
use Citations\Http\JsonResponder;
use Citations\Http\Request;
use Citations\Http\Response;
use Citations\Tests\Fake\CapturingEmitter;
use Citations\Tests\Fake\RecordingHandler;
use PHPUnit\Framework\TestCase;

/**
 * The central error-mapping contract (Requirements 15.7): a known
 * ErrorCode-carrying exception maps to its code/status, while ANY unexpected
 * throwable maps to a generic INTERNAL_ERROR 500 whose serialized body leaks
 * no credential, DSN, SQL, exception message or stack-trace detail.
 */
final class ErrorMappingTest extends TestCase
{
    public function testConfigExceptionMapsToGenericInternalError(): void
    {
        $response = (new ErrorMapper())->toResponse(ConfigException::unreadableSource());

        self::assertSame(500, $response->status);
        self::assertSame('INTERNAL_ERROR', $response->body['error']);
        self::assertSame(ErrorMapper::GENERIC_INTERNAL_MESSAGE, $response->body['message']);
    }

    public function testKnownClientErrorKeepsItsCodeAndStatus(): void
    {
        $error = new class () extends \RuntimeException implements ErrorCodeAware {
            public function errorCode(): ErrorCode
            {
                return ErrorCode::NOT_FOUND;
            }
        };

        $response = (new ErrorMapper())->toResponse($error);

        self::assertSame(404, $response->status);
        self::assertSame('NOT_FOUND', $response->body['error']);
    }

    public function testArbitraryThrowableMapsToGenericInternalError(): void
    {
        $response = (new ErrorMapper())->toResponse(new \TypeError('boom at Foo::bar()'));

        self::assertSame(500, $response->status);
        self::assertSame('INTERNAL_ERROR', $response->body['error']);
        self::assertSame(ErrorMapper::GENERIC_INTERNAL_MESSAGE, $response->body['message']);
    }

    /**
     * The key 15.7 assertion: a raw throwable whose message carries a secret /
     * DSN / SQL string must not have any of that detail reach the emitted body.
     */
    public function testUnexpectedThrowableNeverLeaksSensitiveDetail(): void
    {
        $secret = 'super-secret-password-123';
        $dsn = 'mysql:host=db.internal;dbname=citations;charset=utf8mb4';
        $sql = "SELECT * FROM operators WHERE password_hash = 'leak'";
        $leakyMessage = sprintf('PDO connect failed dsn=%s user=root pass=%s query=%s', $dsn, $secret, $sql);

        $handler = new RecordingHandler(new Response(200), new \PDOException($leakyMessage));

        $emitter = new CapturingEmitter();
        $frontController = new FrontController(
            new Dispatcher([Dispatcher::SEARCH => $handler]),
            new JsonResponder($emitter),
            new ErrorMapper(),
        );

        $frontController->run(new Request('GET', 'search'));

        self::assertSame(500, $emitter->status);

        $body = (string) $emitter->body;
        self::assertStringContainsString('INTERNAL_ERROR', $body);
        self::assertStringNotContainsString($secret, $body);
        self::assertStringNotContainsString($dsn, $body);
        self::assertStringNotContainsString('password_hash', $body);
        self::assertStringNotContainsString('SELECT', $body);
        self::assertStringNotContainsString('PDO connect failed', $body);
    }

    public function testFrontControllerEmitsHandlerResponseOnSuccess(): void
    {
        $handler = new RecordingHandler(Response::created(['id' => 1]));
        $emitter = new CapturingEmitter();
        $frontController = new FrontController(
            new Dispatcher([Dispatcher::QUOTES => $handler]),
            new JsonResponder($emitter),
            new ErrorMapper(),
        );

        $frontController->run(new Request('POST', 'quotes'));

        self::assertSame(201, $emitter->status);
        self::assertSame('{"id":1}', $emitter->body);
    }
}
