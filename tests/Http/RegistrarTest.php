<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Api\ApiException;
use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
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
use DealNews\Inngest\Http\ServeHandler;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Http\Sync\InBandRegisterRequest;
use DealNews\Inngest\Http\Sync\RegisterRequest;
use DealNews\Inngest\Http\Sync\RegisterResult;
use DealNews\Inngest\Middleware\Middleware;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\MockHttp;
use DealNews\Inngest\Tests\mock\ServeHarness;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Registrar (ports the JS `register()` response handling)
 *
 * @package DealNews\Inngest\Tests\Http
 */
#[CoversClass(Registrar::class)]
#[UsesClass(ApiClient::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(AuthenticatedIntrospection::class)]
#[UsesClass(Capabilities::class)]
#[UsesClass(Client::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(CronTrigger::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Env::class)]
#[UsesClass(EventTrigger::class)]
#[UsesClass(FunctionConfig::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(Headers::class)]
#[UsesClass(InBandRegisterRequest::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(IntrospectionExtra::class)]
#[UsesClass(Introspector::class)]
#[UsesClass(Middleware::class)]
#[UsesClass(MiddlewareStack::class)]
#[UsesClass(RegisterRequest::class)]
#[UsesClass(RegisterResult::class)]
#[UsesClass(Retry::class)]
#[UsesClass(ServeHandler::class)]
#[UsesClass(Signature::class)]
#[UsesClass(StepConfig::class)]
#[UsesClass(StepRetries::class)]
#[UsesClass(StepRuntime::class)]
#[UsesClass(UnauthenticatedIntrospection::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(Version::class)]
class RegistrarTest extends TestCase {

    /**
     * Serve URL used throughout
     *
     * @var string
     */
    public const URL = 'https://example.com/api/inngest';

    /**
     * Configs cover every function, failure handlers included, in order
     *
     * @return void
     */
    public function testConfigs(): void {
        $harness   = new ServeHarness(new ClientOptions(is_dev: true));
        $registrar = new Registrar($harness->client);

        $ids = array_map(
            static fn (FunctionConfig $config) => $config->id,
            $registrar->configs(self::URL)
        );

        $this->assertSame(
            ['test-app-fn1', 'test-app-fn1-failure', 'test-app-fn2'],
            $ids
        );
    }

    /**
     * No functions, no configs
     *
     * @return void
     */
    public function testConfigsEmpty(): void {
        $harness = new ServeHarness(new ClientOptions(is_dev: true), [], false);

        $this->assertSame(
            [],
            (new Registrar($harness->client))->configs(self::URL)
        );
    }

    /**
     * The register body omits a missing deploy ID and app version
     *
     * @param string|null $deploy_id Deploy ID
     *
     * @return void
     */
    #[DataProvider('emptyDeployIds')]
    public function testRegisterBodyOmitsEmpty(?string $deploy_id): void {
        $harness = new ServeHarness(new ClientOptions(is_dev: true), [], false);
        $body    = (new Registrar($harness->client))->registerBody(
            self::URL,
            $deploy_id
        );

        $this->assertSame(
            '{"url":"https://example.com/api/inngest","deployType":"ping",' .
            '"framework":"php","appName":"test-app","functions":[],' .
            '"sdk":"dealnews/inngest-php-sdk:v1.2.3","v":"0.1",' .
            '"capabilities":{"trust_probe":"v1"}}',
            $body->toJson()
        );
    }

    /**
     * Deploy IDs that count as unset
     *
     * @return array<string, array{string|null}>
     */
    public static function emptyDeployIds(): array {
        return [
            'null'  => [null],
            'empty' => [''],
        ];
    }

    /**
     * In-band bodies only report the SDK language and version when the
     * request was authenticated
     *
     * @return void
     */
    public function testInBandBody(): void {
        $harness = new ServeHarness(
            new ClientOptions(env: 'my-env', app_version: '3.0'),
            [],
            false
        );
        $registrar = new Registrar($harness->client);

        $plain = $registrar->inBandBody(
            self::URL,
            new UnauthenticatedIntrospection()
        );
        $this->assertSame('', $plain->sdk_language);
        $this->assertSame('', $plain->sdk_version);
        $this->assertSame('dealnews', $plain->sdk_author);
        $this->assertSame('my-env', $plain->env);
        $this->assertSame('3.0', $plain->app_version);

        $inspection               = new AuthenticatedIntrospection();
        $inspection->sdk_language = 'php';
        $inspection->sdk_version  = '1.2.3';

        $authed = $registrar->inBandBody(self::URL, $inspection);
        $this->assertSame('php', $authed->sdk_language);
        $this->assertSame('1.2.3', $authed->sdk_version);
    }

