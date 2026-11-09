<?php

declare(strict_types=1);

namespace Citations\Tests\Unit;

use Citations\ErrorCode;
use Citations\Http\JsonResponder;
use Citations\Http\Response;
use Citations\Tests\Fake\CapturingEmitter;
use PHPUnit\Framework\TestCase;

/**
 * Example tests for {@see JsonResponder}: a response serializes to the expected
 * JSON body + status with an application/json content type, and an ErrorCode
 * error produces the `{ error, message }` body at the mapped HTTP status
 * (design "Antworten sind JSON", Requirements 15.7).
 */
final class JsonResponderTest extends TestCase
{
    private CapturingEmitter $emitter;
    private JsonResponder $responder;

    protected function setUp(): void
    {
        $this->emitter = new CapturingEmitter();
        $this->responder = new JsonResponder($this->emitter);
    }

    public function testSerializesSuccessBodyAndStatus(): void
    {
        $this->responder->send(Response::ok(['id' => 7, 'text' => 'Zitat'], 201));

        self::assertSame(201, $this->emitter->status);
        self::assertSame('{"id":7,"text":"Zitat"}', $this->emitter->body);
    }

    public function testSetsApplicationJsonContentType(): void
    {
        $this->responder->send(Response::ok(['ok' => true]));

        self::assertContains('Content-Type: application/json; charset=utf-8', $this->emitter->headers);
    }

    public function testErrorResponseProducesUniformBodyAtMappedStatus(): void
    {
        $this->responder->send(Response::error(ErrorCode::NOT_FOUND, 'Resource not found.'));

        self::assertSame(404, $this->emitter->status);

        $decoded = json_decode((string) $this->emitter->body, true);
        self::assertSame(['error', 'message'], array_keys($decoded));
        self::assertSame('NOT_FOUND', $decoded['error']);
        self::assertSame('Resource not found.', $decoded['message']);
    }

    public function testNullBodySerializesToEmptyObject(): void
    {
        $this->responder->send(new Response(204, null));

        self::assertSame(204, $this->emitter->status);
        self::assertSame('{}', $this->emitter->body);
    }

    public function testUnicodeIsNotEscaped(): void
    {
        $this->responder->send(Response::ok(['name' => 'Ähnlich']));

        self::assertSame('{"name":"Ähnlich"}', $this->emitter->body);
    }
}
