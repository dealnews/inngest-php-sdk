<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Options;

use DealNews\Inngest\Functions\Options\BatchEvents;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for BatchEvents
 *
 * @package DealNews\Inngest\Tests\Functions\Options
 */
#[CoversClass(BatchEvents::class)]
#[UsesClass(ValueObject::class)]
class BatchEventsTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"maxSize":10,"timeout":"1s"}',
            (new BatchEvents(10, '1s'))->toJson()
        );
        $this->assertSame(
            '{"maxSize":10,"timeout":"1s","key":"k","if":"i"}',
            (new BatchEvents(10, '1s', 'k', 'i'))->toJson()
        );
    }

    /**
     * Wire data hydrates into the PHP properties
     *
     * @return void
     */
    public function testFromArray(): void {
        $this->assertSame(
            3,
            BatchEvents::fromArray([
                'maxSize' => 3,
                'timeout' => '2s',
            ])->max_size
        );
    }
}
