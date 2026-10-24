<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http\Sync;

use DealNews\Inngest\Functions\Config\FunctionConfig;
use DealNews\Inngest\Http\Capabilities;
use DealNews\Inngest\Http\Sync\RegisterRequest;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for RegisterRequest
 *
 * @package DealNews\Inngest\Tests\Http\Sync
 */
#[CoversClass(RegisterRequest::class)]
#[UsesClass(Capabilities::class)]
#[UsesClass(FunctionConfig::class)]
#[UsesClass(ValueObject::class)]
class RegisterRequestTest extends TestCase {

    /**
     * Uses JS wire keys and leaves out unset optional fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"url":"","deployType":"ping","framework":"","appName":"",' .
            '"functions":[],"sdk":"","v":"0.1",' .
            '"capabilities":{"trust_probe":"v1"}}',
            (new RegisterRequest())->toJson()
        );

        $request = new RegisterRequest(
            functions: [new FunctionConfig(id: 'a')],
            deploy_id: 'dep',
            app_version: '1.0'
        );
        $data = $request->toArray();
        $this->assertSame('dep', $data['deployId']);
        $this->assertSame('1.0', $data['appVersion']);
        $this->assertSame('a', $data['functions'][0]['id']);
    }

    /**
     * Hydrates function configs from wire data
     *
     * @return void
     */
    public function testFromArray(): void {
        $request = RegisterRequest::fromArray([
            'appName'   => 'app',
            'functions' => [['id' => 'app-fn']],
        ]);

        $this->assertSame('app', $request->app_name);
        $this->assertInstanceOf(FunctionConfig::class, $request->functions[0]);
    }
}
