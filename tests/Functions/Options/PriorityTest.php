<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Options;

use DealNews\Inngest\Functions\Options\Priority;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Priority
 *
 * @package DealNews\Inngest\Tests\Functions\Options
 */
#[CoversClass(Priority::class)]
#[UsesClass(ValueObject::class)]
class PriorityTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{}',
            (new Priority())->toJson()
        );
        $this->assertSame(
            '{"run":"event.data.p"}',
            (new Priority('event.data.p'))->toJson()
        );
    }
}
