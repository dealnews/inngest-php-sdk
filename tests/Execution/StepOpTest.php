<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for StepOp
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(StepOp::class)]
class StepOpTest extends TestCase {

    /**
     * Holds what a tool asks for
     *
     * @return void
     */
    public function testValues(): void {
        $op = new StepOp('A', OpCode::Sleep, '1h');

        $this->assertSame('A', $op->id);
        $this->assertSame(OpCode::Sleep, $op->op);
        $this->assertSame('1h', $op->name);
        $this->assertNull($op->display_name);
        $this->assertSame([], $op->opts);
    }
}