    /**
     * Register posts the body with the sync-kind and extra headers
     *
     * @return void
     */
    public function testRegisterRequest(): void {
        $harness = new ServeHarness(
            new ClientOptions(is_dev: true),
            [MockHttp::json(200, ['status' => 201, 'modified' => true])]
        );

        $result = (new Registrar($harness->client))->register(
            self::URL,
            'dep 1',
            [Headers::TRACE_PARENT => '00-x']
        );

        $this->assertSame(201, $result->status);
        $this->assertSame('Successfully registered', $result->message);
        $this->assertTrue($result->modified);

        $request = $harness->http->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            'http://localhost:8288/fn/register?deployId=dep+1',
            (string)$request->getUri()
        );
        $this->assertSame(
            'out_of_band',
            $request->getHeaderLine(Headers::SYNC_KIND)
        );
        $this->assertSame('00-x', $request->getHeaderLine('traceparent'));
        $this->assertSame('dep 1', $harness->http->jsonBody()['deployId']);
        $this->assertSame(
            ['Registered inngest functions'],
            $harness->logged('debug')
        );
    }

    /**
     * Register response handling
     *
     * @param GuzzleResponse|\Throwable $reply    Response or error
     * @param int                       $status   Expected status
     * @param string                    $message  Expected message
     * @param bool                      $modified Expected modified flag
     * @param list<string>              $debug    Expected debug logs
     *
     * @return void
     */
    #[DataProvider('replies')]
    public function testRegisterResult(
        GuzzleResponse|\Throwable $reply,
        int $status,
        string $message,
        bool $modified,
        array $debug
    ): void {
        $harness = new ServeHarness(new ClientOptions(is_dev: true), [$reply]);
        $result  = (new Registrar($harness->client))->register(self::URL, null);

        $this->assertSame($status, $result->status);
        $this->assertSame($message, $result->message);
        $this->assertSame($modified, $result->modified);
        $this->assertSame($debug, $harness->logged('debug'));
    }

    /**
     * Register replies and their results
     *
     * @return array<string, array<int, mixed>>
     */
    public static function replies(): array {
        $registered = ['Registered inngest functions'];
        $invalid    = 'Failed to register; Invalid register response; ' .
                      'status code: 200';

        return [
            'defaults' => [
                MockHttp::json(200, new \stdClass()),
                200,
                'Successfully registered',
                false,
                $registered,
            ],
            'error status from body, not HTTP' => [
                MockHttp::json(500, ['status' => 400, 'error' => 'Nope']),
                400,
                'Nope',
                false,
                $registered,
            ],
            'float status' => [
                MockHttp::json(200, ['status' => 202.0]),
                202,
                'Successfully registered',
                false,
                $registered,
            ],
            'skipped' => [
                MockHttp::json(200, ['skipped' => true]),
                200,
                'Successfully registered',
                false,
                [],
            ],
            'not json' => [
                new GuzzleResponse(502, [], 'Bad Gateway'),
                500,
                'Failed to register; Syntax error; status code: 502',
                false,
                [],
            ],
            'empty body' => [
                new GuzzleResponse(200, [], ''),
                500,
                'Failed to register; Syntax error; status code: 200',
                false,
                [],
            ],
            'not an object' => [
                MockHttp::json(200, [1, 2]),
                500,
                $invalid,
                false,
                [],
            ],
            'status not a number' => [
                MockHttp::json(200, ['status' => '200']),
                500,
                $invalid,
                false,
                [],
            ],
            'error not a string' => [
                MockHttp::json(200, ['error' => 5]),
                500,
                $invalid,
                false,
                [],
            ],
            'skipped not a bool' => [
                MockHttp::json(200, ['skipped' => 'no']),
                500,
                $invalid,
                false,
                [],
            ],
            'modified not a bool' => [
                MockHttp::json(200, ['modified' => 1]),
                500,
                $invalid,
                false,
                [],
            ],
            'explicit null' => [
                MockHttp::json(200, ['error' => null]),
                500,
                $invalid,
                false,
                [],
            ],
            'transport error' => [
                new ConnectException(
                    'refused',
                    new GuzzleRequest('POST', 'http://localhost:8288')
                ),
                500,
                'Failed to register; Request to ' .
                'http://localhost:8288/fn/register failed: refused',
                false,
                [],
            ],
        ];
    }

    /**
     * Transport errors are logged
     *
     * @return void
     */
    public function testTransportErrorLogged(): void {
        $harness = new ServeHarness(
            new ClientOptions(is_dev: true),
            [
                new ConnectException(
                    'refused',
                    new GuzzleRequest('POST', 'http://localhost:8288')
                ),
            ]
        );
        (new Registrar($harness->client))->register(self::URL, null);

        $this->assertSame(['Failed to register'], $harness->logged('error'));
    }
}
