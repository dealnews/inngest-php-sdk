<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Options;

use DealNews\Inngest\Functions\Options\RateLimit;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for RateLimit
 *
 * @package DealNews\Inngest\Tests\Functions\Options
 */
#[CoversClass(RateLimit::class)]
#[UsesClass(ValueObject::class)]
class RateLimitTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"limit":1,"period":"1h"}',
            (new RateLimit(1, '1h'))->toJson()
        );
        $this->assertSame(
            '{"limit":1,"period":"1h","key":"k"}',
            (new RateLimit(1, '1h', 'k'))->toJson()
        );
    }
}
