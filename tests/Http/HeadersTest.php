<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http;

use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Tests\mock\FixedVersion;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Headers
 *
 * @package DealNews\Inngest\Tests\Http
 */
#[CoversClass(Headers::class)]
#[UsesClass(Version::class)]
class HeadersTest extends TestCase {

    /**
     * SDK headers match JS `inngestHeaders()`, minus framework/platform
     *
     * @return void
     */
    public function testSdkHeaders(): void {
        $headers = (new Headers(new FixedVersion()))->sdkHeaders();

        $this->assertSame(
            [
                'Content-Type'          => 'application/json',
                'User-Agent'            => 'dealnews/inngest-php-sdk:v1.2.3',
                'x-inngest-sdk'         => 'dealnews/inngest-php-sdk:v1.2.3',
                'x-inngest-sdk-handled' => 'true',
            ],
            $headers
        );
    }

    /**
     * The environment header is added when an env is set
     *
     * @return void
     */
    public function testEnvHeader(): void {
        $headers = new Headers(new FixedVersion());

        $this->assertSame(
            'branch',
            $headers->sdkHeaders('branch')[Headers::ENVIRONMENT]
        );
        $this->assertArrayNotHasKey(
            Headers::ENVIRONMENT,
            $headers->sdkHeaders('')
        );
    }

    /**
     * Extra headers are added and win over defaults
     *
     * @return void
     */
    public function testExtraHeaders(): void {
        $headers = (new Headers(new FixedVersion()))->sdkHeaders(null, [
            'Content-Type'             => 'text/plain',
            Headers::EVENT_ID_SEED     => 'seed',
        ]);

        $this->assertSame('text/plain', $headers['Content-Type']);
        $this->assertSame('seed', $headers[Headers::EVENT_ID_SEED]);
    }
}
