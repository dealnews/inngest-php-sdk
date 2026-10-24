<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Config;

use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Config\Mode;
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\RecordingLogger;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for ClientConfig (ports the JS "mode" and "URL configuration"
 * tests from Inngest.test.ts)
 *
 * @package DealNews\Inngest\Tests\Config
 */
#[CoversClass(ClientConfig::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(Env::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValueObject::class)]
class ClientConfigTest extends TestCase {

    /**
     * Mode resolution matches the JS client
     *
     * @param array<string, string> $env      Environment variables
     * @param bool|null             $is_dev   is_dev option
     * @param Mode                  $expected Expected mode
     *
     * @return void
     */
    #[DataProvider('modeProvider')]
    public function testMode(array $env, ?bool $is_dev, Mode $expected): void {
        $config = $this->resolve($env, new ClientOptions(is_dev: $is_dev));

        $this->assertSame($expected, $config->mode);
    }

    /**
     * Cases for testMode
     *
     * @return array<string, array{array<string, string>, ?bool, Mode}>
     */
    public static function modeProvider(): array {
        return [
            'default cloud'         => [[], null, Mode::Cloud],
            'is_dev true'           => [[], true, Mode::Dev],
            'is_dev false'          => [[], false, Mode::Cloud],
            'INNGEST_DEV=1'         => [[Env::DEV => '1'], null, Mode::Dev],
            'INNGEST_DEV=true'      => [[Env::DEV => 'true'], null, Mode::Dev],
            'INNGEST_DEV=false'     => [
                [Env::DEV => 'false'],
                null,
                Mode::Cloud,
            ],
            'INNGEST_DEV=0'         => [[Env::DEV => '0'], null, Mode::Cloud],
            'is_dev overrides env'  => [[Env::DEV => '1'], false, Mode::Cloud],
            'INNGEST_DEV=URL'       => [
                [Env::DEV => 'http://localhost:3000'],
                null,
                Mode::Dev,
            ],
            'INNGEST_DEV=undefined' => [
                [Env::DEV => 'undefined'],
                null,
                Mode::Cloud,
            ],
        ];
    }

    /**
     * A URL in INNGEST_DEV becomes the default for both base URLs
     *
     * @param string $value    INNGEST_DEV value
     * @param string $expected Expected base URL
     *
     * @return void
     */
    #[DataProvider('devUrlProvider')]
    public function testDevUrl(string $value, string $expected): void {
        $config = $this->resolve([Env::DEV => $value]);

        $this->assertSame(Mode::Dev, $config->mode);
        $this->assertSame($expected, $config->api_base_url);
        $this->assertSame($expected, $config->event_api_base_url);
    }

    /**
     * Cases for testDevUrl
     *
     * @return array<string, array{string, string}>
     */
    public static function devUrlProvider(): array {
        return [
            'with scheme'    => [
                'http://localhost:3000',
                'http://localhost:3000/',
            ],
            'host:port'      => ['0.0.0.0:8288', 'http://0.0.0.0:8288/'],
            'localhost:port' => ['localhost:9000', 'http://localhost:9000/'],
        ];
    }

    /**
     * A dev URL also applies when dev mode was forced by the option
     *
     * @return void
     */
    public function testDevUrlWithIsDevOption(): void {
        $config = $this->resolve(
            [Env::DEV => 'localhost:9000'],
            new ClientOptions(is_dev: true)
        );

        $this->assertSame('http://localhost:9000/', $config->api_base_url);
    }

    /**
     * Base URL resolution matches the JS client
     *
     * @param array<string, string> $env       Environment variables
     * @param ClientOptions         $options   Options
     * @param string                $api       Expected API URL
     * @param string                $event_api Expected event API URL
     *
     * @return void
     */
    #[DataProvider('urlProvider')]
    public function testUrls(
        array $env,
        ClientOptions $options,
        string $api,
        string $event_api
    ): void {
        $config = $this->resolve($env, $options);

        $this->assertSame($api, $config->api_base_url);
        $this->assertSame($event_api, $config->event_api_base_url);
    }

    /**
     * Cases for testUrls
     *
     * @return array<string, array<mixed>>
     */
    public static function urlProvider(): array {
        return [
            'cloud defaults'            => [
                [],
                new ClientOptions(),
                'https://api.inngest.com/',
                'https://inn.gs/',
            ],
            'dev defaults'              => [
                [],
                new ClientOptions(is_dev: true),
                'http://localhost:8288/',
                'http://localhost:8288/',
            ],
            'INNGEST_BASE_URL'          => [
                [Env::BASE_URL => 'http://custom:8000/'],
                new ClientOptions(),
                'http://custom:8000/',
                'http://custom:8000/',
            ],
            'INNGEST_API_BASE_URL'      => [
                [Env::API_BASE_URL => 'http://api-only:8000/'],
                new ClientOptions(),
                'http://api-only:8000/',
                'https://inn.gs/',
            ],
            'INNGEST_EVENT_API_BASE_URL' => [
                [Env::EVENT_API_BASE_URL => 'http://event-only:8000/'],
                new ClientOptions(),
                'https://api.inngest.com/',
                'http://event-only:8000/',
            ],
            'specific beats base'       => [
                [
                    Env::BASE_URL           => 'http://base:8000/',
                    Env::API_BASE_URL       => 'http://api-specific:9000/',
                    Env::EVENT_API_BASE_URL => 'http://event-specific:9001/',
                ],
                new ClientOptions(),
                'http://api-specific:9000/',
                'http://event-specific:9001/',
            ],
            'option base_url beats env' => [
                [
                    Env::BASE_URL           => 'http://base:8000/',
                    Env::API_BASE_URL       => 'http://api:9000/',
                    Env::EVENT_API_BASE_URL => 'http://event:9001/',
                ],
                new ClientOptions(base_url: 'http://option:7000/'),
                'http://option:7000/',
                'http://option:7000/',
            ],
            'specific options win'      => [
                [],
                new ClientOptions(
                    base_url: 'http://option:7000/',
                    api_base_url: 'http://api-opt/',
                    event_api_base_url: 'http://event-opt/'
                ),
                'http://api-opt/',
                'http://event-opt/',
            ],
        ];
    }

