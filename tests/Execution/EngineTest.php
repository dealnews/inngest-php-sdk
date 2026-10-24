<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\NonRetriableError;
use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Error\RetryAfterError;
use DealNews\Inngest\Error\SerializedError;
use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\ExecutionException;
use DealNews\Inngest\Execution\ExecutionResult;
use DealNews\Inngest\Execution\FoundStep;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\OutgoingOp;
use DealNews\Inngest\Execution\ResultType;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Engine behavior the JS fixtures don't reach
 *
 * Fiber cleanup, misuse of step tools, stalls, and the step loop's order
 * handling. Wire parity is in EngineParityTest.
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(Engine::class)]
#[UsesClass(Context::class)]
#[UsesClass(ErrorSerializer::class)]
#[UsesClass(Event::class)]
#[UsesClass(ExecutionException::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(FoundStep::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(NonRetriableError::class)]
#[UsesClass(OutgoingOp::class)]
#[UsesClass(RemoteError::class)]
#[UsesClass(RetryAfterError::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(SerializedError::class)]
#[UsesClass(StepError::class)]
#[UsesClass(StepIdResolver::class)]
#[UsesClass(StepOp::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(ValueObject::class)]
class EngineTest extends TestCase {

    /**
     * Logger used by the last engine built
     *
     * @var RecordingLogger
     */
    protected RecordingLogger $logger;

    /**
     * Builds an engine for a handler and a request body
     *
     * @param callable             $handler   Handler
     * @param array<string, mixed> $body      Body fields (merged over a
     *                                        default request)
     * @param string|null          $requested Hashed ID of a requested step
     * @param \Closure|null        $failure   on_failure handler
     * @param bool                 $run_failure Run the failure handler
     *
     * @return Engine
     */
    protected function engine(
        callable $handler,
        array $body = [],
        ?string $requested = null,
        ?\Closure $failure = null,
        bool $run_failure = false
    ): Engine {
        $this->logger = new RecordingLogger();
        $function     = new InngestFunction(
            'app',
            new FunctionOptions(id: 'fn', on_failure: $failure),
            $handler
        );

        $request = RunRequest::parse(
            (string)json_encode(array_merge(
                [
                    'event' => ['name' => 'demo/event', 'data' => ['n' => 1]],
                    'steps' => new \stdClass(),
                    'ctx'   => ['run_id' => 'run-1'],
                ],
                $body
            )),
            null
        );

        return new Engine(
            $function,
            $run_failure,
            $request,
            $requested,
            $this->logger
        );
    }

    /**
     * Messages logged at a level
     *
     * @param string $level Level
     *
     * @return list<string>
     */
    protected function logged(string $level): array {
        $messages = [];
        foreach ($this->logger->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = (string)$record['message'];
            }
        }

