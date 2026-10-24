<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\SerializedError;
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
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\MockHttp;
use DealNews\Inngest\Tests\mock\ServeHarness;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Compares ServeHandler responses with real JS SDK responses
 *
 * tests/fixtures/serve/js-responses.json comes from running the JS
 * `InngestCommHandler` (see generate.mts there). Each case here sends the
 * same request to the PHP handler. Before comparing, the JS output is
 * mapped to this SDK's identity: SDK name/version, `framework: "php"`,
 * no Connect capability, no feature observations, no
 * `X-Inngest-Framework` header, no `Authorization` header without a key
 * (D25), and `sdk_author: "dealnews"`. Error stacks and PHP's error
 * `code` are ignored.
 *
 * @package DealNews\Inngest\Tests\Http
 */
#[CoversClass(ServeHandler::class)]
#[CoversClass(Introspector::class)]
#[CoversClass(Registrar::class)]
#[UsesClass(ApiClient::class)]
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
#[UsesClass(IntrospectionExtra::class)]
#[UsesClass(RegisterRequest::class)]
#[UsesClass(RegisterResult::class)]
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
class ServeHandlerParityTest extends TestCase {

    /**
     * SDK identifier the PHP harness reports
     *
     * @var string
     */
    public const SDK = 'dealnews/inngest-php-sdk:v1.2.3';

    /**
     * Fixture file
     *
     * @var string
     */
    public const FIXTURE = 'serve/js-responses.json';

    /**
     * PHP equivalents of every JS fixture case
     *
     * Each entry is a callable that sends the request and returns the
     * harness, plus the response.
     *
     * @return array<string, array{\Closure(): array{ServeHarness, Response}}>
     */
    public static function cases(): array {
        $dev         = static fn () => new ClientOptions(is_dev: true);
        $plain_get   = static function (
            ClientOptions $options,
            array $headers = []
        ): array {
            return static::send($options, 'GET', ServeHarness::URL, $headers);
        };
        $dev_url     = 'http://localhost:3000/api/inngest';
        $register_ok = MockHttp::json(
            200,
            ['status' => 200, 'modified' => true]
        );
        $in_band_url = '{"url":"https://example.com/api/inngest?x=1"}';
        $cloud_url   = '{"url":"https://example.com/api/inngest"}';

        return [
            'introspection_dev' => [
                static fn () => static::send(
                    $dev(),
                    'GET',
                    $dev_url,
                    [Headers::SERVER_KIND => 'dev']
                ),
            ],
            'introspection_cloud' => [
                static fn () => $plain_get(
                    new ClientOptions(
                        event_key: ServeHarness::EVENT_KEY,
                        signing_key: ServeHarness::SIGNING_KEY,
                        signing_key_fallback:
                            ServeHarness::SIGNING_KEY_FALLBACK,
                        env: 'my-env',
                        is_dev: false,
                        serve_origin: 'https://example.com',
                        serve_path: '/custom/path'
                    ),
                    [
                        Headers::SIGNATURE    => ServeHarness::sign(''),
                        Headers::TRACE_PARENT => '00-abc-def-01',
                    ]
                ),
            ],
            'introspection_cloud_no_keys' => [
                static fn () => $plain_get(
                    new ClientOptions(
                        signing_key: ServeHarness::SIGNING_KEY,
                        is_dev: false
                    ),
                    [Headers::SIGNATURE => ServeHarness::sign('')]
                ),
            ],
            'in_band_dev' => [
                static fn () => static::send(
                    $dev(),
                    'PUT',
                    $dev_url . '?deployId=dep-1',
                    [Headers::SYNC_KIND => 'in_band'],
                    $in_band_url
                ),
            ],
            'in_band_cloud' => [
                static fn () => static::send(
                    new ClientOptions(
                        event_key: ServeHarness::EVENT_KEY,
                        signing_key: ServeHarness::SIGNING_KEY,
                        is_dev: false,
                        app_version: '2.0.0'
                    ),
                    'PUT',
                    ServeHarness::URL,
                    [
                        Headers::SYNC_KIND => 'in_band',
                        Headers::SIGNATURE => ServeHarness::sign($cloud_url),
                    ],
                    $cloud_url
                ),
            ],
            'in_band_invalid_body' => [
                static fn () => static::send(
                    $dev(),
                    'PUT',
                    $dev_url,
                    [Headers::SYNC_KIND => 'in_band'],
                    '{"url":"https://example.com/api/inngest","extra":1}'
                ),
            ],
            'out_of_band_cloud' => [
                static fn () => static::send(
                    new ClientOptions(
                        signing_key: ServeHarness::SIGNING_KEY,
                        signing_key_fallback:
                            ServeHarness::SIGNING_KEY_FALLBACK,
                        is_dev: false,
                        app_version: '2.0.0'
                    ),
                    'PUT',
                    ServeHarness::URL . '?deployId=dep-1',
                    [
                        Headers::SIGNATURE   => ServeHarness::sign(''),
                        Headers::SERVER_KIND => 'cloud',
                    ],
                    null,
                    [$register_ok]
                ),
            ],
            'out_of_band_dev_origin_path' => [
                static fn () => static::send(
                    new ClientOptions(
                        is_dev: true,
                        serve_origin: 'https://example.com',
                        serve_path: '/custom/path'
                    ),
                    'PUT',
                    $dev_url . '?deployId=undefined',
                    [],
                    null,
                    [$register_ok]
                ),
            ],
            'post_trust_probe' => [
                static fn () => static::send(
                    $dev(),
                    'POST',
                    $dev_url . '?probe=trust',
                    [],
                    '{}'
                ),
            ],
            'post_unknown_probe' => [
                static fn () => static::send(
                    $dev(),
                    'POST',
                    $dev_url . '?probe=nope',
                    [],
                    '{}'
                ),
            ],
            'post_missing_fn_id' => [
                static fn () => static::send(
                    $dev(),
                    'POST',
                    $dev_url,
                    [],
                    '{}'
                ),
            ],
            'post_unknown_fn' => [
                static fn () => static::send(
                    $dev(),
                    'POST',
                    $dev_url . '?fnId=nope',
                    [],
                    '{}'
                ),
            ],
            'post_missing_body' => [
                static fn () => static::send(
                    $dev(),
                    'POST',
                    $dev_url . '?fnId=test-app-fn1'
                ),
            ],
            'method_not_allowed' => [
                static fn () => static::send($dev(), 'DELETE', $dev_url),
            ],
            'cloud_no_signing_key' => [
                static fn () => $plain_get(new ClientOptions(is_dev: false)),
            ],
            'cloud_unsigned_get' => [
                static fn () => $plain_get(
                    new ClientOptions(
                        signing_key: ServeHarness::SIGNING_KEY,
                        env: 'my-env',
                        is_dev: false
                    ),
                    [Headers::SERVER_KIND => 'cloud']
                ),
            ],
        ];
    }

