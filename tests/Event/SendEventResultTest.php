<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Event;

use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for SendEventResult
 *
 * @package DealNews\Inngest\Tests\Event
 */
#[CoversClass(SendEventResult::class)]
#[UsesClass(ValueObject::class)]
class SendEventResultTest extends TestCase {

    /**
     * Holds IDs in order, and defaults to none
     *
     * @return void
     */
    public function testIds(): void {
        $this->assertSame([], (new SendEventResult())->ids);
        $this->assertSame(
            '{"ids":["a","b"]}',
            (new SendEventResult(['a', 'b']))->toJson()
        );
    }
}
