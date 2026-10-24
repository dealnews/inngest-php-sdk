<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Trigger;

use DealNews\Inngest\Functions\Trigger\CronTrigger;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for CronTrigger
 *
 * @package DealNews\Inngest\Tests\Functions\Trigger
 */
#[CoversClass(CronTrigger::class)]
#[UsesClass(ValueObject::class)]
class CronTriggerTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"cron":"0 * * * *"}',
            (new CronTrigger('0 * * * *'))->toJson()
        );
        $this->assertSame(
            '{"cron":"0 * * * *","jitter":"30s"}',
            (new CronTrigger('0 * * * *', jitter: '30s'))->toJson()
        );
    }
}