    /**
     * Status, headers, body, and register requests match the JS SDK
     *
     * @param \Closure $send Sends the PHP request
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testMatchesJs(\Closure $send): void {
        $name     = (string)$this->dataName();
        $expected = $this->fixture()->$name;

        [$harness, $response] = $send();

        $this->assertSame($expected->status, $response->status, 'status');
        $this->assertEquals(
            static::jsHeaders($expected->headers),
            static::phpHeaders($response->headers),
            'headers'
        );
        $this->assertJsonDataSame(
            static::encode(static::jsBody($name, $expected->body)),
            static::encode(static::phpBody($name, $response->body)),
            'body'
        );

        $expected_register = $expected->register ?? [];
        $this->assertCount(count($expected_register), $harness->http->requests);
        foreach ($expected_register as $index => $register) {
            $request = $harness->http->requests[$index];
            $this->assertSame($register->url, (string)$request->getUri());
            $this->assertSame($register->method, $request->getMethod());
            $this->assertEquals(
                static::jsRegisterHeaders($register->headers),
                static::phpRequestHeaders($request->getHeaders())
            );
            $this->assertJsonDataSame(
                static::encode(static::normalize($register->body)),
                (string)$request->getBody()
            );
        }
    }

    /**
     * Every fixture case has a PHP equivalent
     *
     * @return void
     */
    public function testAllFixtureCasesCovered(): void {
        $this->assertSame(
            array_keys(get_object_vars($this->fixture())),
            array_keys(static::cases())
        );
    }

    /**
     * Builds a harness and sends one request
     *
     * @param ClientOptions         $options   Client options
     * @param string                $method    Method
     * @param string                $url       URL
     * @param array<string, string> $headers   Headers
     * @param string|null           $body      Raw body
     * @param list<mixed>           $responses Outbound responses
     *
     * @return array{ServeHarness, Response}
     */
    protected static function send(
        ClientOptions $options,
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $responses = []
    ): array {
        $harness = new ServeHarness($options, $responses);

        return [$harness, $harness->request($method, $url, $headers, $body)];
    }

