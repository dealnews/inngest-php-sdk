<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Options;

use DealNews\Inngest\Functions\Options\Timeouts;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Timeouts
 *
 * @package DealNews\Inngest\Tests\Functions\Options
 */
#[CoversClass(Timeouts::class)]
#[UsesClass(ValueObject::class)]
class TimeoutsTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{}',
            (new Timeouts())->toJson()
        );
        $this->assertSame(
            '{"start":"1m","finish":"1h"}',
            (new Timeouts('1m', '1h'))->toJson()
        );
    }
}
