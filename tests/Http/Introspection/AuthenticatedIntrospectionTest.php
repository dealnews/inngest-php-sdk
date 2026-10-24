<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http\Introspection;

use DealNews\Inngest\Http\Capabilities;
use DealNews\Inngest\Http\Introspection\AuthenticatedIntrospection;
use DealNews\Inngest\Http\Introspection\IntrospectionExtra;
use DealNews\Inngest\Http\Introspection\UnauthenticatedIntrospection;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for AuthenticatedIntrospection
 *
 * @package DealNews\Inngest\Tests\Http\Introspection
 */
#[CoversClass(AuthenticatedIntrospection::class)]
#[UsesClass(Capabilities::class)]
#[UsesClass(IntrospectionExtra::class)]
#[UsesClass(UnauthenticatedIntrospection::class)]
#[UsesClass(ValueObject::class)]
class AuthenticatedIntrospectionTest extends TestCase {

    /**
     * Unset optional values are sent as null, not left out
     *
     * @return void
     */
    public function testKeepsNulls(): void {
        $introspection               = new AuthenticatedIntrospection();
        $introspection->capabilities = new Capabilities();

        $data = $introspection->toArray();

        foreach (
            [
                'env',
                'event_key_hash',
                'serve_origin',
                'serve_path',
                'signing_key_fallback_hash',
                'signing_key_hash',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $data);
            $this->assertNull($data[$key]);
        }
        $this->assertTrue($data['authentication_succeeded']);
        $this->assertSame(['trust_probe' => 'v1'], $data['capabilities']);
    }
}