    /**
     * Loads the fixture, keeping `{}` vs `[]`
     *
     * @return \stdClass
     */
    protected function fixture(): \stdClass {
        return json_decode(
            $this->loadFixture(self::FIXTURE),
            false,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Maps JS-only values in a decoded payload to this SDK's values
     *
     * @param mixed $value Decoded JSON (objects as stdClass)
     *
     * @return mixed
     */
    protected static function normalize(mixed $value): mixed {
        $result = $value;

        if ($value instanceof \stdClass) {
            $result = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                if ($key === 'featureObservations') {
                    continue;
                }

                $item = static::normalizeField((string)$key, $item);

                if ($key === 'capabilities') {
                    unset($item->connect);
                }

                $result->$key = $item;
            }
        } elseif (is_array($value)) {
            $result = array_map(
                static fn ($item) => static::normalize($item),
                $value
            );
        }

        return $result;
    }

    /**
     * Maps one JS field value to this SDK's value
     *
     * @param string $key  Field name
     * @param mixed  $item Value
     *
     * @return mixed
     */
    protected static function normalizeField(string $key, mixed $item): mixed {
        $result = static::normalize($item);

        if ($key === 'framework') {
            $result = 'php';
        } elseif ($key === 'sdk') {
            $result = self::SDK;
        } elseif ($key === 'sdk_language' && $item === 'js') {
            $result = 'php';
        } elseif ($key === 'sdk_version' && $item !== '') {
            $result = '1.2.3';
        } elseif ($key === 'sdk_author') {
            $result = 'dealnews';
        }

        return $result;
    }

    /**
     * Normalizes a JS response body
     *
     * @param string $name Case name
     * @param mixed  $body Decoded body (or raw text)
     *
     * @return mixed
     */
    protected static function jsBody(string $name, mixed $body): mixed {
        $body = static::normalize($body);

        if ($body instanceof \stdClass && isset($body->__serialized)) {
            unset($body->stack);
            $body->name = 'ServeException';
        }
        if ($name === 'in_band_invalid_body') {
            $body->message = '<validation message>';
        }

        return $body;
    }

    /**
     * Decodes and normalizes a PHP response body
     *
     * @param string $name Case name
     * @param string $raw  Raw body
     *
     * @return mixed
     */
    protected static function phpBody(string $name, string $raw): mixed {
        $body = $raw === '' ? '' : json_decode($raw, false);

        // PHP errors also carry their exception code (D20).
        if ($body instanceof \stdClass && isset($body->__serialized)) {
            unset($body->stack, $body->code);
        }
        if ($name === 'in_band_invalid_body') {
            $body->message = '<validation message>';
        }

        return $body;
    }

    /**
     * Normalizes JS response headers
     *
     * @param \stdClass $headers Headers
     *
     * @return array<string, string>
     */
    protected static function jsHeaders(\stdClass $headers): array {
        $result = [];
        foreach (get_object_vars($headers) as $name => $value) {
            $result[$name] = match ($name) {
                'user-agent', Headers::SDK_VERSION => self::SDK,
                Headers::SIGNATURE                 => '<signature>',
                default                            => $value,
            };
        }
        unset($result['x-inngest-framework']);
        ksort($result);

        return $result;
    }

    /**
     * Normalizes PHP response headers
     *
     * @param array<string, string> $headers Headers
     *
     * @return array<string, string>
     */
    protected static function phpHeaders(array $headers): array {
        if (isset($headers[Headers::SIGNATURE])) {
            $headers[Headers::SIGNATURE] = '<signature>';
        }
        ksort($headers);

        return $headers;
    }

    /**
     * Normalizes the headers of the request JS sent to /fn/register
     *
     * @param \stdClass $headers Headers
     *
     * @return array<string, string>
     */
    protected static function jsRegisterHeaders(\stdClass $headers): array {
        $result = static::jsHeaders($headers);
        unset($result['server-timing']);
        if (($result['authorization'] ?? '') === 'Bearer undefined') {
            unset($result['authorization']);
        }

        return $result;
    }

    /**
     * Normalizes the headers of a request PHP sent
     *
     * @param array<string, list<string>> $headers PSR-7 headers
     *
     * @return array<string, string>
     */
    protected static function phpRequestHeaders(array $headers): array {
        $result = [];
        foreach ($headers as $name => $values) {
            $result[strtolower((string)$name)] = implode(', ', $values);
        }
        unset($result['host'], $result['content-length']);
        ksort($result);

        return $result;
    }

    /**
     * Encodes data for assertJsonDataSame()
     *
     * @param mixed $data Data
     *
     * @return string
     */
    protected static function encode(mixed $data): string {
        return (string)json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}
