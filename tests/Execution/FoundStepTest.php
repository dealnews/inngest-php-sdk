<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Execution\FoundStep;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\OutgoingOp;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for FoundStep
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(FoundStep::class)]
#[UsesClass(OutgoingOp::class)]
class FoundStepTest extends TestCase {

    /**
     * Reported and run ops carry the step's identity
     *
     * @return void
     */
    public function testOps(): void {
        $step = new FoundStep(
            id: 'A:1',
            hashed_id: 'hash',
            op: OpCode::StepPlanned,
            name: 'A',
            display_name: 'Pretty',
            opts: ['input' => [1]],
            userland_id: 'A',
            userland_index: 1,
            fn: null,
            fulfilled: false,
            has_step_state: false
        );

        $this->assertFalse($step->handled);
        $this->assertNull($step->fiber);

        $planned = $step->toOp();
        $this->assertSame(OpCode::StepPlanned, $planned->op);
        $this->assertSame('hash', $planned->id);
        $this->assertSame('A', $planned->name);
        $this->assertSame('Pretty', $planned->display_name);
        $this->assertSame(['input' => [1]], $planned->opts);
        $this->assertSame('A', $planned->userland_id);
        $this->assertSame(1, $planned->userland_index);

        $this->assertSame(OpCode::StepRun, $step->toRunOp()->op);
    }
}
