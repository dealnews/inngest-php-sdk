<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Middleware;

use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Middleware\SendEventInput;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for SendEventInput
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(SendEventInput::class)]
#[UsesClass(Event::class)]
class SendEventInputTest extends TestCase {

    /**
     * Holds the events and the sending function
     *
     * @return void
     */
    public function testValues(): void {
        $events = [new Event('a')];

        $input = new SendEventInput($events);

        $this->assertSame($events, $input->events);
        $this->assertNull($input->function);
    }
}
