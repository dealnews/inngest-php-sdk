<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Api\ApiException;
use DealNews\Inngest\Api\StaleDispatchError;
use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\SerializedError;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Execution\CheckpointConfig;
use DealNews\Inngest\Execution\Checkpointer;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\ExecutionResult;
use DealNews\Inngest\Execution\FoundStep;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\OutgoingOp;
use DealNews\Inngest\Execution\ParallelGroup;
use DealNews\Inngest\Execution\ResultType;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepIdResolver;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Tests\mock\RecordingCheckpointer;
use DealNews\Inngest\Tests\mock\RecordingLogger;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Engine's async checkpointing
 *
 * Covers what the JS fixtures (`cp_*` cases in EngineParityTest) can't:
 * time limits (with a fake clock), checkpoint failures, and cases where
 * PHP differs on purpose.
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(Engine::class)]
#[UsesClass(AiTools::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(CheckpointConfig::class)]
#[UsesClass(Checkpointer::class)]
#[UsesClass(Context::class)]
#[UsesClass(Duration::class)]
#[UsesClass(ErrorSerializer::class)]
#[UsesClass(Event::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(FoundStep::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(OutgoingOp::class)]
#[UsesClass(ParallelGroup::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(SerializedError::class)]
#[UsesClass(StepIdResolver::class)]
#[UsesClass(StepOp::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(ValueObject::class)]
class EngineCheckpointTest extends TestCase {

    /**
     * Checkpointer used by the last engine built
     *
     * @var RecordingCheckpointer
     */
    protected RecordingCheckpointer $checkpointer;

    /**
     * Logger used by the last engine built
     *
     * @var RecordingLogger
     */
    protected RecordingLogger $logger;

    /**
     * Fake clock; `now` is milliseconds
     *
     * @var \ArrayObject<string, int>
     */
    protected \ArrayObject $clock;

    /**
     * Starts the fake clock at 0
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->clock = new \ArrayObject(['now' => 0]);
    }

    /**
     * Builds a checkpointing engine with the fake clock
     *
     * @param callable             $handler Handler
     * @param CheckpointConfig     $config  Checkpoint settings
     * @param array<string, mixed> $body    Body fields
     *
     * @return Engine
     */
    protected function engine(
        callable $handler,
        CheckpointConfig $config = new CheckpointConfig(),
        array $body = []
    ): Engine {
        $this->checkpointer = new RecordingCheckpointer($config);
        $this->logger       = new RecordingLogger();

        $function = new InngestFunction(
            'app',
            new FunctionOptions(id: 'fn'),
            $handler
        );
        $request = RunRequest::parse(
            (string)json_encode(array_merge(
                [
                    'event' => ['name' => 'demo/event', 'data' => []],
                    'steps' => new \stdClass(),
                    'ctx'   => ['run_id' => 'run-1', 'fn_id' => 'f'],
                ],
                $body
            )),
            null
        );

        $args = [
            $function,
            false,
            $request,
            null,
            $this->logger,
            null,
            null,
            $this->checkpointer,
        ];

        $engine = new class(...$args) extends Engine {
            /** @var \ArrayObject<string, int> */
            public \ArrayObject $clock;

            protected function nowMs(): int {
                return $this->clock['now'];
            }
        };
        $engine->clock = $this->clock;

        return $engine;
    }

    /**
     * Op codes and step IDs of a list of ops
     *
     * @param list<OutgoingOp> $ops Ops
     *
     * @return list<string>
     */
    protected static function summary(array $ops): array {
        return array_map(
            static fn (OutgoingOp $op) => $op->op->value . ' ' .
                ($op->userland_id ?? $op->id),
            $ops
        );
    }

