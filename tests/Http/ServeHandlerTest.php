<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Error\SerializedError;
use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Execution\CheckpointConfig;
use DealNews\Inngest\Execution\Checkpointer;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\ExecutionException;
use DealNews\Inngest\Execution\ExecutionResult;
use DealNews\Inngest\Execution\FoundStep;
use DealNews\Inngest\Execution\OutgoingOp;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepIdResolver;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\Config\FunctionConfig;
use DealNews\Inngest\Functions\Config\StepConfig;
use DealNews\Inngest\Functions\Config\StepRetries;
use DealNews\Inngest\Functions\Config\StepRuntime;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Functions\Trigger\CronTrigger;
use DealNews\Inngest\Functions\Trigger\EventTrigger;
use DealNews\Inngest\Http\Capabilities;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Http\Introspection\AuthenticatedIntrospection;
use DealNews\Inngest\Http\Introspection\IntrospectionExtra;
use DealNews\Inngest\Http\Introspection\UnauthenticatedIntrospection;
use DealNews\Inngest\Http\Introspector;
use DealNews\Inngest\Http\Registrar;
use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Http\Response;
use DealNews\Inngest\Http\ServeException;
use DealNews\Inngest\Http\ServeHandler;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Http\SignatureException;
use DealNews\Inngest\Http\SignatureResult;
use DealNews\Inngest\Http\Sync\InBandRegisterRequest;
use DealNews\Inngest\Http\Sync\RegisterRequest;
use DealNews\Inngest\Http\Sync\RegisterResult;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\ExpiredOkSignature;
use DealNews\Inngest\Tests\mock\MockHttp;
use DealNews\Inngest\Tests\mock\ServeHarness;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for ServeHandler
 *
 * Ports the shared serve handler suite (JS `testFramework` in
 * src/test/helpers.ts) and the error-response and introspection tests in
 * InngestCommHandler.test.ts. Byte-level parity with JS output lives in
 * ServeHandlerParityTest.
 *
 * @package DealNews\Inngest\Tests\Http
 */
#[CoversClass(ServeHandler::class)]
#[UsesClass(AiTools::class)]
#[UsesClass(ApiClient::class)]
#[UsesClass(Context::class)]
#[UsesClass(CheckpointConfig::class)]
#[UsesClass(Checkpointer::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Event::class)]
#[UsesClass(ExecutionException::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(FoundStep::class)]
#[UsesClass(OutgoingOp::class)]
#[UsesClass(RemoteError::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(StepError::class)]
#[UsesClass(StepIdResolver::class)]
#[UsesClass(StepOp::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(AuthenticatedIntrospection::class)]
#[UsesClass(Capabilities::class)]
#[UsesClass(Client::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(CronTrigger::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Env::class)]
#[UsesClass(ErrorSerializer::class)]
#[UsesClass(EventTrigger::class)]
#[UsesClass(FunctionConfig::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(Headers::class)]
#[UsesClass(InBandRegisterRequest::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(Introspector::class)]
#[UsesClass(IntrospectionExtra::class)]
#[UsesClass(RegisterRequest::class)]
#[UsesClass(RegisterResult::class)]
#[UsesClass(Registrar::class)]
#[UsesClass(Request::class)]
#[UsesClass(Response::class)]
#[UsesClass(Retry::class)]
#[UsesClass(SerializedError::class)]
#[UsesClass(ServeException::class)]
#[UsesClass(Signature::class)]
#[UsesClass(SignatureException::class)]
#[UsesClass(SignatureResult::class)]
#[UsesClass(StepConfig::class)]
#[UsesClass(StepRetries::class)]
#[UsesClass(StepRuntime::class)]
#[UsesClass(UnauthenticatedIntrospection::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(Version::class)]
class ServeHandlerTest extends TestCase {

    /**
     * Signing key from the JS signature validation tests
     *
     * @var string
     */
    public const JS_KEY = 'signkey-test-' .
        'f00f3005a3666b359a79c2bc3380ce2715e62727ac461ae1a2618f8766029c9f';

    /**
     * Body signed by JS_SIGNATURE (JSON.stringify of the JS test body)
     *
     * @var string
     */
    public const JS_BODY = '{"ctx":{"fn_id":"local-testing-local-cron",' .
        '"run_id":"01GQ3HTEZ01M7R8Z9PR1DMHDN1","step_id":"step"},' .
        '"event":{"data":{},"id":"","name":"demo/event.sent",' .
        '"ts":1674082830001,"v":"1"},"events":[{"data":{},"id":"",' .
        '"name":"demo/event.sent","ts":1674082830001,"v":"1"}],' .
        '"steps":{},"use_api":false}';