    /**
     * Keys and names: options win over env vars, which win over defaults
     *
     * @return void
     */
    public function testOptionsBeatEnv(): void {
        $env = [
            Env::EVENT_KEY            => 'env-event',
            Env::SIGNING_KEY          => 'env-sign',
            Env::SIGNING_KEY_FALLBACK => 'env-fallback',
            Env::ENV                  => 'env-branch',
            Env::SERVE_ORIGIN         => 'https://env.example.com',
            Env::SERVE_PATH           => '/env/inngest',
            Env::ENABLE_UNAUTHED_SYNC => 'false',
            Env::ALLOW_IN_BAND_SYNC   => 'false',
        ];

        $from_env = $this->resolve($env);
        $this->assertSame('env-event', $from_env->event_key);
        $this->assertSame('env-sign', $from_env->signing_key);
        $this->assertSame('env-fallback', $from_env->signing_key_fallback);
        $this->assertSame('env-branch', $from_env->env);
        $this->assertSame('https://env.example.com', $from_env->serve_origin);
        $this->assertSame('/env/inngest', $from_env->serve_path);
        $this->assertFalse($from_env->enable_unauthed_sync);
        $this->assertFalse($from_env->allow_in_band_sync);

        $from_options = $this->resolve($env, new ClientOptions(
            event_key: 'opt-event',
            signing_key: 'opt-sign',
            signing_key_fallback: 'opt-fallback',
            env: 'opt-branch',
            app_version: '1.2.3',
            serve_origin: 'https://opt.example.com',
            serve_path: '/opt/inngest',
            enable_unauthed_sync: true,
            allow_in_band_sync: true
        ));
        $this->assertSame('test', $from_options->app_id);
        $this->assertSame('opt-event', $from_options->event_key);
        $this->assertSame('opt-sign', $from_options->signing_key);
        $this->assertSame('opt-fallback', $from_options->signing_key_fallback);
        $this->assertSame('opt-branch', $from_options->env);
        $this->assertSame('1.2.3', $from_options->app_version);
        $this->assertSame(
            'https://opt.example.com',
            $from_options->serve_origin
        );
        $this->assertSame('/opt/inngest', $from_options->serve_path);
        $this->assertTrue($from_options->enable_unauthed_sync);
        $this->assertTrue($from_options->allow_in_band_sync);
    }

    /**
     * Unset values fall back to SDK defaults
     *
     * @return void
     */
    public function testDefaults(): void {
        $config = $this->resolve([]);

        $this->assertNull($config->event_key);
        $this->assertNull($config->signing_key);
        $this->assertNull($config->signing_key_fallback);
        $this->assertNull($config->env);
        $this->assertNull($config->app_version);
        $this->assertNull($config->serve_origin);
        $this->assertNull($config->serve_path);
        $this->assertTrue($config->enable_unauthed_sync);
        $this->assertTrue($config->allow_in_band_sync);
    }

    /**
     * The deprecated INNGEST_SERVE_HOST still works, with a warning
     *
     * @return void
     */
    public function testServeHostDeprecated(): void {
        $logger = new RecordingLogger();
        $config = ClientConfig::resolve(
            'test',
            null,
            new Env([Env::SERVE_HOST => 'https://host.example.com']),
            $logger
        );

        $this->assertSame('https://host.example.com', $config->serve_origin);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
    }

    /**
     * INNGEST_SERVE_ORIGIN wins over the deprecated variable silently
     *
     * @return void
     */
    public function testServeOriginBeatsServeHost(): void {
        $logger = new RecordingLogger();
        $config = ClientConfig::resolve('test', null, new Env([
            Env::SERVE_ORIGIN => 'https://origin.example.com',
            Env::SERVE_HOST   => 'https://host.example.com',
        ]), $logger);

        $this->assertSame('https://origin.example.com', $config->serve_origin);
        $this->assertCount(0, $logger->records);
    }

    /**
     * An app ID is required
     *
     * @return void
     */
    public function testEmptyAppIdThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(4001);
        ClientConfig::resolve('  ', null, new Env([]));
    }

    /**
     * All arguments but the app ID are optional
     *
     * @return void
     */
    public function testResolveWithDefaults(): void {
        $this->assertSame('app', ClientConfig::resolve('app')->app_id);
    }

    /**
     * Resolves a config for app "test" with fixed env vars
     *
     * @param array<string, string> $env     Environment variables
     * @param ClientOptions|null    $options Options
     *
     * @return ClientConfig
     */
    protected function resolve(
        array $env,
        ?ClientOptions $options = null
    ): ClientConfig {
        return ClientConfig::resolve('test', $options, new Env($env));
    }
}
