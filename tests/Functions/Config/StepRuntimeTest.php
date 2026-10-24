<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Config;

use DealNews\Inngest\Functions\Config\StepRuntime;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for StepRuntime
 *
 * @package DealNews\Inngest\Tests\Functions\Config
 */
#[CoversClass(StepRuntime::class)]
#[UsesClass(ValueObject::class)]
class StepRuntimeTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"type":"http","url":""}',
            (new StepRuntime())->toJson()
        );
        $this->assertSame(
            '{"type":"http","url":"https://x/"}',
            (new StepRuntime(url: 'https://x/'))->toJson()
        );
    }
}