    /**
     * Signature captured from a real Inngest request, signed with JS_KEY
     *
     * @var string
     */
    public const JS_SIGNATURE = 't=1687306735&s=' .
        'a060f245a5d0c3c779ef65fd15b2ac06e4ab98dd8cc9860aa0722c8481ae02ab';

    /**
     * Options for a cloud-mode client with a signing key
     *
     * @param string|null $env Inngest environment
     *
     * @return ClientOptions
     */
    protected static function cloud(?string $env = null): ClientOptions {
        return new ClientOptions(
            signing_key: ServeHarness::SIGNING_KEY,
            env: $env,
            is_dev: false
        );
    }

    /**
     * Options for a dev-mode client
     *
     * @return ClientOptions
     */
    protected static function dev(): ClientOptions {
        return new ClientOptions(is_dev: true);
    }

    /**
     * A successful register response
     *
     * @return \GuzzleHttp\Psr7\Response
     */
    protected static function registered(): \GuzzleHttp\Psr7\Response {
        return MockHttp::json(200, ['status' => 200]);
    }

    /**
     * Decodes a response body
     *
     * @param Response $response Response
     *
     * @return mixed
     */
    protected static function body(Response $response): mixed {
        return json_decode($response->body, true);
    }

    /**
     * Cloud mode without a signing key fails at request time, not before
     *
     * @return void
     */
    public function testCloudModeWithoutSigningKeyReturns500(): void {
        $harness  = new ServeHarness(new ClientOptions(is_dev: false));
        $response = $harness->request('GET');

        $this->assertSame(500, $response->status);
        $this->assertSame(
            ['code' => 'internal_server_error'],
            static::body($response)
        );
        $this->assertStringContainsString(
            'In cloud mode but no signing key found',
            $harness->logged('error')[0]
        );
    }

    /**
     * GET in dev mode shows introspection data with the SDK headers
     *
     * @return void
     */
    public function testGetShowsIntrospection(): void {
        $harness  = new ServeHarness(static::dev(), [], false);
        $response = $harness->request(
            'GET',
            ServeHarness::URL,
            [Headers::SERVER_KIND => 'dev']
        );

        $this->assertSame(200, $response->status);
        $this->assertSame(
            'dealnews/inngest-php-sdk:v1.2.3',
            $response->header(Headers::SDK_VERSION)
        );
        $this->assertSame(
            'dev',
            $response->header(Headers::EXPECTED_SERVER_KIND)
        );
        $this->assertSame('2', $response->header(Headers::REQUEST_VERSION));
        $this->assertNull($response->header(Headers::SIGNATURE));
        $this->assertSame(
            [
                'extra'           => ['native_crypto' => true],
                'has_event_key'   => false,
                'has_signing_key' => false,
                'function_count'  => 0,
                'mode'            => 'dev',
                'schema_version'  => '2024-05-24',
            ],
            static::body($response)
        );
    }

    /**
     * Signed GET in cloud mode gets the authenticated body, signed
     *
     * @return void
     */
    public function testAuthenticatedGetIsSigned(): void {
        $harness  = new ServeHarness(static::cloud('v10'));
        $response = $harness->request(
            'GET',
            ServeHarness::URL,
            [Headers::SIGNATURE => ServeHarness::sign('')]
        );

        $this->assertSame(200, $response->status);
        $body = static::body($response);
        $this->assertTrue($body['authentication_succeeded']);
        $this->assertSame('v10', $body['env']);
        $this->assertSame('v10', $response->header(Headers::ENVIRONMENT));

        $this->assertSame(
            ServeHarness::SIGNING_KEY,
            (new Signature())->verify(
                (string)$response->header(Headers::SIGNATURE),
                $response->body,
                ServeHarness::SIGNING_KEY
            )
        );
    }

    /**
     * A response to a request signed with the fallback key is signed with
     * the fallback key
     *
     * @return void
     */
    public function testResponseSignedWithKeyThatVerified(): void {
        $harness = new ServeHarness(new ClientOptions(
            signing_key: ServeHarness::SIGNING_KEY,
            signing_key_fallback: ServeHarness::SIGNING_KEY_FALLBACK,
            is_dev: false
        ));
        $response = $harness->request(
            'GET',
            ServeHarness::URL,
            [
                Headers::SIGNATURE => ServeHarness::sign(
                    '',
                    ServeHarness::SIGNING_KEY_FALLBACK
                ),
            ]
        );

        $this->assertSame(200, $response->status);
        $this->assertSame(
            ServeHarness::SIGNING_KEY_FALLBACK,
            (new Signature())->verify(
                (string)$response->header(Headers::SIGNATURE),
                $response->body,
                ServeHarness::SIGNING_KEY_FALLBACK
            )
        );
    }

