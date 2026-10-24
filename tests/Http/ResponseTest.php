<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http;

use DealNews\Inngest\Http\Response;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Tests for Response
 *
 * @package DealNews\Inngest\Tests\Http
 */
#[CoversClass(Response::class)]
class ResponseTest extends TestCase {

    /**
     * Header names are lowercased and looked up case-insensitively
     *
     * @return void
     */
    public function testHeaders(): void {
        $response = new Response(201, ['Content-Type' => 'text/plain'], 'hi');

        $this->assertSame(201, $response->status);
        $this->assertSame('hi', $response->body);
        $this->assertSame(['content-type' => 'text/plain'], $response->headers);
        $this->assertSame('text/plain', $response->header('CONTENT-TYPE'));
        $this->assertNull($response->header('missing'));

        $copy = $response->withHeaders(['X-A' => 'b']);
        $this->assertSame(['x-a' => 'b'], $copy->headers);
        $this->assertSame(201, $copy->status);
        $this->assertSame('hi', $copy->body);
        $this->assertSame(['content-type' => 'text/plain'], $response->headers);
    }

    /**
     * Defaults: no headers, empty body
     *
     * @return void
     */
    public function testDefaults(): void {
        $response = new Response(200);

        $this->assertSame([], $response->headers);
        $this->assertSame('', $response->body);
    }

    /**
     * JSON responses encode like JS (unescaped slashes and unicode)
     *
     * @return void
     */
    public function testJson(): void {
        $response = Response::json(
            200,
            ['url' => 'https://example.com/a', 'name' => 'café'],
            ['X-Extra' => '1']
        );

        $this->assertSame(
            '{"url":"https://example.com/a","name":"café"}',
            $response->body
        );
        $this->assertSame(
            ['content-type' => 'application/json', 'x-extra' => '1'],
            $response->headers
        );
    }

    /**
     * Unencodable data throws
     *
     * @return void
     */
    public function testJsonFailure(): void {
        $this->expectException(\JsonException::class);

        Response::json(200, NAN);
    }

    /**
     * send() emits status and headers, then echoes the body
     *
     * @return void
     */
    public function testSend(): void {
        $response = new class(202, ['X-A' => 'b'], 'body') extends Response {
            /** @var list<string> */
            public array $emitted = [];

            protected function headersSent(): bool {
                return false;
            }

            protected function emitStatus(int $status): void {
                $this->emitted[] = (string)$status;
            }

            protected function emitHeader(string $line): void {
                $this->emitted[] = $line;
            }
        };

        $this->expectOutputString('body');
        $response->send();

        $this->assertSame(['202', 'x-a: b'], $response->emitted);
    }

    /**
     * send() only echoes the body once headers have gone out
     *
     * @return void
     */
    public function testSendAfterHeadersSent(): void {
        $response = new class(202, ['X-A' => 'b'], 'body') extends Response {
            /** @var list<string> */
            public array $emitted = [];

            protected function headersSent(): bool {
                return true;
            }

            protected function emitHeader(string $line): void {
                $this->emitted[] = $line;
            }
        };

        $this->expectOutputString('body');
        $response->send();

        $this->assertSame([], $response->emitted);
    }

    /**
     * send() uses PHP's header functions (fresh process: nothing sent yet)
     *
     * @return void
     */
    #[RunInSeparateProcess]
    public function testSendWithPhpFunctions(): void {
        $this->expectOutputString('{}');

        (new Response(201, ['X-A' => 'b'], '{}'))->send();
    }

    /**
     * Converts to PSR-7
     *
     * @return void
     */
    public function testToPsr7(): void {
        $psr = (new Response(206, ['X-A' => 'b'], '[1]'))->toPsr7();

        $this->assertSame(206, $psr->getStatusCode());
        $this->assertSame('b', $psr->getHeaderLine('x-a'));
        $this->assertSame('[1]', (string)$psr->getBody());
    }
}
