<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Api;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Api\ApiException;
use DealNews\Inngest\Api\SendSignalResult;
use DealNews\Inngest\Api\StaleDispatchError;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\FixedVersion;
use DealNews\Inngest\Tests\mock\MockHttp;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for ApiClient (ports JS `fetchWithAuthFallback` tests)
 *
 * @package DealNews\Inngest\Tests\Api
 */
#[CoversClass(ApiClient::class)]
#[CoversClass(ApiException::class)]
#[CoversClass(StaleDispatchError::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(Env::class)]
#[UsesClass(Headers::class)]
#[UsesClass(MiddlewareStack::class)]
#[UsesClass(SendSignalResult::class)]
#[UsesClass(Signature::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(Version::class)]
class ApiClientTest extends TestCase {

    /**
     * Requests carry SDK headers and the hashed signing key
     *
     * @return void
     */
    public function testRequestAuthAndHeaders(): void {
        $http = new MockHttp([MockHttp::json(200, ['ok' => true])]);
        $api  = $this->api($http, new ClientOptions(
            signing_key: 'signkey-test-12345',
            env: 'branch'
        ));

        $response = $api->request(
            'POST',
            '/fn/register',
            '{"a":1}',
            ['x-extra' => 'yes']
        );

        $request = $http->requests[0];
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            'https://api.inngest.com/fn/register',
            (string)$request->getUri()
        );
        $this->assertSame(
            'Bearer ' . Signature::hashSigningKey('signkey-test-12345'),
            $request->getHeaderLine('Authorization')
        );
        $this->assertSame(
            'dealnews/inngest-php-sdk:v1.2.3',
            $request->getHeaderLine('x-inngest-sdk')
        );
        $this->assertSame('branch', $request->getHeaderLine('x-inngest-env'));
        $this->assertSame('yes', $request->getHeaderLine('x-extra'));
        $this->assertSame('{"a":1}', (string)$request->getBody());
    }

    /**
     * Without a signing key, no Authorization header is sent
     *
     * @return void
     */
    public function testNoKeyNoAuthHeader(): void {
        $http = new MockHttp([new Response(200)]);

        $this->api($http, new ClientOptions(is_dev: true))
            ->request('GET', '/v0/x');

        $this->assertFalse($http->requests[0]->hasHeader('Authorization'));
        $this->assertSame(
            'http://localhost:8288/v0/x',
            (string)$http->requests[0]->getUri()
        );
    }

    /**
     * 401 and 403 retry once with the fallback key
     *
     * @return void
     */
    public function testFallbackOnAuthFailure(): void {
        foreach ([401, 403] as $status) {
            $http = new MockHttp([
                new Response($status),
                MockHttp::json(200, ['data' => '12345']),
            ]);

            $response = $this->api($http, new ClientOptions(
                signing_key: 'signkey-test-aa',
                signing_key_fallback: 'signkey-test-bb'
            ))->request('GET', '/v0/x');

            $this->assertSame(200, $response->getStatusCode());
            $this->assertCount(2, $http->requests);
            $this->assertSame(
                'Bearer ' . Signature::hashSigningKey('signkey-test-aa'),
                $http->requests[0]->getHeaderLine('Authorization')
            );
            $this->assertSame(
                'Bearer ' . Signature::hashSigningKey('signkey-test-bb'),
                $http->requests[1]->getHeaderLine('Authorization')
            );
        }
    }

    /**
     * Other errors don't trigger the fallback
     *
     * @return void
     */
    public function testNoFallbackOnOtherErrors(): void {
        $http     = new MockHttp([new Response(500)]);
        $response = $this->api($http, new ClientOptions(
            signing_key: 'signkey-test-aa',
            signing_key_fallback: 'signkey-test-bb'
        ))->request('GET', '/v0/x');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertCount(1, $http->requests);
    }

    /**
     * Without a fallback key, a 401 is returned as-is
     *
     * @return void
     */
    public function testNoFallbackKey(): void {
        $http     = new MockHttp([new Response(401)]);
        $response = $this->api($http, new ClientOptions(
            signing_key: 'signkey-test-aa'
        ))->request('GET', '/v0/x');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertCount(1, $http->requests);
    }

    /**
     * Transport failures become ApiException 6001
     *
     * @return void
     */
    public function testTransportFailure(): void {
        $http = new MockHttp([
            new ConnectException('refused', new Request('GET', '/')),
        ]);

        try {
            $this->api($http)->request('GET', '/v0/x');
            $this->fail('Expected an exception');
        } catch (ApiException $e) {
            $this->assertSame(6001, $e->getCode());
            $this->assertNull($e->getHttpStatus());
            $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
    }

    /**
     * Run steps and batch come back decoded from the right endpoints
     *
     * @return void
     */
    public function testGetRunStepsAndBatch(): void {
        $http = new MockHttp([
            MockHttp::json(200, ['abc' => ['type' => 'data', 'data' => 1]]),
            MockHttp::json(200, [['name' => 'evt', 'data' => []]]),
        ]);
        $api = $this->api($http);

        $this->assertSame(
            ['abc' => ['type' => 'data', 'data' => 1]],
            $api->getRunSteps('01H/RUN')
        );
        $this->assertSame(
            [['name' => 'evt', 'data' => []]],
            $api->getRunBatch('01H/RUN')
        );
        $this->assertSame(
            'https://api.inngest.com/v0/runs/01H%2FRUN/actions',
            (string)$http->requests[0]->getUri()
        );
        $this->assertSame(
            'https://api.inngest.com/v0/runs/01H%2FRUN/batch',
            (string)$http->requests[1]->getUri()
        );
    }

    /**
     * Error responses use the API's error message when there is one
     *
     * @return void
     */
    public function testGetRunStepsErrorMessage(): void {
        $http = new MockHttp([
            MockHttp::json(404, ['error' => 'run not found', 'status' => 404]),
        ]);

        try {
            $this->api($http)->getRunSteps('run');
            $this->fail('Expected an exception');
        } catch (ApiException $e) {
            $this->assertSame('run not found', $e->getMessage());
            $this->assertSame(6002, $e->getCode());
            $this->assertSame(404, $e->getHttpStatus());
        }
    }

    /**
     * Error responses without a message use the fallback text
     *
     * @return void
     */
    public function testGetRunBatchErrorFallback(): void {
        $http = new MockHttp([new Response(500, [], 'oops')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unknown error retrieving event batch');
        $this->api($http)->getRunBatch('run');
    }

    /**
     * Non-JSON success bodies are an error
     *
     * @return void
     */
    public function testInvalidJson(): void {
        $http = new MockHttp([new Response(200, [], 'not json')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(6003);
        $this->api($http)->getRunSteps('run');
    }

    /**
     * Signals POST `{signal, data}` and return the run ID
     *
     * @return void
     */
    public function testSendSignal(): void {
        $http = new MockHttp([
            MockHttp::json(200, ['data' => ['run_id' => 'run-2']]),
        ]);

        $result = $this->api($http, new ClientOptions(env: 'main'))
            ->sendSignal('sig', ['ok' => true], 'branch');

        $request = $http->requests[0];
        $this->assertSame('run-2', $result->run_id);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            'https://api.inngest.com/v1/signals',
            (string)$request->getUri()
        );
        $this->assertSame(
            '{"signal":"sig","data":{"ok":true}}',
            (string)$request->getBody()
        );
        $this->assertSame(
            ['branch'],
            $request->getHeader('x-inngest-env')
        );
    }

    /**
     * Without data, only the signal is sent; the client env is used
     *
     * @return void
     */
    public function testSendSignalNoData(): void {
        $http = new MockHttp([
            MockHttp::json(200, ['data' => ['run_id' => 'run-2']]),
        ]);

        $this->api($http, new ClientOptions(env: 'main'))->sendSignal('sig');

        $this->assertSame(
            '{"signal":"sig"}',
            (string)$http->requests[0]->getBody()
        );
        $this->assertSame(
            'main',
            $http->requests[0]->getHeaderLine('x-inngest-env')
        );
    }

    /**
     * A 404 means no run was waiting
     *
     * @return void
     */
    public function testSendSignalNotFound(): void {
        $http = new MockHttp([new Response(404, [], 'not json')]);

        $this->assertNull($this->api($http)->sendSignal('sig')->run_id);
    }

    /**
     * Failures throw with JS's messages
     *
     * @param Response $response Response
     * @param string   $message  Expected message
     *
     * @return void
     */
    #[DataProvider('signalErrorProvider')]
    public function testSendSignalErrors(
        Response $response,
        string $message
    ): void {
        $http = new MockHttp([$response]);

        try {
            $this->api($http)->sendSignal('sig');
            $this->fail('Expected an exception');
        } catch (ApiException $e) {
            $this->assertSame(6004, $e->getCode());
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(
                $response->getStatusCode(),
                $e->getHttpStatus()
            );
        }
    }

    /**
     * Error responses
     *
     * @return array<string, array{Response, string}>
     */
    public static function signalErrorProvider(): array {
        return [
            'error body' => [
                MockHttp::json(400, ['error' => 'bad signal', 'status' => 400]),
                'Failed to send signal: bad signal',
            ],
            'not json' => [
                new Response(500, [], 'oops'),
                'Failed to send signal: 500 Internal Server Error - oops',
            ],
            'json without error' => [
                MockHttp::json(502, ['nope' => 1]),
                'Failed to send signal: 502 Bad Gateway - {"nope":1}',
            ],
            'success without run ID' => [
                MockHttp::json(200, ['data' => []]),
                'Failed to send signal: Successfully sent signal, but ' .
                'response parsing failed: 200 OK - {"data":[]}',
            ],
            'success with empty run ID' => [
                MockHttp::json(200, ['data' => ['run_id' => '']]),
                'Failed to send signal: Successfully sent signal, but ' .
                'response parsing failed: 200 OK - {"data":{"run_id":""}}',
            ],
        ];
    }

    /**
     * Checkpoints POST the payload; 409 is stale, other errors fail
     *
     * @return void
     */
    public function testCheckpointAsync(): void {
        $http = new MockHttp([
            MockHttp::json(200, new \stdClass()),
            MockHttp::json(409, new \stdClass()),
            new Response(502, [], 'bad gateway'),
        ]);
        $api  = $this->api($http);

        $api->checkpointAsync('run/1', ['a' => 'b/c']);
        $this->assertSame(
            'https://api.inngest.com/v1/checkpoint/run%2F1/async',
            (string)$http->requests[0]->getUri()
        );
        $this->assertSame(
            '{"a":"b/c"}',
            (string)$http->requests[0]->getBody()
        );

        try {
            $api->checkpointAsync('run-1', []);
            $this->fail('Expected a stale dispatch');
        } catch (StaleDispatchError $e) {
            $this->assertSame(6006, $e->getCode());
            $this->assertSame(409, $e->getHttpStatus());
            $this->assertSame(
                'Stale dispatch: checkpoint returned 409 (run run-1)',
                $e->getMessage()
            );
        }

        try {
            $api->checkpointAsync('run-1', []);
            $this->fail('Expected a failure');
        } catch (ApiException $e) {
            $this->assertSame(6005, $e->getCode());
            $this->assertSame(
                'Failed to checkpoint async: 502 Bad Gateway - bad gateway',
                $e->getMessage()
            );
        }
    }

    /**
     * Builds an ApiClient around the mock HTTP client
     *
     * @param MockHttp           $http    Mock HTTP
     * @param ClientOptions|null $options Options
     *
     * @return ApiClient
     */
    protected function api(
        MockHttp $http,
        ?ClientOptions $options = null
    ): ApiClient {
        return new ApiClient(
            ClientConfig::resolve('test', $options, new Env([])),
            $http->client,
            new Headers(new FixedVersion())
        );
    }
}
