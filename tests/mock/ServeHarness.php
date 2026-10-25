<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\Trigger\CronTrigger;
use DealNews\Inngest\Functions\Trigger\EventTrigger;
use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Http\Response;
use DealNews\Inngest\Http\ServeHandler;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Support\Retry;

/**
 * A client, serve handler, mocked HTTP, and logger wired together
 *
 * The client never reads real env vars, reports version `1.2.3`, never
 * sleeps between retries, and
 * (by default) has the same two functions the JS fixture generator in
 * tests/fixtures/serve uses.
 *
 * Usage:
 *
 * ```php
 * $harness  = new ServeHarness(new ClientOptions(is_dev: true));
 * $response = $harness->request('GET', ServeHarness::URL);
 * ```
 *
 * @package DealNews\Inngest\Tests\mock
 */
class ServeHarness {

    public const APP_ID = 'test-app';

    public const URL = 'https://localhost:3000/api/inngest';

    public const SIGNING_KEY = 'signkey-test-' .
        '0000000000000000000000000000000000000000000000000000000000000000';

    public const SIGNING_KEY_FALLBACK = 'signkey-test-' .
        '1111111111111111111111111111111111111111111111111111111111111111';

    public const EVENT_KEY = 'event-key-123';

    /**
     * The client
     *
     * @var Client
     */
    public Client $client;

    /**
     * Mocked outbound HTTP
     *
     * @var MockHttp
     */
    public MockHttp $http;

    /**
     * Logger given to the client
     *
     * @var RecordingLogger
     */
    public RecordingLogger $logger;

    /**
     * The handler under test
     *
     * @var ServeHandler
     */
    public ServeHandler $handler;

    /**
     * Creates the harness
     *
     * @param ClientOptions|null $options   Client options
     * @param list<mixed>        $responses Queued outbound HTTP responses
     * @param bool|\Closure       $functions Whether to add the default
     *                                       functions, or a closure that
     *                                       adds functions to the client
     * @param Signature|null     $signature Signature helper for the handler
     */
    public function __construct(
        ?ClientOptions $options = null,
        array $responses = [],
        bool|\Closure $functions = true,
        ?Signature $signature = null
    ) {
        $this->http   = new MockHttp($responses);
        $this->logger = new RecordingLogger();
        $this->client = new Client(
            self::APP_ID,
            $options,
            $this->logger,
            $this->http->client,
            new Env([]),
            new FixedVersion('1.2.3'),
            new Retry(static function (float $milliseconds): void {
            })
        );

        if ($functions instanceof \Closure) {
            $functions($this->client);
        } elseif ($functions) {
            $this->client->createFunction(
                new FunctionOptions(
                    id: 'fn1',
                    triggers: [new EventTrigger('demo/event.sent')],
                    on_failure: static fn () => null
                ),
                static fn () => null
            );
            $this->client->createFunction(
                new FunctionOptions(
                    id: 'fn2',
                    name: 'Second',
                    triggers: [new CronTrigger('0 * * * *')],
                    retries: 1
                ),
                static fn () => null
            );
        }

        $this->handler = new ServeHandler($this->client, $signature);
    }

    /**
     * Sends a request through the handler
     *
     * @param string                $method  HTTP method
     * @param string                $url     URL
     * @param array<string, string> $headers Headers
     * @param string|null           $body    Raw body
     *
     * @return Response
     */
    public function request(
        string $method,
        string $url = self::URL,
        array $headers = [],
        ?string $body = null
    ): Response {
        return $this->handler->handle(
            new Request($method, $url, $headers, $body)
        );
    }

    /**
     * Builds a valid, current `X-Inngest-Signature` value for a body
     *
     * @param string $body Raw body
     * @param string $key  Signing key
     *
     * @return string
     */
    public static function sign(
        string $body,
        string $key = self::SIGNING_KEY
    ): string {
        return (new Signature())->signatureHeader($body, $key);
    }

    /**
     * Returns the messages logged at a level
     *
     * @param string $level PSR-3 level
     *
     * @return list<string>
     */
    public function logged(string $level): array {
        $messages = [];
        foreach ($this->logger->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = (string)$record['message'];
            }
        }

        return $messages;
    }
}
