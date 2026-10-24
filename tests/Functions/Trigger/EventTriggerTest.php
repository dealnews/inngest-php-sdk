<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Trigger;

use DealNews\Inngest\Functions\Trigger\EventTrigger;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for EventTrigger
 *
 * @package DealNews\Inngest\Tests\Functions\Trigger
 */
#[CoversClass(EventTrigger::class)]
#[UsesClass(ValueObject::class)]
class EventTriggerTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"event":"app/x"}',
            (new EventTrigger('app/x'))->toJson()
        );
        $this->assertSame(
            '{"event":"app/x","expression":"event.data.a == 1"}',
            (new EventTrigger('app/x', if: 'event.data.a == 1'))->toJson()
        );
    }

    /**
     * Wire data hydrates into the PHP properties
     *
     * @return void
     */
    public function testFromArray(): void {
        $this->assertSame(
            'x',
            EventTrigger::fromArray(['event' => 'e', 'expression' => 'x'])->if
        );
    }
}
