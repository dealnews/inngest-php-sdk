<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http;

use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Tests\TestCase;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for Request
 *
 * @package DealNews\Inngest\Tests\Http
 */
#[CoversClass(Request::class)]
class RequestTest extends TestCase {

    /**
     * The method is uppercased and header names lowercased
     *
     * @return void
     */
    public function testNormalizes(): void {
        $request = new Request(
            'post',
            'https://example.com/api',
            [
                'X-Inngest-Signature' => 't=1&s=2',
                'Accept'              => ['a', 'b'],
            ],
            '{}'
        );

        $this->assertSame('POST', $request->method);
        $this->assertSame('https://example.com/api', $request->url);
        $this->assertSame('{}', $request->body);
        $this->assertSame(
            ['x-inngest-signature' => 't=1&s=2', 'accept' => 'a, b'],
            $request->headers
        );
        $this->assertSame('t=1&s=2', $request->header('X-INNGEST-SIGNATURE'));
        $this->assertNull($request->header('missing'));
    }

    /**
     * Body defaults to null (no body)
     *
     * @return void
     */
    public function testNoBody(): void {
        $this->assertNull((new Request('GET', 'https://example.com'))->body);
    }

    /**
     * Query parameters are read like `URLSearchParams.get()`
     *
     * @param string      $url      URL
     * @param string      $name     Parameter
     * @param string|null $expected Value
     *
     * @return void
     */
    #[DataProvider('queries')]
    public function testQuery(
        string $url,
        string $name,
        ?string $expected
    ): void {
        $this->assertSame(
            $expected,
            (new Request('GET', $url))->query($name)
        );
    }

    /**
     * URLs, parameters, and values
     *
     * @return array<string, array{string, string, string|null}>
     */
    public static function queries(): array {
        $base  = 'https://example.com/api';
        $steps = $base . '?fnId=abc&stepId=step';

        return [
            'present'     => [$steps, 'fnId', 'abc'],
            'second'      => [$steps, 'stepId', 'step'],
            'first wins'  => [$base . '?a=1&a=2', 'a', '1'],
            'decoded'     => [$base . '?fnId=a%20b+c', 'fnId', 'a b c'],
            'no value'    => [$base . '?probe', 'probe', ''],
            'empty value' => [$base . '?probe=', 'probe', ''],
            'missing'     => [$base . '?fnId=abc', 'probe', null],
            'no query'    => [$base, 'fnId', null],
            'fragment'    => [$base . '?a=1#b=2', 'b', null],
        ];
    }

    /**
     * Requests are rebuilt from server vars
     *
     * @param array<string, mixed> $server Server vars
     * @param string               $url    Expected URL
     *
     * @return void
     */
    #[DataProvider('servers')]
    public function testFromGlobalsUrl(array $server, string $url): void {
        $this->assertSame(
            $url,
            Request::fromGlobals($server, '')->url
        );
    }

    /**
     * Server vars and the URL they produce
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function servers(): array {
        $uri = '/api/inngest?fnId=a';

        return [
            'http host' => [
                ['HTTP_HOST' => 'example.com', 'REQUEST_URI' => $uri],
                'http://example.com' . $uri,
            ],
            'https on' => [
                [
                    'HTTPS'       => 'on',
                    'HTTP_HOST'   => 'example.com:8443',
                    'REQUEST_URI' => $uri,
                ],
                'https://example.com:8443' . $uri,
            ],
            'https off' => [
                [
                    'HTTPS'       => 'off',
                    'HTTP_HOST'   => 'example.com',
                    'REQUEST_URI' => $uri,
                ],
                'http://example.com' . $uri,
            ],
            'forwarded proto' => [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'HTTPS, http',
                    'HTTP_HOST'              => 'example.com',
                    'REQUEST_URI'            => $uri,
                ],
                'https://example.com' . $uri,
            ],
            'server name and port' => [
                [
                    'SERVER_NAME' => 'internal',
                    'SERVER_PORT' => '8080',
                    'REQUEST_URI' => $uri,
                ],
                'http://internal:8080' . $uri,
            ],
            'default http port' => [
                ['SERVER_NAME' => 'internal', 'SERVER_PORT' => '80'],
                'http://internal/',
            ],
            'default https port' => [
                [
                    'HTTPS'       => '1',
                    'SERVER_NAME' => 'internal',
                    'SERVER_PORT' => '443',
                ],
                'https://internal/',
            ],
            'nothing' => [
                [],
                'http://localhost/',
            ],
            'uri without slash' => [
                ['HTTP_HOST' => 'example.com', 'REQUEST_URI' => 'api'],
                'http://example.com/api',
            ],
        ];
    }

    /**
     * Method, headers, and body come from server vars
     *
     * @return void
     */
    public function testFromGlobals(): void {
        $request = Request::fromGlobals(
            [
                'REQUEST_METHOD'           => 'put',
                'HTTP_HOST'                => 'example.com',
                'HTTP_X_INNGEST_SYNC_KIND' => 'in_band',
                'CONTENT_TYPE'             => 'application/json',
                'CONTENT_LENGTH'           => 2,
                'SCRIPT_NAME'              => '/index.php',
                'argv'                     => ['ignored'],
            ],
            '{}'
        );

        $this->assertSame('PUT', $request->method);
        $this->assertSame('{}', $request->body);
        $this->assertSame(
            [
                'host'                => 'example.com',
                'x-inngest-sync-kind' => 'in_band',
                'content-type'        => 'application/json',
                'content-length'      => '2',
            ],
            $request->headers
        );
    }

    /**
     * Without arguments, `$_SERVER` and `php://input` are used
     *
     * @return void
     */
    public function testFromGlobalsDefaults(): void {
        $request = Request::fromGlobals();

        $this->assertSame('GET', $request->method);
        $this->assertSame('', $request->body);
    }

    /**
     * PSR-7 requests convert, keeping the raw body
     *
     * @return void
     */
    public function testFromPsr7(): void {
        $request = Request::fromPsr7(new ServerRequest(
            'POST',
            'https://example.com/api?fnId=a',
            ['X-Inngest-Signature' => 'sig'],
            '{"a": 1}'
        ));

        $this->assertSame('POST', $request->method);
        $this->assertSame('https://example.com/api?fnId=a', $request->url);
        $this->assertSame('sig', $request->header('x-inngest-signature'));
        $this->assertSame('{"a": 1}', $request->body);
    }
}
