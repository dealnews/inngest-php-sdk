<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Step;

use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Step\ParallelMode;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for StepOptions
 *
 * @package DealNews\Inngest\Tests\Step
 */
#[CoversClass(StepOptions::class)]
#[UsesClass(StepOp::class)]
class StepOptionsTest extends TestCase {

    /**
     * Strings become options; options pass through
     *
     * @return void
     */
    public function testFrom(): void {
        $from_string = StepOptions::from('fetch');
        $this->assertSame('fetch', $from_string->id);
        $this->assertNull($from_string->name);

        $options = new StepOptions('fetch', 'Fetch it');
        $this->assertSame($options, StepOptions::from($options));
    }

    /**
     * toOp() fills in the ID, display name, and parallel mode
     *
     * @return void
     */
    public function testToOp(): void {
        $op = (new StepOptions('a'))->toOp(OpCode::Sleep, '1h');
        $this->assertSame('a', $op->id);
        $this->assertSame(OpCode::Sleep, $op->op);
        $this->assertSame('1h', $op->name);
        $this->assertSame('a', $op->display_name);
        $this->assertSame([], $op->opts);
        $this->assertNull($op->parallel_mode);

        $op = (new StepOptions('a', 'Pretty', ParallelMode::Race))
            ->toOp(OpCode::StepPlanned, null, ['type' => 'x']);
        $this->assertNull($op->name);
        $this->assertSame('Pretty', $op->display_name);
        $this->assertSame(['type' => 'x'], $op->opts);
        $this->assertSame(ParallelMode::Race, $op->parallel_mode);
    }
}