    /**
     * GET signatures cover an empty body, whatever the request carried
     *
     * @return void
     */
    public function testGetSignatureIgnoresBody(): void {
        $harness  = new ServeHarness(static::cloud());
        $response = $harness->request(
            'GET',
            ServeHarness::URL,
            [Headers::SIGNATURE => ServeHarness::sign('')],
            '{"ignored":true}'
        );

        $this->assertSame(200, $response->status);
    }

    /**
     * Cloud-mode GET without a valid signature is a 401 without SDK
     * identification headers
     *
     * @param array<string, string> $headers Request headers
     *
     * @return void
     */
    #[DataProvider('badSignatureHeaders')]
    public function testUnsignedCloudGetIsUnauthorized(array $headers): void {
        $harness  = new ServeHarness(static::cloud('my-env'));
        $response = $harness->request('GET', ServeHarness::URL, $headers);

        $this->assertSame(401, $response->status);
        $this->assertSame(
            ['message' => 'Unauthorized'],
            static::body($response)
        );
        $this->assertSame(
            [
                'content-type'          => 'application/json',
                'x-inngest-sdk-handled' => 'true',
                'traceparent'           => '00-trace',
            ],
            $response->headers
        );
        $this->assertSame(
            ['Signature validation failed'],
            $harness->logged('error')
        );
    }

    /**
     * Missing, malformed, and wrong signatures
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function badSignatureHeaders(): array {
        $wrong_key = 'signkey-test-wrong-' . str_repeat('2', 64);

        return [
            'missing' => [
                [Headers::TRACE_PARENT => '00-trace'],
            ],
            'empty' => [
                [Headers::SIGNATURE => '', Headers::TRACE_PARENT => '00-trace'],
            ],
            'wrong key' => [
                [
                    Headers::SIGNATURE    => ServeHarness::sign('', $wrong_key),
                    Headers::TRACE_PARENT => '00-trace',
                ],
            ],
            'zero hmac' => [
                [
                    Headers::SIGNATURE    => 't=' . time() . '&s=' .
                                             str_repeat('0', 64),
                    Headers::TRACE_PARENT => '00-trace',
                ],
            ],
        ];
    }

    /**
     * Trace context headers are copied onto responses
     *
     * @return void
     */
    public function testForwardsTraceHeaders(): void {
        $harness  = new ServeHarness(static::dev());
        $response = $harness->request('GET', ServeHarness::URL, [
            'TraceParent' => '00-parent',
            'tracestate'  => 'a=b',
        ]);

        $this->assertSame('00-parent', $response->header('traceparent'));
        $this->assertSame('a=b', $response->header('tracestate'));
    }

    /**
     * Out-of-band sync registers the URL the request came in on
     *
     * @param string $url      Request URL
     * @param string $expected URL sent to Inngest
     * @param string|null $origin Serve origin
     * @param string|null $path   Serve path
     *
     * @return void
     */
    #[DataProvider('registerUrls')]
    public function testRegistersUrl(
        string $url,
        string $expected,
        ?string $origin = null,
        ?string $path = null
    ): void {
        $harness = new ServeHarness(
            new ClientOptions(
                signing_key: ServeHarness::SIGNING_KEY,
                is_dev: false,
                serve_origin: $origin,
                serve_path: $path
            ),
            [static::registered()]
        );
        $response = $harness->request(
            'PUT',
            $url,
            [
                Headers::SIGNATURE   => ServeHarness::sign(''),
                Headers::SERVER_KIND => 'dev',
            ]
        );

        $this->assertSame(200, $response->status);
        $this->assertSame(
            ['message' => 'Successfully registered', 'modified' => false],
            static::body($response)
        );
        $this->assertSame(
            'out_of_band',
            $response->header(Headers::SYNC_KIND)
        );
        $this->assertSame(
            'dev',
            $response->header(Headers::EXPECTED_SERVER_KIND)
        );

        $sent = $harness->http->jsonBody();
        $this->assertSame($expected, $sent['url']);
        $this->assertSame(
            $expected . '?fnId=test-app-fn1&stepId=step',
            $sent['functions'][0]['steps']['step']['runtime']['url']
        );
        $this->assertSame(
            'https://api.inngest.com/fn/register',
            (string)$harness->http->requests[0]->getUri()
        );
    }

