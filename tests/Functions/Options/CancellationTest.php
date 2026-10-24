<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Options;

use DealNews\Inngest\Functions\Options\Cancellation;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Cancellation
 *
 * Conversion to the wire format is tested in InngestFunctionTest.
 *
 * @package DealNews\Inngest\Tests\Functions\Options
 */
#[CoversClass(Cancellation::class)]
class CancellationTest extends TestCase {

    /**
     * Holds what it's given; everything but the event is optional
     *
     * @return void
     */
    public function testProperties(): void {
        $minimal = new Cancellation('app/cancel');
        $this->assertSame('app/cancel', $minimal->event);
        $this->assertNull($minimal->if);
        $this->assertNull($minimal->match);
        $this->assertNull($minimal->timeout);

        $interval = new \DateInterval('P1D');
        $full     = new Cancellation('e', 'x', 'data.id', $interval);
        $this->assertSame('x', $full->if);
        $this->assertSame('data.id', $full->match);
        $this->assertSame($interval, $full->timeout);
    }
}
