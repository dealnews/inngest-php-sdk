<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http\Introspection;

use DealNews\Inngest\Http\Introspection\IntrospectionExtra;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for IntrospectionExtra
 *
 * @package DealNews\Inngest\Tests\Http\Introspection
 */
#[CoversClass(IntrospectionExtra::class)]
#[UsesClass(ValueObject::class)]
class IntrospectionExtraTest extends TestCase {

    /**
     * `is_streaming` is left out unless set
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"native_crypto":true}',
            (new IntrospectionExtra())->toJson()
        );
        $this->assertSame(
            '{"native_crypto":true,"is_streaming":false}',
            (new IntrospectionExtra(is_streaming: false))->toJson()
        );
    }
}