    /**
     * Request URLs, serve origin/path overrides, and the expected result
     *
     * @return array<string, array<int, string|null>>
     */
    public static function registerUrls(): array {
        $path = '/foo/bar/inngest/endpoint';

        return [
            'default URL from request' => [
                'https://localhost:3000/api/inngest',
                'https://localhost:3000/api/inngest',
            ],
            'custom URL from request' => [
                'https://localhost:3000' . $path,
                'https://localhost:3000' . $path,
            ],
            'overwritten origin' => [
                'https://localhost:3000/api/inngest',
                'https://example.com/api/inngest',
                'https://example.com',
            ],
            'overwritten path' => [
                'https://localhost:3000/api/inngest',
                'https://localhost:3000' . $path,
                null,
                $path,
            ],
            'overwritten origin and path' => [
                'https://localhost:3000/api/inngest',
                'https://example.com' . $path,
                'https://example.com',
                $path,
            ],
            'origin with its own path' => [
                'https://localhost:3000/api/inngest',
                'https://example.com/api/inngest',
                'https://example.com/ignored/',
            ],
            'path without leading slash' => [
                'https://localhost:3000/api/inngest',
                'https://localhost:3000/foo',
                null,
                'foo',
            ],
            'empty request path' => [
                'https://localhost:3000',
                'https://localhost:3000/',
            ],
            'fragment dropped' => [
                'https://localhost:3000/api/inngest#frag',
                'https://localhost:3000/api/inngest',
            ],
        ];
    }

    /**
     * The client's env goes into the response headers
     *
     * @return void
     */
    public function testUsesEnvHeaderFromClient(): void {
        $harness  = new ServeHarness(
            static::cloud('FOO'),
            [static::registered()]
        );
        $response = $harness->request(
            'PUT',
            ServeHarness::URL,
            [Headers::SIGNATURE => ServeHarness::sign('')]
        );

        $this->assertSame(200, $response->status);
        $this->assertSame('FOO', $response->header(Headers::ENVIRONMENT));
        $this->assertSame(
            'FOO',
            $harness->http->requests[0]->getHeaderLine(Headers::ENVIRONMENT)
        );
    }

    /**
     * The deploy ID is passed through per request, never remembered (#493)
     *
     * @return void
     */
    public function testDoesNotHoldOnToDeployId(): void {
        $harness = new ServeHarness(
            static::cloud(),
            [static::registered(), static::registered(), static::registered()]
        );

        foreach (['1', '', '3'] as $deploy_id) {
            $url = ServeHarness::URL;
            if ($deploy_id !== '') {
                $url .= '?deployId=' . $deploy_id;
            }
            $harness->request('PUT', $url);
        }

        $first = $harness->http->jsonBody(0);
        $this->assertSame('1', $first['deployId']);
        $this->assertSame(
            'https://api.inngest.com/fn/register?deployId=1',
            (string)$harness->http->requests[0]->getUri()
        );

        $second = $harness->http->jsonBody(1);
        $this->assertArrayNotHasKey('deployId', $second);
        $this->assertSame(
            'https://api.inngest.com/fn/register',
            (string)$harness->http->requests[1]->getUri()
        );

        $this->assertSame('3', $harness->http->jsonBody(2)['deployId']);
    }

    /**
     * Register errors are passed back with the status Inngest reported
     *
     * @return void
     */
    public function testRegisterErrorStatusPassedThrough(): void {
        $harness = new ServeHarness(
            static::dev(),
            [MockHttp::json(200, ['status' => 400, 'error' => 'Bad config'])]
        );
        $response = $harness->request('PUT');

        $this->assertSame(400, $response->status);
        $this->assertSame(
            ['message' => 'Bad config', 'modified' => false],
            static::body($response)
        );
    }

    /**
     * The JS sync-kind matrix: which syncs happen for each combination of
     * server, SDK mode, requested kind, signature, and the in-band setting
     *
     * @param string|int  $expected  Sync kind performed, or a status code
     * @param string      $server    Server kind header
     * @param bool        $sdk_dev   Whether the SDK is in dev mode
     * @param string|null $requested Requested sync kind
     * @param bool|null   $valid     Valid (true), invalid (false), or no
     *                               (null) signature
     * @param bool|null   $allow     allow_in_band_sync setting
     * @param bool        $no_body   Send no body
     *
     * @return void
     */
    #[DataProvider('syncKinds')]
    public function testSyncKind(
        string|int $expected,
        string $server,
        bool $sdk_dev,
        ?string $requested,
        ?bool $valid,
        ?bool $allow,
        bool $no_body = false
    ): void {
        $body    = $no_body ?
            null :
            '{"url":"https://example.com/api/inngest"}';
        $headers = [Headers::SERVER_KIND => $server];
        if ($requested !== null) {
            $headers[Headers::SYNC_KIND] = $requested;
        }
        if ($valid === true) {
            $headers[Headers::SIGNATURE] = ServeHarness::sign(
                (string)$body,
                '123'
            );
        } elseif ($valid === false) {
            $headers[Headers::SIGNATURE] = 'INVALID';
        }

        $harness = new ServeHarness(
            new ClientOptions(
                signing_key: '123',
                is_dev: $sdk_dev,
                allow_in_band_sync: $allow
            ),
            [static::registered()]
        );
        $response = $harness->request(
            'PUT',
            ServeHarness::URL,
            $headers,
            $body
        );

        if (is_int($expected)) {
            $this->assertSame($expected, $response->status);
        } else {
            $this->assertSame(200, $response->status);
            if ($sdk_dev || $valid === true) {
                $this->assertSame(
                    $expected,
                    $response->header(Headers::SYNC_KIND)
                );
            } else {
                // Unauthenticated cloud responses don't say which kind.
                $this->assertNull($response->header(Headers::SYNC_KIND));
            }
            $this->assertCount(
                $expected === 'out_of_band' ? 1 : 0,
                $harness->http->requests
            );
        }
    }

