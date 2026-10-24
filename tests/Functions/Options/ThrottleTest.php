<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Options;

use DealNews\Inngest\Functions\Options\Throttle;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Throttle
 *
 * @package DealNews\Inngest\Tests\Functions\Options
 */
#[CoversClass(Throttle::class)]
#[UsesClass(ValueObject::class)]
class ThrottleTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"limit":1,"period":"1s"}',
            (new Throttle(1, '1s'))->toJson()
        );
        $this->assertSame(
            '{"limit":1,"period":"1s","key":"k","burst":2}',
            (new Throttle(1, '1s', 'k', 2))->toJson()
        );
    }
}