    /**
     * Each step is checkpointed and the function keeps going; step data
     * goes through JSON before the function sees it
     *
     * @return void
     */
    public function testRunsToCompletion(): void {
        $engine = $this->engine(static function (Context $ctx): array {
            $a = $ctx->step->run('A', static fn () => (object)['x' => 1]);
            $b = $ctx->step->run('B', static fn () => 1.0);

            return ['a' => $a, 'b' => $b];
        });

        $result = $engine->execute();

        $this->assertSame(ResultType::StepsFound, $result->type);
        $this->assertSame(['RunComplete ' . sha1('complete')], array_map(
            static fn (OutgoingOp $op) => $op->op->value . ' ' . $op->id,
            $result->ops
        ));
        $this->assertSame(
            ['a' => ['x' => 1], 'b' => 1.0],
            $result->ops[0]->data
        );
        $this->assertCount(2, $this->checkpointer->sent);
        $this->assertSame(
            ['StepRun A'],
            static::summary($this->checkpointer->sent[0])
        );
    }

    /**
     * Steps are buffered; the rest go out with RunComplete
     *
     * @return void
     */
    public function testBuffered(): void {
        $engine = $this->engine(
            static function (Context $ctx): void {
                foreach (['A', 'B', 'C'] as $id) {
                    $ctx->step->run($id, static fn () => $id);
                }
            },
            new CheckpointConfig(buffered_steps: 2)
        );

        $result = $engine->execute();

        $this->assertSame(
            [['StepRun A', 'StepRun B']],
            array_map(
                static fn (array $ops) => static::summary($ops),
                $this->checkpointer->sent
            )
        );
        $this->assertSame(
            ['StepRun C', 'RunComplete ' . sha1('complete')],
            static::summary($result->ops)
        );
    }

    /**
     * Past the runtime limit, the next step goes back to Inngest instead of
     * running here
     *
     * @return void
     */
    public function testRuntimeExceeded(): void {
        $clock  = $this->clock;
        $engine = $this->engine(
            static function (Context $ctx) use ($clock): void {
                $ctx->step->run('A', static function () use ($clock) {
                    $clock['now'] = 500;

                    return 'A';
                });
                $ctx->step->run('B', static fn () => 'B');
            },
            new CheckpointConfig(max_runtime_ms: 100)
        );
        $result = $engine->execute();

        $this->assertSame(['StepPlanned B'], static::summary($result->ops));
        $this->assertCount(1, $this->checkpointer->sent);
    }

    /**
     * A runtime limit of 0 means none
     *
     * @return void
     */
    public function testNoRuntimeLimit(): void {
        $engine = $this->engine(
            static fn (Context $ctx) => $ctx->step->run('A', static fn () => 1),
            new CheckpointConfig(max_runtime_ms: 0)
        );
        $this->clock['now'] = PHP_INT_MAX;

        $result = $engine->execute();

        $this->assertSame(OpCode::RunComplete, $result->ops[0]->op);
    }

    /**
     * Buffered steps are sent once the interval passes, even if the
     * buffer isn't full
     *
     * @return void
     */
    public function testIntervalCheckpoint(): void {
        $clock  = $this->clock;
        $engine = $this->engine(
            static function (Context $ctx) use ($clock): void {
                $ctx->step->run('A', static function () use ($clock) {
                    $clock['now'] = 200;

                    return 'A';
                });
                $ctx->step->run('B', static fn () => 'B');
            },
            new CheckpointConfig(buffered_steps: 5, max_interval_ms: 100)
        );
        $result = $engine->execute();

        $this->assertSame(
            [['StepRun A']],
            array_map(
                static fn (array $ops) => static::summary($ops),
                $this->checkpointer->sent
            )
        );
        $this->assertSame(
            ['StepRun B', 'RunComplete ' . sha1('complete')],
            static::summary($result->ops)
        );
    }

    /**
     * A failed interval checkpoint hands the buffered steps back
     *
     * @return void
     */
    public function testIntervalCheckpointFails(): void {
        $clock  = $this->clock;
        $engine = $this->engine(
            static function (Context $ctx) use ($clock): void {
                $ctx->step->run('A', static function () use ($clock) {
                    $clock['now'] = 200;

                    return 'A';
                });
                $ctx->step->run('B', static fn () => 'B');
            },
            new CheckpointConfig(buffered_steps: 5, max_interval_ms: 100)
        );
        $this->checkpointer->error = new \RuntimeException('down');

        $result = $engine->execute();

        $this->assertSame(['StepRun A'], static::summary($result->ops));
    }