    /**
     * Every combination from the JS sync type tests
     *
     * @return array<string, array<int, mixed>>
     */
    public static function syncKinds(): array {
        $cases   = [];
        $servers = ['dev', 'cloud'];
        $modes   = [true, false];
        $sigs    = [null, false, true];

        $add = static function (
            string|int $expected,
            string $server,
            bool $sdk_dev,
            ?string $requested,
            ?bool $valid,
            ?bool $allow,
            bool $no_body = false
        ) use (&$cases): void {
            $name = sprintf(
                '%s server, %s SDK, %s requested, %s signature, in-band %s%s',
                $server,
                $sdk_dev ? 'dev' : 'cloud',
                $requested ?? 'nothing',
                $valid === null ? 'no' : ($valid ? 'valid' : 'invalid'),
                $allow === null ? 'unset' : ($allow ? 'allowed' : 'disallowed'),
                $no_body ? ', no body' : ''
            );
            $cases[$name] = [
                $expected,
                $server,
                $sdk_dev,
                $requested,
                $valid,
                $allow,
                $no_body,
            ];
        };

        foreach ($servers as $srv) {
            foreach ($modes as $dev) {
                // Disallowed in-band: always out-of-band.
                foreach ([null, 'in_band', 'out_of_band'] as $kind) {
                    foreach ($sigs as $sig) {
                        $add('out_of_band', $srv, $dev, $kind, $sig, false);
                    }
                }

                foreach ([null, true] as $allow) {
                    foreach ($sigs as $sig) {
                        $add('out_of_band', $srv, $dev, null, $sig, $allow);
                        $add(
                            'out_of_band',
                            $srv,
                            $dev,
                            'out_of_band',
                            $sig,
                            $allow
                        );

                        $in_band = $sig === true || $dev ? 'in_band' : 401;
                        $add($in_band, $srv, $dev, 'in_band', $sig, $allow);
                    }

                    // #789: in-band without a body is a 500.
                    $add(500, $srv, $dev, 'in_band', true, $allow, true);
                }
            }
        }

        return $cases;
    }

    /**
     * In-band sync with a body that isn't `{"url": "..."}` is a 400
     *
     * @param string $body Raw body
     *
     * @return void
     */
    #[DataProvider('badInBandBodies')]
    public function testInBandInvalidBody(string $body): void {
        $harness  = new ServeHarness(static::dev());
        $response = $harness->request(
            'PUT',
            ServeHarness::URL,
            [Headers::SYNC_KIND => 'in_band'],
            $body
        );

        $this->assertSame(400, $response->status);
        $this->assertSame('invalid_request', static::body($response)['code']);
    }

    /**
     * Bodies that fail in-band validation
     *
     * @return array<string, array{string}>
     */
    public static function badInBandBodies(): array {
        return [
            'extra key'    => ['{"url":"https://example.com","extra":1}'],
            'missing url'  => ['{"other":"x"}'],
            'url not text' => ['{"url":5}'],
            'list'         => ['["https://example.com"]'],
            'invalid json' => ['{"url":'],
        ];
    }

    /**
     * An in-band URL that isn't absolute is an internal error
     *
     * @param string $url URL from the body
     *
     * @return void
     */
    #[DataProvider('badInBandUrls')]
    public function testInBandInvalidUrl(string $url): void {
        $harness  = new ServeHarness(static::dev());
        $response = $harness->request(
            'PUT',
            ServeHarness::URL,
            [Headers::SYNC_KIND => 'in_band'],
            (string)json_encode(['url' => $url])
        );

        $this->assertSame(500, $response->status);
        $body = static::body($response);
        $this->assertSame('internal', $body['type']);
        $this->assertSame(5105, $body['code']);
        $this->assertSame("Invalid URL: $url", $body['message']);
    }

