<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Http\CheckpointBuffer;
use PHPUnit\Framework\TestCase;

class CheckpointBufferTest extends TestCase
{
    public function testEmptyBufferNeverFlushes(): void
    {
        $buffer = new CheckpointBuffer(0, null);
        $this->assertTrue($buffer->isEmpty());
        $this->assertFalse($buffer->shouldFlush());
    }

    public function testBufferedStepsZeroFlushesAfterEveryStep(): void
    {
        $buffer = new CheckpointBuffer(0, null);
        $buffer->add(['id' => 'a', 'op' => 'StepRun']);
        $this->assertTrue($buffer->shouldFlush());
    }

    public function testBufferedStepsOneFlushesAfterEveryStep(): void
    {
        $buffer = new CheckpointBuffer(1, null);
        $buffer->add(['id' => 'a', 'op' => 'StepRun']);
        $this->assertTrue($buffer->shouldFlush());
    }

    public function testBufferedStepsThousandDoesNotFlushBeforeThreshold(): void
    {
        $buffer = new CheckpointBuffer(1000, null);
        for ($i = 0; $i < 999; $i++) {
            $buffer->add(['id' => "step-{$i}", 'op' => 'StepRun']);
            $this->assertFalse($buffer->shouldFlush());
        }
    }

    public function testBufferedStepsThousandFlushesAtThreshold(): void
    {
        $buffer = new CheckpointBuffer(1000, null);
        for ($i = 0; $i < 999; $i++) {
            $buffer->add(['id' => "step-{$i}", 'op' => 'StepRun']);
        }
        $buffer->add(['id' => 'step-999', 'op' => 'StepRun']);
        $this->assertTrue($buffer->shouldFlush());
    }

    public function testMaxIntervalTriggersFlushViaInjectedClock(): void
    {
        $now = 1000;
        $clock = function () use (&$now): int {
            return $now;
        };

        $buffer = new CheckpointBuffer(1000, 3000, $clock);
        $buffer->add(['id' => 'a', 'op' => 'StepRun']);
        $this->assertFalse($buffer->shouldFlush());

        $now += 2999;
        $this->assertFalse($buffer->shouldFlush());

        $now += 1;
        $this->assertTrue($buffer->shouldFlush());
    }

    public function testMaxIntervalTimerRestartsAfterDrain(): void
    {
        $now = 0;
        $clock = function () use (&$now): int {
            return $now;
        };

        $buffer = new CheckpointBuffer(1000, 100, $clock);
        $buffer->add(['id' => 'a', 'op' => 'StepRun']);

        $now = 150;
        $this->assertTrue($buffer->shouldFlush());

        $buffer->drain();
        $now = 200;
        $buffer->add(['id' => 'b', 'op' => 'StepRun']);
        $this->assertFalse($buffer->shouldFlush());

        $now = 301;
        $this->assertTrue($buffer->shouldFlush());
    }

    public function testDrainEmptiesBufferAndReturnsSteps(): void
    {
        $buffer = new CheckpointBuffer(1000, null);
        $buffer->add(['id' => 'a', 'op' => 'StepRun']);
        $buffer->add(['id' => 'b', 'op' => 'StepRun']);

        $drained = $buffer->drain();

        $this->assertSame([
            ['id' => 'a', 'op' => 'StepRun'],
            ['id' => 'b', 'op' => 'StepRun'],
        ], $drained);
        $this->assertTrue($buffer->isEmpty());
        $this->assertFalse($buffer->shouldFlush());
    }

    public function testPeekIsNonDestructive(): void
    {
        $buffer = new CheckpointBuffer(1000, null);
        $buffer->add(['id' => 'a', 'op' => 'StepRun']);

        $this->assertSame([['id' => 'a', 'op' => 'StepRun']], $buffer->peek());
        $this->assertFalse($buffer->isEmpty());
        $this->assertSame([['id' => 'a', 'op' => 'StepRun']], $buffer->peek());
    }
}
