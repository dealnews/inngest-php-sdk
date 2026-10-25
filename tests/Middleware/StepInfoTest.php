<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Middleware;

use DealNews\Inngest\Middleware\StepInfo;
use DealNews\Inngest\Middleware\StepType;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for StepInfo
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(StepInfo::class)]
#[UsesClass(StepOptions::class)]
class StepInfoTest extends TestCase {

    /**
     * Holds what middleware knows about a step
     *
     * @return void
     */
    public function testValues(): void {
        $options = new StepOptions('A', 'Pretty');

        $info = new StepInfo('hash', StepType::Run, $options, true, [1]);

        $this->assertSame('hash', $info->hashed_id);
        $this->assertSame(StepType::Run, $info->step_type);
        $this->assertSame($options, $info->options);
        $this->assertTrue($info->memoized);
        $this->assertSame([1], $info->input);
        $this->assertNull(
            (new StepInfo('h', StepType::Run, $options, false))->input
        );
    }
}