    /**
     * URLs that aren't absolute
     *
     * @return array<string, array{string}>
     */
    public static function badInBandUrls(): array {
        return [
            'relative'    => ['/api/inngest'],
            'unparseable' => ['http://:80'],
        ];
    }

    /**
     * In-band sync uses the URL from the body, with overrides applied
     *
     * @return void
     */
    public function testInBandUsesBodyUrlWithOverrides(): void {
        $harness  = new ServeHarness(new ClientOptions(
            is_dev: true,
            serve_path: '/custom'
        ));
        $response = $harness->request(
            'PUT',
            ServeHarness::URL,
            [Headers::SYNC_KIND => 'in_band'],
            '{"url":"https://example.com/api/inngest"}'
        );

        $body = static::body($response);
        $this->assertSame('https://example.com/custom', $body['url']);
        $this->assertSame('', $body['sdk_language']);
        $this->assertSame('', $body['sdk_version']);
    }

    /**
     * With unauthenticated syncs turned off, cloud mode requires a valid
     * signature for every request, including PUT
     *
     * @return void
     */
    public function testUnauthedSyncDisabled(): void {
        $options = new ClientOptions(
            signing_key: ServeHarness::SIGNING_KEY,
            is_dev: false,
            enable_unauthed_sync: false
        );

        $harness  = new ServeHarness($options, [static::registered()]);
        $response = $harness->request('PUT');
        $this->assertSame(401, $response->status);
        $this->assertCount(0, $harness->http->requests);

        $harness  = new ServeHarness($options, [static::registered()]);
        $response = $harness->request(
            'PUT',
            ServeHarness::URL,
            [Headers::SIGNATURE => ServeHarness::sign('')]
        );
        $this->assertSame(200, $response->status);
        $this->assertCount(1, $harness->http->requests);
    }

    /**
     * Unauthenticated syncs turned off has no effect in dev mode
     *
     * @return void
     */
    public function testUnauthedSyncDisabledIgnoredInDev(): void {
        $harness = new ServeHarness(
            new ClientOptions(is_dev: true, enable_unauthed_sync: false),
            [static::registered()]
        );

        $this->assertSame(200, $harness->request('PUT')->status);
    }

    /**
     * POST without a body is a 401 (#789)
     *
     * @param string|null $body Raw body
     *
     * @return void
     */
    #[DataProvider('missingBodies')]
    public function testPostMissingBody(?string $body): void {
        $harness  = new ServeHarness(static::dev());
        $response = $harness->request(
            'POST',
            ServeHarness::URL . '?fnId=test-app-fn1',
            [],
            $body
        );

        $this->assertSame(401, $response->status);
        $this->assertSame(
            ['message' => 'Unauthorized'],
            static::body($response)
        );
        $this->assertStringContainsString(
            'Missing body when executing',
            $harness->logged('error')[0]
        );
    }

    /**
     * Bodies JS treats as missing (falsy after JSON.parse)
     *
     * @return array<string, array{string|null}>
     */
    public static function missingBodies(): array {
        return [
            'none'         => [null],
            'empty'        => [''],
            'whitespace'   => ["  \n"],
            'null'         => ['null'],
            'false'        => ['false'],
            'zero'         => ['0'],
            'empty string' => ['""'],
        ];
    }

    /**
     * Bodies that are present, even if odd, get past the body check
     *
     * @param string $body Raw body
     *
     * @return void
     */
    #[DataProvider('presentBodies')]
    public function testPostPresentBody(string $body): void {
        $harness  = new ServeHarness(static::dev());
        $response = $harness->request(
            'POST',
            ServeHarness::URL . '?probe=trust',
            [],
            $body
        );

        $this->assertSame(200, $response->status);
    }

    /**
     * Truthy or unparseable bodies
     *
     * @return array<string, array{string}>
     */
    public static function presentBodies(): array {
        return [
            'empty list'   => ['[]'],
            'empty object' => ['{}'],
            'invalid json' => ['{'],
            'one'          => ['1'],
        ];
    }

    /**
     * POST in cloud mode needs a valid signature
     *
     * @param array<string, string> $headers Request headers
     *
     * @return void
     */
    #[DataProvider('badPostSignatures')]
    public function testPostSignatureRequired(array $headers): void {
        $harness = new ServeHarness(
            new ClientOptions(signing_key: self::JS_KEY, is_dev: false)
        );
        $response = $harness->request(
            'POST',
            ServeHarness::URL . '?fnId=test-app-fn1',
            $headers,
            '{"event":{},"events":[{}]}'
        );

        $this->assertSame(401, $response->status);
        $this->assertSame(
            ['message' => 'Unauthorized'],
            static::body($response)
        );
    }

