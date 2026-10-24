<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Api\ApiException;
use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Event\EventMeta;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\FixedVersion;
use DealNews\Inngest\Tests\mock\MockHttp;
use DealNews\Inngest\Tests\mock\RecordingLogger;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Client (ports the JS `send` tests from Inngest.test.ts)
 *
 * @package DealNews\Inngest\Tests
 */
#[CoversClass(Client::class)]
#[UsesClass(ApiClient::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(Env::class)]
#[UsesClass(Event::class)]
#[UsesClass(EventMeta::class)]
#[UsesClass(SendEventResult::class)]
#[UsesClass(Headers::class)]
#[UsesClass(Retry::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(Version::class)]
class ClientTest extends TestCase {

    /**
     * Event key used by most tests
     *
     * @var string
     */
    public const EVENT_KEY = 'test-event-key';

    /**
     * Getters expose the pieces built by the constructor
     *
     * @return void
     */
    public function testGetters(): void {
        $logger = new RecordingLogger();
        $client = new Client('my-app', logger: $logger, env: new Env([]));

        $this->assertSame('my-app', $client->getAppId());
        $this->assertSame('my-app', $client->getConfig()->app_id);
        $this->assertSame($logger, $client->getLogger());
        $this->assertInstanceOf(ApiClient::class, $client->getApi());
        $this->assertInstanceOf(Headers::class, $client->getHeaders());
    }

    /**
     * An empty app ID is rejected
     *
     * @return void
     */
    public function testEmptyAppId(): void {
        $this->expectException(\InvalidArgumentException::class);
        new Client('', env: new Env([]));
    }

    /**
     * Sending posts to /e/<key> and returns the IDs
     *
     * @return void
     */
    public function testSend(): void {
        $http   = $this->okHttp(['id-1']);
        $result = $this->client($http)->send(
            new Event(name: 'test/event', data: ['a' => 1])
        );

        $this->assertSame(['id-1'], $result->ids);
        $this->assertSame(
            'https://inn.gs/e/test-event-key',
            (string)$http->requests[0]->getUri()
        );
        $this->assertSame('POST', $http->requests[0]->getMethod());
        $this->assertSame(
            'dealnews/inngest-php-sdk:v1.2.3',
            $http->requests[0]->getHeaderLine('x-inngest-sdk')
        );
        $this->assertSame(
            'application/json',
            $http->requests[0]->getHeaderLine('Content-Type')
        );
    }

    /**
     * Cloud mode without an event key fails before sending
     *
     * @return void
     */
    public function testCloudModeRequiresEventKey(): void {
        $http   = new MockHttp();
        $client = $this->client($http, new ClientOptions());

        try {
            $client->send(new Event(name: 'test'));
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertSame(8101, $e->getCode());
            $this->assertStringContainsString('event key', $e->getMessage());
        }

        $this->assertCount(0, $http->requests);
    }

    /**
     * Dev mode works without an event key, using a placeholder
     *
     * @return void
     */
    public function testDevModeWithoutEventKey(): void {
        $http = $this->okHttp(['id']);
        $this->client($http, new ClientOptions(is_dev: true))
            ->send(new Event(name: 'test'));

        $this->assertSame(
            'http://localhost:8288/e/NO_EVENT_KEY_SET',
            (string)$http->requests[0]->getUri()
        );
    }

    /**
     * The event key can come from the environment
     *
     * @return void
     */
    public function testEventKeyFromEnv(): void {
        $http   = $this->okHttp(['id']);
        $client = new Client(
            'test',
            http_client: $http->client,
            env: new Env([Env::EVENT_KEY => 'env-key']),
            version: new FixedVersion(),
            retry: $this->noSleep()
        );

        $client->send(new Event(name: 'test'));

        $this->assertSame(
            'https://inn.gs/e/env-key',
            (string)$http->requests[0]->getUri()
        );
    }

    /**
     * Sending nothing warns and makes no request
     *
     * @return void
     */
    public function testEmptyList(): void {
        $http   = new MockHttp();
        $logger = new RecordingLogger();
        $client = new Client(
            'test',
            new ClientOptions(event_key: self::EVENT_KEY),
            $logger,
            $http->client,
            new Env([])
        );

        $this->assertSame([], $client->send([])->ids);
        $this->assertCount(0, $http->requests);
        $this->assertSame('warning', $logger->records[0]['level']);
    }

