<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http;

use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\Mode;
use DealNews\Inngest\Http\Capabilities;
use DealNews\Inngest\Http\Introspection\AuthenticatedIntrospection;
use DealNews\Inngest\Http\Introspection\IntrospectionExtra;
use DealNews\Inngest\Http\Introspection\UnauthenticatedIntrospection;
use DealNews\Inngest\Http\Introspector;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Tests\mock\FixedVersion;
use DealNews\Inngest\Tests\mock\ServeHarness;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Introspector (ports the JS "introspection" tests)
 *
 * @package DealNews\Inngest\Tests\Http
 */
#[CoversClass(Introspector::class)]
#[UsesClass(AuthenticatedIntrospection::class)]
#[UsesClass(Capabilities::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(IntrospectionExtra::class)]
#[UsesClass(Signature::class)]
#[UsesClass(UnauthenticatedIntrospection::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(Version::class)]
class IntrospectorTest extends TestCase {

    /**
     * Builds a config
     *
     * @param Mode        $mode        Mode
     * @param string|null $signing_key Signing key
     * @param string|null $fallback    Fallback signing key
     * @param string|null $event_key   Event key
     *
     * @return ClientConfig
     */
    protected static function config(
        Mode $mode,
        ?string $signing_key = null,
        ?string $fallback = null,
        ?string $event_key = null
    ): ClientConfig {
        return new ClientConfig(
            app_id: 'test',
            mode: $mode,
            api_base_url: 'https://api.inngest.com/',
            event_api_base_url: 'https://inn.gs/',
            event_key: $event_key,
            signing_key: $signing_key,
            signing_key_fallback: $fallback,
            env: 'v10',
            serve_origin: 'https://example.com',
            serve_path: '/api'
        );
    }

    /**
     * Unauthenticated bodies only say what's configured, not the values
     *
     * @return void
     */
    public function testUnauthenticated(): void {
        $introspector = new Introspector(
            static::config(Mode::Dev, 'key', null, 'event'),
            new FixedVersion()
        );
        $result = $introspector->build(4, false);

        $this->assertNotInstanceOf(AuthenticatedIntrospection::class, $result);
        $this->assertSame(
            '{"extra":{"native_crypto":true},"has_event_key":true,' .
            '"has_signing_key":true,"function_count":4,"mode":"dev",' .
            '"schema_version":"2024-05-24"}',
            $result->toJson()
        );
    }

    /**
     * Authenticated bodies match the JS "authenticated" test (only 12
     * characters of each key hash)
     *
     * @return void
     */
    public function testAuthenticated(): void {
        $introspector = new Introspector(
            static::config(
                Mode::Cloud,
                ServeHarness::SIGNING_KEY,
                ServeHarness::SIGNING_KEY_FALLBACK
            ),
            new FixedVersion('1.2.3')
        );
        $result = $introspector->build(0, true);

        $this->assertJsonDataSame(
            (string)json_encode([
                'api_origin'                => 'https://api.inngest.com/',
                'app_id'                    => 'test',
                'authentication_succeeded'  => true,
                'capabilities'              => ['trust_probe' => 'v1'],
                'env'                       => 'v10',
                'event_api_origin'          => 'https://inn.gs/',
                'event_key_hash'            => null,
                'extra'                     => [
                    'is_streaming'  => false,
                    'native_crypto' => true,
                ],
                'framework'                 => 'php',
                'function_count'            => 0,
                'has_event_key'             => false,
                'has_signing_key'           => true,
                'mode'                      => 'cloud',
                'schema_version'            => '2024-05-24',
                'sdk_language'              => 'php',
                'sdk_version'               => '1.2.3',
                'serve_origin'              => 'https://example.com',
                'serve_path'                => '/api',
                'signing_key_fallback_hash' => '02d449a31fbb',
                'signing_key_hash'          => '66687aadf862',
            ]),
            $result->toJson()
        );
    }

    /**
     * The event key is reported as its SHA-256
     *
     * @return void
     */
    public function testEventKeyHash(): void {
        $introspector = new Introspector(
            static::config(Mode::Cloud, 'key', null, 'event-key-123'),
            new FixedVersion()
        );
        $result = $introspector->build(0, true);

        $this->assertInstanceOf(AuthenticatedIntrospection::class, $result);
        $this->assertSame(
            '5d5a212001221cb2529bf266c37e615a785400000198a0d5b73a8b819603c6a3',
            $result->event_key_hash
        );
        $this->assertNull($result->signing_key_fallback_hash);
    }
}