    /**
     * Missing, malformed, and expired signatures
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function badPostSignatures(): array {
        return [
            'no signature' => [[]],
            'invalid'      => [[Headers::SIGNATURE => 't=&s=']],
            'expired'      => [
                [Headers::SIGNATURE => 't=' . (time() - 86400) . '&s=expired'],
            ],
        ];
    }

    /**
     * A real signature from Inngest validates against the raw body, with
     * the main key or the fallback key
     *
     * @param string      $key      Signing key
     * @param string|null $fallback Fallback signing key
     *
     * @return void
     */
    #[DataProvider('validKeys')]
    public function testPostValidSignature(
        string $key,
        ?string $fallback
    ): void {
        $harness = new ServeHarness(
            new ClientOptions(
                signing_key: $key,
                signing_key_fallback: $fallback,
                is_dev: false
            ),
            [],
            true,
            new ExpiredOkSignature()
        );
        $response = $harness->request(
            'POST',
            ServeHarness::URL . '?fnId=test-app-fn1&stepId=step&probe=trust',
            [Headers::SIGNATURE => self::JS_SIGNATURE],
            self::JS_BODY
        );

        $this->assertSame(200, $response->status);
        $this->assertNotNull($response->header(Headers::SIGNATURE));
    }

    /**
     * Keys that verify JS_SIGNATURE
     *
     * @return array<string, array{string, string|null}>
     */
    public static function validKeys(): array {
        return [
            'main key'     => [self::JS_KEY, null],
            'fallback key' => ['fake', self::JS_KEY],
        ];
    }

    /**
     * Fails when neither key matches
     *
     * @return void
     */
    public function testPostFailsWithBothKeysWrong(): void {
        $harness = new ServeHarness(
            new ClientOptions(
                signing_key: 'fake',
                signing_key_fallback: 'another-fake',
                is_dev: false
            ),
            [],
            true,
            new ExpiredOkSignature()
        );
        $response = $harness->request(
            'POST',
            ServeHarness::URL . '?fnId=test-app-fn1&stepId=step',
            [Headers::SIGNATURE => self::JS_SIGNATURE],
            self::JS_BODY
        );

        $this->assertSame(401, $response->status);
    }

    /**
     * Trust probe answers 200 with an empty body; unknown probes are 400
     *
     * @return void
     */
    public function testProbes(): void {
        $harness = new ServeHarness(static::dev());

        $trust = $harness->request(
            'POST',
            ServeHarness::URL . '?probe=trust',
            [],
            '{}'
        );
        $this->assertSame(200, $trust->status);
        $this->assertSame('', $trust->body);

        $unknown = $harness->request(
            'POST',
            ServeHarness::URL . '?probe=other',
            [],
            '{}'
        );
        $this->assertSame(400, $unknown->status);
        $body = static::body($unknown);
        $this->assertSame('Unknown probe "other"', $body['message']);
        $this->assertSame(5101, $body['code']);
        $this->assertTrue($body['__serialized']);
    }

    /**
     * Function lookup: missing or unknown IDs are internal errors; a
     * known ID (including a failure handler's) reaches execution
     *
     * @param string $query    Query string
     * @param int    $code     Expected error code
     *
     * @return void
     */
    #[DataProvider('functionLookups')]
    public function testFunctionLookup(string $query, int $code): void {
        $harness  = new ServeHarness(static::dev());
        $response = $harness->request(
            'POST',
            ServeHarness::URL . $query,
            [],
            '{}'
        );

        $this->assertSame(500, $response->status);
        $body = static::body($response);
        $this->assertSame('internal', $body['type']);
        $this->assertSame($code, $body['code']);
    }

    /**
     * Queries and the error they produce
     *
     * @return array<string, array{string, int}>
     */
    public static function functionLookups(): array {
        return [
            'no fnId'          => ['', 5102],
            'empty fnId'       => ['?fnId=', 5102],
            'empty probe'      => ['?probe=&fnId=', 5102],
            'unknown fnId'     => ['?fnId=nope', 5103],
            'unprefixed fnId'  => ['?fnId=fn1', 5103],
            // Known IDs reach execution, which rejects the `{}` body.
            'known fnId'       => ['?fnId=test-app-fn1', 7001],
            'failure fnId'     => ['?fnId=test-app-fn1-failure', 7001],
        ];
    }

    /**
     * Other methods are a 405
     *
     * @param string $method Method
     *
     * @return void
     */
    #[DataProvider('otherMethods')]
    public function testMethodNotAllowed(string $method): void {
        $harness  = new ServeHarness(static::dev());
        $response = $harness->request($method);

        $this->assertSame(405, $response->status);
        $this->assertSame(
            ['message' => 'Method not allowed'],
            static::body($response)
        );
        $this->assertSame(
            ['Received unhandled HTTP method; expected POST, PUT, or GET'],
            $harness->logged('error')
        );
    }

