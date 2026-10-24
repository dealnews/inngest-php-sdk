<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http\Sync;

use DealNews\Inngest\Functions\Config\FunctionConfig;
use DealNews\Inngest\Http\Capabilities;
use DealNews\Inngest\Http\Introspection\IntrospectionExtra;
use DealNews\Inngest\Http\Introspection\UnauthenticatedIntrospection;
use DealNews\Inngest\Http\Sync\InBandRegisterRequest;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for InBandRegisterRequest
 *
 * @package DealNews\Inngest\Tests\Http\Sync
 */
#[CoversClass(InBandRegisterRequest::class)]
#[UsesClass(Capabilities::class)]
#[UsesClass(FunctionConfig::class)]
#[UsesClass(IntrospectionExtra::class)]
#[UsesClass(UnauthenticatedIntrospection::class)]
#[UsesClass(ValueObject::class)]
class InBandRegisterRequestTest extends TestCase {

    /**
     * `env` is always sent (null when unset); `appVersion` only when set
     *
     * @return void
     */
    public function testJson(): void {
        $data = (new InBandRegisterRequest())->toArray();

        $this->assertArrayHasKey('env', $data);
        $this->assertNull($data['env']);
        $this->assertArrayNotHasKey('appVersion', $data);
        $this->assertSame(
            [
                'app_id',
                'capabilities',
                'env',
                'framework',
                'functions',
                'inspection',
                'sdk_author',
                'sdk_language',
                'sdk_version',
                'sdk',
                'url',
            ],
            array_keys($data)
        );

        $data = (new InBandRegisterRequest(app_version: '2'))->toArray();
        $this->assertSame('2', $data['appVersion']);
    }
}