        return $messages;
    }

    /**
     * A paused handler is unwound before execute() returns: `finally`
     * runs, `catch` doesn't
     *
     * @return void
     */
    public function testPausedFiberUnwoundBeforeReturn(): void {
        $log    = new \ArrayObject();
        $engine = $this->engine(
            static function (Context $ctx) use ($log): void {
                try {
                    $ctx->step->run('A', static fn () => 'A');
                    $log[] = 'after step';
                } catch (\Throwable) {
                    $log[] = 'catch';
                } finally {
                    $log[] = 'finally';
                }
            }
        );

        $result = $engine->execute();

        $this->assertSame(ResultType::StepRan, $result->type);
        $this->assertSame(['finally'], $log->getArrayCopy());
    }

    /**
     * Errors thrown by `finally` blocks during cleanup are logged, not
     * thrown
     *
     * @return void
     */
    public function testFinallyErrorDuringCleanupLogged(): void {
        $engine = $this->engine(static function (Context $ctx): void {
            try {
                $ctx->step->run('A', static fn () => 'A');
            } finally {
                throw new \RuntimeException('cleanup failed');
            }
        });

        $result = $engine->execute();

        $this->assertSame(ResultType::StepRan, $result->type);
        $this->assertSame(
            ['A finally block threw while a paused function was cleaned up'],
            $this->logged('warning')
        );
        $exception = $this->logger->records[0]['context']['exception'];
        $this->assertSame('cleanup failed', $exception->getMessage());
    }

    /**
     * A step reached from a `finally` block during cleanup fails clearly
     *
     * @return void
     */
    public function testStepDuringCleanup(): void {
        $engine = $this->engine(static function (Context $ctx): void {
            try {
                $ctx->step->run('A', static fn () => 'A');
            } finally {
                $ctx->step->run('cleanup', static fn () => null);
            }
        });

        $engine->execute();

        $exception = $this->logger->records[0]['context']['exception'];
        $this->assertInstanceOf(ExecutionException::class, $exception);
        $this->assertSame(7103, $exception->getCode());
    }

    /**
     * Step tools inside a running step fail the step for good, with the
     * JS warning
     *
     * @return void
     */
    public function testNestedStep(): void {
        $engine = $this->engine(static function (Context $ctx): void {
            $ctx->step->run(
                'outer',
                static fn () => $ctx->step->run('inner', static fn () => 1)
            );
        });

        $result = $engine->execute();
        $op     = $result->ops[0];

        $this->assertSame(ResultType::StepRan, $result->type);
        $this->assertSame(OpCode::StepFailed, $op->op);
        $this->assertFalse($result->retriable);
        $this->assertStringContainsString(
            'can\'t be used inside a step',
            (string)$op->error?->message
        );
        $this->assertSame(
            ['Nested step tooling detected in "inner"'],
            $this->logged('warning')
        );
        $this->assertSame(
            Engine::NESTING_STEPS,
            $this->logger->records[0]['context']['code']
        );
    }

    /**
     * Step tools outside the handler's fiber are rejected
     *
     * @return void
     */
    public function testStepOutsideHandler(): void {
        $engine = $this->engine(static fn () => null);

        $this->expectException(ExecutionException::class);
        $this->expectExceptionCode(7101);

        $engine->getContext()->step->run('A', static fn () => 1);
    }

    /**
     * Step tools in a fiber the user started aren't supported
     *
     * @return void
     */
    public function testStepInUserFiber(): void {
        $engine = $this->engine(static function (Context $ctx): mixed {
            $fiber = new \Fiber(
                static fn () => $ctx->step->run('A', static fn () => 1)
            );

            return $fiber->start();
        });

        $result = $engine->execute();

        $this->assertSame(ResultType::FunctionRejected, $result->type);
        $this->assertSame('Step tools can only be used from the function ' .
            'handler', $result->error?->message);
    }

    /**
     * A memoized step missing from the completion order is still resumed,
     * and reusing its ID then warns about parallel indexing
     *
     * @return void
     */
    public function testStepOutsideOrderResumedAndWarns(): void {
        $engine = $this->engine(
            static function (Context $ctx): array {
                return [
                    $ctx->step->run('A', static fn () => 'live'),
                    $ctx->step->run('A', static fn () => 'second'),
                ];
            },
            [
                'steps' => [sha1('A') => ['data' => 'stored']],
                'ctx'   => [
                    'run_id' => 'run-1',
                    'stack'  => ['stack' => ['other'], 'current' => 0],
                ],
            ]
        );

        $result = $engine->execute();

        $this->assertSame(ResultType::StepRan, $result->type);
        $this->assertSame(sha1('A:1'), $result->ops[0]->id);
        $this->assertSame('second', $result->ops[0]->data);
        $this->assertSame(
            ['Duplicate step ID "A" detected across parallel chains'],
            $this->logged('warning')
        );
    }

    /**
     * A step with only input state can't run when immediate execution is
     * off, and can't be reported: the run has stalled
     *
     * @return void
     */
    public function testStalled(): void {
        $engine = $this->engine(
            static fn (Context $ctx) => $ctx->step->run(
                'A',
                static fn (int $a) => $a,
                1
            ),
            [
                'steps' => [sha1('A') => ['input' => [2]]],
                'ctx'   => [
                    'run_id'                      => 'run-1',
                    'disable_immediate_execution' => true,
                ],
            ]
        );

        $this->expectException(ExecutionException::class);
        $this->expectExceptionCode(7102);

        $engine->execute();
    }

    /**
     * Input state that isn't a list is ignored
     *
     * @return void
     */
    public function testInputStateNotAList(): void {
        $engine = $this->engine(
            static fn (Context $ctx) => $ctx->step->run(
                'A',
                static fn (int $a) => $a * 10,
                1
            ),
            ['steps' => [sha1('A') => ['input' => ['a' => 2]]]]
        );

        $result = $engine->execute();

        $this->assertSame(10, $result->ops[0]->data);
        $this->assertSame(['input' => [1]], $result->ops[0]->opts);
    }

    /**
     * A requested step that never shows up is "not found" right away, with
     * the new steps that were found
     *
     * @return void
     */
    public function testRequestedStepNotFound(): void {
        $engine = $this->engine(
            static function (Context $ctx): void {
                $ctx->step->run('A', static fn () => 'A');
                $ctx->step->run(new StepOptions('B', 'Bee'), static fn () => 1);
            },
            ['steps' => [sha1('A') => ['data' => 'A']]],
            sha1('Z')
        );

        $result = $engine->execute();

        $this->assertSame(ResultType::StepNotFound, $result->type);
        $this->assertSame(sha1('Z'), $result->step_id);
        $this->assertSame(
            [['id' => sha1('B'), 'name' => 'B', 'displayName' => 'Bee']],
            $result->found_steps
        );
        $this->assertSame(1, $result->total_found);
    }

    /**
     * Retry rules
     *
     * @param \Throwable  $error    Thrown by the handler
     * @param bool|string $expected Retriable value
     * @param array<string, mixed> $ctx Extra ctx fields
     *
     * @return void
     */
    #[DataProvider('retriabilities')]
    public function testRetriability(
        \Throwable $error,
        bool|string $expected,
        array $ctx = []
    ): void {
        $engine = $this->engine(
            static function () use ($error): void {
                throw $error;
            },
            ['ctx' => array_merge(['run_id' => 'run-1'], $ctx)]
        );

        $result = $engine->execute();

        $this->assertSame(ResultType::FunctionRejected, $result->type);
        $this->assertSame($expected, $result->retriable);
    }

    /**
     * Errors and their retriable values
     *
     * @return array<string, array<int, mixed>>
     */
    public static function retriabilities(): array {
        return [
            'plain'                => [new \RuntimeException('x'), true],
            'non-retriable'        => [new NonRetriableError('x'), false],
            'remote non-retriable' => [
                new RemoteError('NonRetriableError', 'x'),
                false,
            ],
            'remote other'               => [
                new RemoteError('TypeError', 'x'),
                true,
            ],
            'retry after'                => [
                new RetryAfterError('x', '1m'),
                '60',
            ],
            'step error not from a step' => [
                new StepError('A', ['name' => 'Error', 'message' => 'x']),
                true,
            ],
            'last attempt' => [
                new \RuntimeException('x'),
                false,
                ['attempt' => 2, 'max_attempts' => 3],
            ],
            'retry after on last attempt' => [
                new RetryAfterError('x', '1m'),
                false,
                ['attempt' => 0, 'max_attempts' => 1],
            ],
            'max attempts zero' => [
                new \RuntimeException('x'),
                true,
                ['attempt' => 0, 'max_attempts' => 0],
            ],
        ];
    }

    /**
     * The failure handler gets the original error
     *
     * @return void
     */
    public function testFailureHandlerGetsError(): void {
        $engine = $this->engine(
            static fn () => 'main',
            [
                'event' => [
                    'name' => 'inngest/function.failed',
                    'data' => [
                        'error' => [
                            'name'    => 'NonRetriableError',
                            'message' => 'gave up',
                        ],
                    ],
                ],
            ],
            null,
            static fn (Context $ctx) => [
                get_class($ctx->error),
                $ctx->error?->getMessage(),
            ],
            true
        );

        $result = $engine->execute();

        $this->assertSame(
            [NonRetriableError::class, 'gave up'],
            $result->data
        );
    }

    /**
     * Running the failure handler of a function without one is an error
     *
     * @return void
     */
    public function testMissingFailureHandler(): void {
        $engine = $this->engine(static fn () => 'main', [], null, null, true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(7202);

        $engine->execute();
    }

    /**
     * Without a failure handler run, the context has no error
     *
     * @return void
     */
    public function testContext(): void {
        $engine  = $this->engine(
            static fn () => null,
            [
                'events' => [['name' => 'a'], ['name' => 'b']],
                'ctx'    => [
                    'run_id'       => 'run-9',
                    'attempt'      => 1,
                    'max_attempts' => 4,
                ],
            ]
        );
        $context = $engine->getContext();

        $this->assertSame('demo/event', $context->event->name);
        $this->assertCount(2, $context->events);
        $this->assertSame('run-9', $context->run_id);
        $this->assertSame(1, $context->attempt);
        $this->assertSame(4, $context->max_attempts);
        $this->assertSame($this->logger, $context->logger);
        $this->assertNull($context->error);
    }

    /**
     * Steps with state resume in the order they completed
     *
     * @return void
     */
    public function testUsesCompletionOrder(): void {
        $engine = $this->engine(
            static fn (Context $ctx) => [
                $ctx->step->run('A', static fn () => 'live A'),
                $ctx->step->run('B', static fn () => 'live B'),
            ],
            [
                'steps' => [
                    sha1('B') => ['data' => 'B'],
                    sha1('A') => ['data' => 'A'],
                ],
                'ctx' => [
                    'run_id' => 'run-1',
                    'stack'  => [
                        'stack'   => [sha1('B'), sha1('A')],
                        'current' => 1,
                    ],
                ],
            ]
        );

        $result = $engine->execute();

        $this->assertSame(ResultType::FunctionResolved, $result->type);
        $this->assertSame(['A', 'B'], $result->data);
    }

    /**
     * Step timing is recorded in nanoseconds
     *
     * @return void
     */
    public function testTiming(): void {
        $fn  = new InngestFunction(
            'app',
            new FunctionOptions(id: 'fn'),
            static fn (Context $ctx) => $ctx->step->run('A', static fn () => 1)
        );
        $run = RunRequest::parse('{"event":{},"ctx":{"run_id":"r"}}', null);
        $log = new RecordingLogger();

        $engine = new class($fn, false, $run, null, $log) extends Engine {
            /** @var list<int> */
            public array $times = [1000, 1250];

            protected function nowMs(): int {
                return (int)array_shift($this->times);
            }
        };

        $result = $engine->execute();

        $this->assertSame(
            ['a' => 1000000000, 'b' => 250000000],
            $result->ops[0]->timing
        );
    }

    /**
     * The real clock returns milliseconds
     *
     * @return void
     */
    public function testNowMs(): void {
        $fn  = new InngestFunction(
            'app',
            new FunctionOptions(id: 'fn'),
            'time'
        );
        $run = RunRequest::parse('{"event":{}}', null);
        $log = new RecordingLogger();

        $engine = new class($fn, false, $run, null, $log) extends Engine {
            public function now(): int {
                return $this->nowMs();
            }
        };

        $this->assertEqualsWithDelta(
            microtime(true) * 1000,
            $engine->now(),
            5000
        );
    }
}