    /**
     * Unsupported methods
     *
     * @return array<string, array{string}>
     */
    public static function otherMethods(): array {
        return [
            'DELETE'  => ['DELETE'],
            'PATCH'   => ['PATCH'],
            'OPTIONS' => ['OPTIONS'],
            'HEAD'    => ['HEAD'],
        ];
    }

    /**
     * Methods are matched case-insensitively
     *
     * @return void
     */
    public function testLowercaseMethod(): void {
        $harness = new ServeHarness(static::dev());

        $this->assertSame(200, $harness->request('get')->status);
    }

    /**
     * The step to run can come from the `X-Inngest-Step-Id` header when
     * the query doesn't name one
     *
     * @return void
     */
    public function testStepIdFromHeader(): void {
        $harness = new ServeHarness(
            static::dev(),
            [],
            static function (Client $client): void {
                $client->createFunction(
                    new FunctionOptions(id: 'steps'),
                    static fn (Context $ctx) => $ctx->step->run(
                        'A',
                        static fn () => 'ran A'
                    )
                );
            }
        );
        $body = '{"event":{},"ctx":{"run_id":"r",' .
                '"disable_immediate_execution":true}}';

        $response = $harness->request(
            'POST',
            ServeHarness::URL . '?fnId=test-app-steps',
            [Headers::STEP_ID => sha1('A')],
            $body
        );
        $this->assertSame(206, $response->status);
        $this->assertSame('ran A', static::body($response)[0]['data']);

        // `stepId=step` in the query wins over the header and means "none".
        $response = $harness->request(
            'POST',
            ServeHarness::URL . '?fnId=test-app-steps&stepId=step',
            [Headers::STEP_ID => sha1('A')],
            $body
        );
        $this->assertSame(206, $response->status);
        $this->assertSame(
            'StepPlanned',
            static::body($response)[0]['op']
        );
    }

    /**
     * Checkpoints carry the request and generation IDs; a generation ID
     * that isn't an integer is left out (JS `Number.isInteger`)
     *
     * @return void
     */
    public function testCheckpointRequestIds(): void {
        $harness = new ServeHarness(
            static::dev(),
            [],
            static function (Client $client): void {
                $client->createFunction(
                    new FunctionOptions(id: 'steps'),
                    static fn (Context $ctx) => $ctx->step->run(
                        'A',
                        static fn () => 'ran A'
                    )
                );
            }
        );
        $body  = '{"event":{},"ctx":{"run_id":"r","fn_id":"f","qi_id":"q"}}';
        $cases = ['7' => 7, '-2' => -2, '1.5' => null, 'abc' => null];

        foreach ($cases as $header => $expected) {
            $harness->http->requests = [];
            $harness->http->handler->append(
                MockHttp::json(200, new \stdClass())
            );

            $response = $harness->request(
                'POST',
                ServeHarness::URL . '?fnId=test-app-steps',
                [
                    Headers::REQUEST_ID    => 'req',
                    Headers::GENERATION_ID => (string)$header,
                ],
                $body
            );

            $sent = json_decode(
                (string)$harness->http->requests[0]->getBody(),
                true
            );
            $this->assertSame(206, $response->status);
            $this->assertSame('RunComplete', static::body($response)[0]['op']);
            $this->assertSame('req', $sent['request_id']);
            $this->assertSame($expected, $sent['generation_id'] ?? null);
        }
    }

    /**
     * Step-not-found messages list at most the capped steps and say how
     * many there were
     *
     * @return void
     */
    public function testStepNotFoundMessage(): void {
        $harness = new ServeHarness(static::dev());
        $handler = new class($harness->client) extends ServeHandler {
            public function respond(ExecutionResult $result): Response {
                return $this->executionResponse($result);
            }
        };

        $found = [
            ['id' => 'aaa', 'name' => 'a', 'displayName' => 'Step A'],
            ['id' => 'bbb'],
        ];
        $body = static::body($handler->respond(
            ExecutionResult::stepNotFound('zzz', $found, 30)
        ));

        $this->assertSame(
            'Could not find step "zzz" to run; timed out. Found new ' .
            "steps: \nStep A (aaa)\nbbb (bbb). (showing 2 of 30)",
            $body['error']
        );
        $this->assertSame('zzz', $body['requestedStep']);
        $this->assertSame(30, $body['totalFoundSteps']);

        $body = static::body($handler->respond(
            ExecutionResult::stepNotFound('zzz', [], 0)
        ));
        $this->assertSame(
            'Could not find step "zzz" to run; timed out.',
            $body['error']
        );
    }
}