    /**
     * The env header follows option, env var, and send() precedence
     *
     * @param array<string, string> $env      Environment variables
     * @param string|null           $option   `env` client option
     * @param string|null           $send_env `env` passed to send()
     * @param string|null           $expected Header value
     *
     * @return void
     */
    #[DataProvider('envHeaderProvider')]
    public function testEnvHeader(
        array $env,
        ?string $option,
        ?string $send_env,
        ?string $expected
    ): void {
        $http   = $this->okHttp(['id']);
        $client = new Client(
            'test',
            new ClientOptions(event_key: self::EVENT_KEY, env: $option),
            http_client: $http->client,
            env: new Env($env),
            version: new FixedVersion(),
            retry: $this->noSleep()
        );

        $client->send(new Event(name: 'test'), $send_env);

        $this->assertSame(
            $expected ?? '',
            $http->requests[0]->getHeaderLine('x-inngest-env')
        );
    }

    /**
     * Cases for testEnvHeader (JS "should send env:foo ..." tests)
     *
     * @return array<string, array<mixed>>
     */
    public static function envHeaderProvider(): array {
        return [
            'none'                    => [[], null, null, null],
            'client option'           => [[], 'foo', null, 'foo'],
            'send() argument'         => [[], null, 'foo', 'foo'],
            'INNGEST_ENV'             => [
                [Env::ENV => 'foo'],
                null,
                null,
                'foo',
            ],
            'option beats env var'    => [
                [Env::ENV => 'bar'],
                'foo',
                null,
                'foo',
            ],
            'send() beats everything' => [
                [Env::ENV => 'bar'],
                'baz',
                'foo',
                'foo',
            ],
        ];
    }

    /**
     * ts is filled in; existing ts is kept; data defaults to {}
     *
     * @return void
     */
    public function testPayloadDefaults(): void {
        $http   = $this->okHttp(['a', 'b', 'c']);
        $before = (int)floor(microtime(true) * 1000);

        $this->client($http)->send([
            new Event(name: 'without.ts'),
            new Event(name: 'with.ts', ts: 12345),
            new Event(name: 'zero.ts', ts: 0),
        ]);

        $body  = $http->jsonBody();
        $after = (int)floor(microtime(true) * 1000);

        $this->assertGreaterThanOrEqual($before, $body[0]['ts']);
        $this->assertLessThanOrEqual($after, $body[0]['ts']);
        $this->assertSame(12345, $body[1]['ts']);
        $this->assertGreaterThanOrEqual($before, $body[2]['ts']);
        $this->assertStringContainsString(
            '"data":{}',
            (string)$http->requests[0]->getBody()
        );
        $this->assertArrayNotHasKey('id', $body[0]);
        $this->assertArrayNotHasKey('meta', $body[0]);
    }

    /**
     * The caller's Event objects aren't modified
     *
     * @return void
     */
    public function testInputNotMutated(): void {
        $event = new Event(name: 'test');

        $this->client($this->okHttp(['id']))->send($event);

        $this->assertNull($event->ts);
    }

    /**
     * Arrays are accepted: a single event or a list
     *
     * @return void
     */
    public function testArrayInput(): void {
        $http   = $this->okHttp(['a']);
        $client = $this->client($http);

        $client->send(['name' => 'single', 'data' => ['x' => 1]]);
        $this->assertSame('single', $http->jsonBody()[0]['name']);
        $this->assertSame(['x' => 1], $http->jsonBody()[0]['data']);

        $http->handler->append(MockHttp::json(200, [
            'ids'    => ['a', 'b'],
            'status' => 200,
        ]));
        $client->send([['name' => 'one'], new Event(name: 'two')]);
        $this->assertSame(
            ['one', 'two'],
            array_column($http->jsonBody(1), 'name')
        );
    }

    /**
     * Non-event list items are rejected
     *
     * @return void
     */
    public function testInvalidListItem(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(8102);
        $this->client(new MockHttp())->send(['not an event']);
    }

