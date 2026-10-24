<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Error\SerializedError;
use DealNews\Inngest\Execution\ExecutionResult;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\OutgoingOp;
use DealNews\Inngest\Execution\ResultType;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for ExecutionResult
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(ExecutionResult::class)]
#[UsesClass(OutgoingOp::class)]
class ExecutionResultTest extends TestCase {

    /**
     * Named constructors set the type and their fields
     *
     * @return void
     */
    public function testConstructors(): void {
        $op    = new OutgoingOp('a', OpCode::StepRun);
        $error = new SerializedError();

        $resolved = ExecutionResult::resolved(['x' => 1]);
        $this->assertSame(ResultType::FunctionResolved, $resolved->type);
        $this->assertSame(['x' => 1], $resolved->data);

        $rejected = ExecutionResult::rejected($error, '30');
        $this->assertSame(ResultType::FunctionRejected, $rejected->type);
        $this->assertSame($error, $rejected->error);
        $this->assertSame('30', $rejected->retriable);

        $ran = ExecutionResult::stepRan($op);
        $this->assertSame(ResultType::StepRan, $ran->type);
        $this->assertSame([$op], $ran->ops);
        $this->assertNull($ran->retriable);
        $this->assertFalse(ExecutionResult::stepRan($op, false)->retriable);

        $found = ExecutionResult::stepsFound([$op, $op]);
        $this->assertSame(ResultType::StepsFound, $found->type);
        $this->assertCount(2, $found->ops);

        $missing = ExecutionResult::stepNotFound('z', [['id' => 'b']], 3);
        $this->assertSame(ResultType::StepNotFound, $missing->type);
        $this->assertSame('z', $missing->step_id);
        $this->assertSame([['id' => 'b']], $missing->found_steps);
        $this->assertSame(3, $missing->total_found);
    }
}
