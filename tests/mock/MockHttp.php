<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle client with queued responses and a record of sent requests
 *
 * Usage:
 *
 * ```php
 * $http = new MockHttp([MockHttp::json(200, ['ids' => ['a']])]);
 * $client = new Client('app', http_client: $http->client);
 * // ...
 * $http->requests[0]->getUri();
 * ```
 *
 * @package DealNews\Inngest\Tests\mock
 */
class MockHttp {

    /**
     * Guzzle client to pass to the code under test
     *
     * @var Client
     */
    public Client $client;

    /**
     * Queue of responses (or exceptions) to return
     *
     * @var MockHandler
     */
    public MockHandler $handler;

    /**
     * Requests that were sent, in order
     *
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * Creates the mock
     *
     * @param list<mixed> $queue Responses or Throwables to return in order
     */
    public function __construct(array $queue = []) {
        $this->handler = new MockHandler($queue);
        $stack         = HandlerStack::create($this->handler);

        $requests = &$this->requests;
        $stack->push(Middleware::mapRequest(
            static function (RequestInterface $request) use (&$requests) {
                $requests[] = $request;

                return $request;
            }
        ));

        $this->client = new Client(['handler' => $stack]);
    }

    /**
     * Builds a JSON response
     *
     * @param int                   $status  Status code
     * @param mixed                 $body    Data to JSON-encode
     * @param array<string, string> $headers Extra headers
     *
     * @return Response
     */
    public static function json(
        int $status,
        mixed $body,
        array $headers = []
    ): Response {
        return new Response(
            $status,
            array_merge(['Content-Type' => 'application/json'], $headers),
            (string)json_encode($body)
        );
    }

    /**
     * Decodes the JSON body of a sent request
     *
     * @param int $index Request index
     *
     * @return mixed
     */
    public function jsonBody(int $index = 0): mixed {
        return json_decode((string)$this->requests[$index]->getBody(), true);
    }
}