    /**
     * Session meta is normalized before sending
     *
     * @return void
     */
    public function testSessionsNormalized(): void {
        $http = $this->okHttp(['id']);

        $this->client($http)->send(new Event(
            name: 'test.sessions',
            meta: new EventMeta(sessions: [
                'conversation_id' => 'conversation_1234',
                'model'           => 'gpt-4.1',
                'priority'        => 1,
                'weight'          => 1.5,
                '__proto__'       => 'special',
                'cut'             => null,
            ])
        ));

        $this->assertSame(
            [
                'conversation_id' => 'conversation_1234',
                'model'           => 'gpt-4.1',
                'priority'        => '1',
                'weight'          => '1.5',
                '__proto__'       => 'special',
                'cut'             => null,
            ],
            $http->jsonBody()[0]['meta']['sessions']
        );
    }

    /**
     * Empty sessions are left out entirely
     *
     * @return void
     */
    public function testEmptySessionsOmitted(): void {
        $http = $this->okHttp(['id']);

        $this->client($http)->send(new Event(
            name: 'test',
            meta: new EventMeta(sessions: [])
        ));

        $this->assertArrayNotHasKey('meta', $http->jsonBody()[0]);
    }

    /**
     * Invalid sessions are rejected before sending
     *
     * @param array<mixed> $sessions Sessions
     * @param int          $code     Exception code
     * @param string       $message  Exception message
     *
     * @return void
     */
    #[DataProvider('invalidSessionProvider')]
    public function testInvalidSessions(
        array $sessions,
        int $code,
        string $message
    ): void {
        $http = new MockHttp();

        try {
            $this->client($http)->send(new Event(
                name: 'test',
                meta: new EventMeta(sessions: $sessions)
            ));
            $this->fail('Expected an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($code, $e->getCode());
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertCount(0, $http->requests);
    }

    /**
     * Cases for testInvalidSessions (JS messages)
     *
     * @return array<string, array{array<mixed>, int, string}>
     */
    public static function invalidSessionProvider(): array {
        return [
            'empty key'   => [
                ['' => 'x'],
                8001,
                'Event session keys cannot be empty',
            ],
            'boolean'     => [
                ['active' => true],
                8002,
                'Event session "active" must be a string, number, or null',
            ],
            'not finite'  => [
                ['conversation_id' => NAN],
                8003,
                'Event session "conversation_id" must be a finite number',
            ],
            'empty value' => [
                ['conversation_id' => ''],
                8004,
                'Event session "conversation_id" cannot have an empty ID',
            ],
        ];
    }

    /**
     * Every send carries an event ID seed: "<ms>,<base64>"
     *
     * @return void
     */
    public function testEventIdSeedHeader(): void {
        $http = $this->okHttp(['id']);

        $this->client($http)->send(new Event(name: 'test'));

        $this->assertMatchesRegularExpression(
            '/^\\d{13},[A-Za-z0-9+\\/]{14}==$/',
            $http->requests[0]->getHeaderLine(Headers::EVENT_ID_SEED)
        );
    }

    /**
     * API errors produce JS-equivalent messages after retrying
     *
     * @param int         $status   HTTP status
     * @param mixed       $body     Response body (JSON-encoded)
     * @param string      $expected Exception message
     *
     * @return void
     */
    #[DataProvider('errorProvider')]
    public function testErrorResponses(
        int $status,
        mixed $body,
        string $expected
    ): void {
        $queue = [];
        for ($i = 0; $i < Client::SEND_MAX_ATTEMPTS; $i++) {
            $queue[] = is_string($body) ?
                new Response($status, [], $body) :
                MockHttp::json($status, $body);
        }
        $http = new MockHttp($queue);

        try {
            $this->client($http)->send(new Event(name: 'test'));
            $this->fail('Expected an exception');
        } catch (ApiException $e) {
            $this->assertSame($expected, $e->getMessage());
            $this->assertSame($status, $e->getHttpStatus());
            $this->assertSame(6002, $e->getCode());
        }

        $this->assertCount(Client::SEND_MAX_ATTEMPTS, $http->requests);
    }

    /**
     * Cases for testErrorResponses
     *
     * @return array<string, array{int, mixed, string}>
     */
    public static function errorProvider(): array {
        return [
            'parsed error'        => [
                400,
                ['error' => 'Test Error'],
                'Inngest API Error: 400 Test Error',
            ],
            'parsed error on 200' => [
                200,
                ['error' => 'Test Error'],
                'Inngest API Error: 200 Test Error',
            ],
            '400 without error'   => [
                400,
                ['status' => 400],
                'Inngest API Error: 400 Cannot process event payload',
            ],
            '401'                 => [
                401,
                [],
                'Inngest API Error: 401 Event key Not Found',
            ],
            '403'                 => [
                403,
                [],
                'Inngest API Error: 403 Forbidden',
            ],
            '404'                 => [
                404,
                [],
                'Inngest API Error: 404 Event key not found',
            ],
            '406 body as JSON'    => [
                406,
                ['detail' => 'x'],
                'Inngest API Error: 406 {"detail":"x"}',
            ],
            '409'                 => [
                409,
                [],
                'Inngest API Error: 409 Event transformation failed',
            ],
            '412'                 => [
                412,
                [],
                'Inngest API Error: 412 Event transformation failed',
            ],
            '413'                 => [
                413,
                [],
                'Inngest API Error: 413 Event payload too large',
            ],
            '500'                 => [
                500,
                [],
                'Inngest API Error: 500 Internal server error',
            ],
            'unknown status'      => [
                599,
                'gateway exploded',
                'Inngest API Error: 599 gateway exploded',
            ],
            'not JSON'            => [
                502,
                '<html>bad gateway</html>',
                'Inngest API Error: 502 <html>bad gateway</html>',
            ],
            'bad ids'             => [
                200,
                ['ids' => [1, 2], 'status' => 200],
                'Inngest API Error: 200 {"ids":[1,2],"status":200}',
            ],
            '406 not JSON'        => [
                406,
                'plain text',
                'Inngest API Error: 406 plain text',
            ],
        ];
    }

    /**
     * A failure followed by success returns the success
     *
     * @return void
     */
    public function testRetryThenSuccess(): void {
        $http = new MockHttp([
            new Response(500),
            MockHttp::json(200, ['ids' => ['ok'], 'status' => 200]),
        ]);

        $result = $this->client($http)->send(new Event(name: 'test'));

        $this->assertSame(['ok'], $result->ids);
        $this->assertCount(2, $http->requests);
        $this->assertSame(
            $http->requests[0]->getHeaderLine(Headers::EVENT_ID_SEED),
            $http->requests[1]->getHeaderLine(Headers::EVENT_ID_SEED)
        );
    }

    /**
     * Transport failures are retried, then thrown as ApiException 6001
     *
     * @return void
     */
    public function testTransportFailure(): void {
        $queue = [];
        for ($i = 0; $i < Client::SEND_MAX_ATTEMPTS; $i++) {
            $queue[] = new ConnectException(
                'refused',
                new Request('POST', '/')
            );
        }
        $http = new MockHttp($queue);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(6001);
        $this->client($http)->send(new Event(name: 'test'));
    }

    /**
     * Builds a mock that accepts a send and returns the given IDs
     *
     * @param list<string> $ids IDs to return
     *
     * @return MockHttp
     */
    protected function okHttp(array $ids): MockHttp {
        return new MockHttp([
            MockHttp::json(200, ['ids' => $ids, 'status' => 200]),
        ]);
    }

    /**
     * Builds a client with an event key, mock HTTP, and no real sleeping
     *
     * @param MockHttp           $http    Mock HTTP
     * @param ClientOptions|null $options Options (defaults to an event key)
     *
     * @return Client
     */
    protected function client(
        MockHttp $http,
        ?ClientOptions $options = null
    ): Client {
        return new Client(
            'test',
            $options ?? new ClientOptions(event_key: self::EVENT_KEY),
            http_client: $http->client,
            env: new Env([]),
            version: new FixedVersion(),
            retry: $this->noSleep()
        );
    }

    /**
     * Retry helper that doesn't actually sleep
     *
     * @return Retry
     */
    protected function noSleep(): Retry {
        return new Retry(static function (float $milliseconds): void {
        });
    }
}