    /**
     * A failed checkpoint hands the steps back to Inngest to save
     *
     * @return void
     */
    public function testCheckpointFails(): void {
        $engine = $this->engine(
            static fn (Context $ctx) => $ctx->step->run('A', static fn () => 1)
        );
        $this->checkpointer->error = new \RuntimeException('down');

        $result = $engine->execute();

        $this->assertSame(ResultType::StepsFound, $result->type);
        $this->assertSame(['StepRun A'], static::summary($result->ops));
        $this->assertSame(1, $result->ops[0]->data);
        $this->assertSame(
            'Checkpointing failed; returning steps to Inngest instead',
            $this->logger->records[0]['message']
        );
    }

    /**
     * A stale request stops without retrying
     *
     * @return void
     */
    public function testStaleDispatch(): void {
        $engine = $this->engine(
            static fn (Context $ctx) => $ctx->step->run('A', static fn () => 1)
        );
        $this->checkpointer->error = new StaleDispatchError(
            'Stale dispatch: checkpoint returned 409 (run run-1)',
            6006,
            409
        );

        $result = $engine->execute();

        $this->assertSame(ResultType::FunctionRejected, $result->type);
        $this->assertFalse($result->retriable);
        $this->assertSame('StaleDispatchError', $result->error?->name);
    }

    /**
     * A retry request returns the step instead of continuing
     *
     * @return void
     */
    public function testRetryAttemptReturnsStep(): void {
        $engine = $this->engine(
            static fn (Context $ctx) => $ctx->step->run('A', static fn () => 1),
            new CheckpointConfig(),
            ['ctx' => ['run_id' => 'r', 'fn_id' => 'f', 'attempt' => 1]]
        );

        $result = $engine->execute();

        $this->assertSame(ResultType::StepRan, $result->type);
        $this->assertSame([], $this->checkpointer->sent);
    }

    /**
     * Unsent steps go back along with steps found when the handler returns
     * (JS drops them)
     *
     * @return void
     */
    public function testBufferedWithUnreportedSteps(): void {
        $engine = $this->engine(
            static function (Context $ctx): string {
                $ctx->step->run('A', static fn () => 'A');

                return $ctx->step->race(
                    static fn () => $ctx->step->run('B', static fn () => 'B'),
                    static fn () => 'no step',
                );
            },
            new CheckpointConfig(buffered_steps: 2)
        );

        $result = $engine->execute();

        $this->assertSame(
            ['StepRun A', 'StepPlanned B'],
            static::summary($result->ops)
        );
        $this->assertSame([], $this->checkpointer->sent);
    }

    /**
     * Replaced input runs the step with that input (JS hangs here)
     *
     * @return void
     */
    public function testInputState(): void {
        $engine = $this->engine(
            static fn (Context $ctx) => $ctx->step->run(
                'A',
                static fn (int $a, int $b) => $a + $b,
                1,
                2
            ),
            new CheckpointConfig(),
            ['steps' => [sha1('A') => ['input' => [5, 6]]]]
        );

        $result = $engine->execute();

        $this->assertSame(11, $result->ops[0]->data);
        $this->assertSame(
            ['input' => [5, 6]],
            $this->checkpointer->sent[0][0]->opts
        );
    }

    /**
     * Memoized steps outside the completion order are still resumed
     *
     * @return void
     */
    public function testMemoizedOutsideOrder(): void {
        $engine = $this->engine(
            static fn (Context $ctx) => $ctx->step->run('A', static fn () => 1),
            new CheckpointConfig(),
            [
                'steps' => [sha1('A') => ['data' => 'stored']],
                'ctx'   => [
                    'run_id' => 'r',
                    'fn_id'  => 'f',
                    'stack'  => ['stack' => [], 'current' => 0],
                ],
            ]
        );

        $result = $engine->execute();

        $this->assertSame('stored', $result->ops[0]->data);
        $this->assertSame([], $this->checkpointer->sent);
    }
}
