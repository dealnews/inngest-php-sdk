<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Options;

use DealNews\Inngest\Functions\Options\Debounce;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Debounce
 *
 * @package DealNews\Inngest\Tests\Functions\Options
 */
#[CoversClass(Debounce::class)]
#[UsesClass(ValueObject::class)]
class DebounceTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"period":"5m"}',
            (new Debounce('5m'))->toJson()
        );
        $this->assertSame(
            '{"period":"5m","key":"k","timeout":"1h"}',
            (new Debounce('5m', 'k', '1h'))->toJson()
        );
    }
}
