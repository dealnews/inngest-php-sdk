<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Config;

use DealNews\Inngest\Functions\Config\StepRetries;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for StepRetries
 *
 * @package DealNews\Inngest\Tests\Functions\Config
 */
#[CoversClass(StepRetries::class)]
#[UsesClass(ValueObject::class)]
class StepRetriesTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{}',
            (new StepRetries())->toJson()
        );
        $this->assertSame(
            '{"attempts":0}',
            (new StepRetries(0))->toJson()
        );
    }
}
