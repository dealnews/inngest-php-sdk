<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Config\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear environment variables before each test
        putenv('INNGEST_EVENT_KEY');
        putenv('INNGEST_SIGNING_KEY');
        putenv('INNGEST_SIGNING_KEY_FALLBACK');
        putenv('INNGEST_ENV');
        putenv('INNGEST_DEV');
        putenv('INNGEST_API_BASE_URL');
        putenv('INNGEST_EVENT_API_BASE_URL');
        putenv('INNGEST_SERVE_ORIGIN');
        putenv('INNGEST_SERVE_PATH');
        putenv('INNGEST_LOG_LEVEL');
    }

    public function testDefaultConfig(): void
    {
        $config = new Config();

        $this->assertFalse($config->isDev());
        $this->assertSame('https://api.inngest.com', $config->getApiBaseUrl());
        $this->assertSame('https://inn.gs', $config->getEventApiBaseUrl());
    }

    public function testConfigFromConstructor(): void
    {
        $config = new Config(
            event_key: 'test-key',
            signing_key: 'test-signing',
            env: 'production'
        );

        $this->assertSame('test-key', $config->getEventKey());
        $this->assertSame('test-signing', $config->getSigningKey());
        $this->assertSame('production', $config->getEnv());
    }

    public function testConfigFromEnvironment(): void
    {
        putenv('INNGEST_EVENT_KEY=env-key');
        putenv('INNGEST_SIGNING_KEY=env-signing');
        putenv('INNGEST_ENV=staging');

        $config = new Config();

        $this->assertSame('env-key', $config->getEventKey());
        $this->assertSame('env-signing', $config->getSigningKey());
        $this->assertSame('staging', $config->getEnv());
    }

    public function testDevMode(): void
    {
        putenv('INNGEST_DEV=1');

        $config = new Config();

        $this->assertTrue($config->isDev());
        $this->assertSame('http://localhost:8288', $config->getApiBaseUrl());
        $this->assertSame('http://localhost:8288', $config->getEventApiBaseUrl());
    }

    public function testDevModeWithCustomUrl(): void
    {
        putenv('INNGEST_DEV=http://custom-dev:9999');

        $config = new Config();

        $this->assertTrue($config->isDev());
        $this->assertSame('http://custom-dev:9999', $config->getApiBaseUrl());
        $this->assertSame('http://custom-dev:9999', $config->getEventApiBaseUrl());
    }

    public function testConstructorOverridesEnvironment(): void
    {
        putenv('INNGEST_EVENT_KEY=env-key');

        $config = new Config(event_key: 'constructor-key');

        $this->assertSame('constructor-key', $config->getEventKey());
    }

    public function testSigningKeyFallback(): void
    {
        $config = new Config(
            signing_key: 'primary-key',
            signing_key_fallback: 'fallback-key'
        );

        $this->assertSame('primary-key', $config->getSigningKey());
        $this->assertSame('fallback-key', $config->getSigningKeyFallback());
    }

    public function testCustomApiUrls(): void
    {
        $config = new Config(
            api_base_url: 'https://custom-api.example.com',
            event_api_base_url: 'https://custom-events.example.com'
        );

        $this->assertSame('https://custom-api.example.com', $config->getApiBaseUrl());
        $this->assertSame('https://custom-events.example.com', $config->getEventApiBaseUrl());
    }

    public function testServeConfiguration(): void
    {
        $config = new Config(
            serve_origin: 'https://myapp.com',
            serve_path: '/webhooks/inngest'
        );

        $this->assertSame('https://myapp.com', $config->getServeOrigin());
        $this->assertSame('/webhooks/inngest', $config->getServePath());
    }
}
