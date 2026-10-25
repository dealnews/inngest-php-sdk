<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Middleware;

use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Middleware\WrapSendEventArgs;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for WrapSendEventArgs
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(WrapSendEventArgs::class)]
#[UsesClass(Event::class)]
#[UsesClass(SendEventResult::class)]
class WrapSendEventArgsTest extends TestCase {

    /**
     * next() runs the rest of the chain
     *
     * @return void
     */
    public function testNext(): void {
        $events = [new Event('a')];
        $result = new SendEventResult(['id-1']);

        $args = new WrapSendEventArgs($events, null, static fn () => $result);

        $this->assertSame($events, $args->events);
        $this->assertNull($args->function);
        $this->assertSame($result, $args->next());
    }
}
