<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Step;

use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepIdResolver;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Tests\mock\RecordingLogger;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for StepTools (op shapes; running steps is in the engine tests)
 *
 * @package DealNews\Inngest\Tests\Step
 */
#[CoversClass(StepTools::class)]
#[UsesClass(Context::class)]
#[UsesClass(Engine::class)]
#[UsesClass(ErrorSerializer::class)]
#[UsesClass(Event::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(StepIdResolver::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepOp::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(ValueObject::class)]
class StepToolsTest extends TestCase {

    /**
     * Builds tools on an engine that records step calls
     *
     * @return array{StepTools, \ArrayObject<int, array<int, mixed>>}
     */
    protected function tools(): array {
        $calls  = new \ArrayObject();
        $fn     = new InngestFunction(
            'app',
            new FunctionOptions(id: 'fn'),
            'time'
        );
        $run = RunRequest::parse('{"event":{}}', null);
        $log = new RecordingLogger();

        $engine = new class($fn, false, $run, null, $log) extends Engine {
            /** @var \ArrayObject<int, array<int, mixed>> */
            public \ArrayObject $calls;

            public function step(
                StepOp $op,
                ?\Closure $fn = null,
                array $args = []
            ): mixed {
                $this->calls[] = [$op, $fn, $args];

                return $fn === null ? null : $fn(...$args);
            }
        };
        $engine->calls = $calls;

        return [new StepTools($engine), $calls];
    }

    /**
     * `run` plans a step named after its ID
     *
     * @return void
     */
    public function testRun(): void {
        [$tools, $calls] = $this->tools();

        $this->assertSame('ran', $tools->run('A', static fn () => 'ran'));

        [$op, , $args] = $calls[0];
        $this->assertSame('A', $op->id);
        $this->assertSame(OpCode::StepPlanned, $op->op);
        $this->assertSame('A', $op->name);
        $this->assertSame('A', $op->display_name);
        $this->assertSame([], $op->opts);
        $this->assertSame([], $args);
    }

    /**
     * `run` with options and input
     *
     * @return void
     */
    public function testRunWithOptionsAndInput(): void {
        [$tools, $calls] = $this->tools();

        $result = $tools->run(
            new StepOptions('A', 'Add'),
            static fn (int $a, int $b) => $a + $b,
            ...['a' => 1, 'b' => 2]
        );

        [$op, , $args] = $calls[0];
        $this->assertSame(3, $result);
        $this->assertSame('Add', $op->display_name);
        $this->assertSame('A', $op->name);
        $this->assertSame(['input' => [1, 2]], $op->opts);
        $this->assertSame([1, 2], $args);
    }

    /**
     * Any callable works
     *
     * @return void
     */
    public function testRunCallable(): void {
        [$tools] = $this->tools();

        $this->assertSame('ABC', $tools->run('A', 'strtoupper', 'abc'));
    }
}
