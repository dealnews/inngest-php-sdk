<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http\Introspection;

use DealNews\Inngest\Http\Introspection\IntrospectionExtra;
use DealNews\Inngest\Http\Introspection\UnauthenticatedIntrospection;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for UnauthenticatedIntrospection
 *
 * @package DealNews\Inngest\Tests\Http\Introspection
 */
#[CoversClass(UnauthenticatedIntrospection::class)]
#[UsesClass(IntrospectionExtra::class)]
#[UsesClass(ValueObject::class)]
class UnauthenticatedIntrospectionTest extends TestCase {

    /**
     * Encodes to the JS wire shape
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"extra":{"native_crypto":true},"has_event_key":false,' .
            '"has_signing_key":false,"function_count":0,"mode":"dev",' .
            '"schema_version":"2024-05-24"}',
            (new UnauthenticatedIntrospection())->toJson()
        );
    }
}
