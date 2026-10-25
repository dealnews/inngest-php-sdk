<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

use DealNews\Inngest\Client;
use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\NonRetriableError;
use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Error\RetryAfterError;
use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Step\StepTools;
use Psr\Log\LoggerInterface;

/**
 * Runs one request's worth of a function
 *
 * Port of the async step mode of the JS execution engine
 * (components/execution/engine.ts). The JS engine reruns the handler from
 * the top on every request and pauses it on a promise that never resolves
 * when it reaches a step that hasn't run. Here the handler runs in a
 * Fiber, and a step tool suspends the fiber instead.
 *
 * Each request:
 *
 * 1. Run the handler until it returns, throws, or reaches a step.
 * 2. Resume steps that already have results from Inngest, one at a time,
 *    in the order they originally completed (`ctx.stack`).
 * 3. When nothing else can be resumed, decide (like JS `steps-found`):
 *    run the requested step, run the one new step right away, or report
 *    the new steps so Inngest can plan them.
 *
 * Usage:
 *
 * ```php
 * $engine = new Engine(
 *     $function, false, $run_request, null, $logger, null, $client
 * );
 * $result = $engine->execute();
 * ```
 *
 * Edge cases:
 *
 * - Fibers left paused at a step are destroyed before execute() returns.
 *   PHP unwinds them: `finally` blocks run, `catch` blocks don't. Errors
 *   thrown by those `finally` blocks are logged and ignored.
 * - When the requested step can't be found and nothing else can run, the
 *   engine answers "step not found" right away (JS waits 10 seconds,
 *   because async code might still find it).
 * - Step tools called inside a running step throw a NonRetriableError
 *   (JS only warns, then waits forever).
 *
 * @package DealNews\Inngest\Execution
 */
class Engine {

    /**
     * Step ID used for the run-complete op
     *
     * @var string
     */
    public const RUN_COMPLETE_STEP_ID = 'complete';

    /**
     * Most found steps reported in a "step not found" response
     *
     * @var int
     */
    public const MAX_FOUND_STEPS = 25;

    /**
     * Warning code for step tools used inside a step
     *
     * @var string
     */
    public const NESTING_STEPS = 'NESTING_STEPS';

    /**
     * Warning code for repeated step IDs across parallel branches
     *
     * @var string
     */
    public const AUTOMATIC_PARALLEL_INDEXING = 'AUTOMATIC_PARALLEL_INDEXING';

    /**
     * Context passed to the handler
     *
     * @var Context
     */
    protected Context $context;

    /**
     * Serializes errors for the wire
     *
     * @var ErrorSerializer
     */
    protected ErrorSerializer $serializer;

    /**
     * Assigns step IDs
     *
     * @var StepIdResolver
     */
    protected StepIdResolver $ids;

    /**
     * Every step found in this request, by hashed ID (JS `state.steps`)
     *
     * @var array<string, FoundStep>
     */
    protected array $steps = [];

    /**
     * Steps found since the last report (JS `foundStepsToReport`)
     *
     * @var array<string, FoundStep>
     */
    protected array $found = [];

    /**
     * Found steps not yet handled (JS `unhandledFoundStepsToReport`)
     *
     * @var array<string, FoundStep>
     */
    protected array $unhandled = [];

    /**
     * Hashed IDs of steps with results, in the order they completed, not
     * yet resumed
     *
     * @var list<string>
     */
    protected array $remaining_order;

    /**
     * Step currently running its code
     *
     * @var FoundStep|null
     */
    protected ?FoundStep $executing_step = null;

    /**
     * StepError most recently thrown into the handler
     *
     * An uncaught step error isn't retried: the step already used its
     * retries.
     *
     * @var StepError|null
     */
    protected ?StepError $recently_rejected = null;

    /**
     * Whether the parallel indexing warning was logged
     *
     * @var bool
     */
    protected bool $warned_parallel_indexing = false;

    /**
     * Fibers running handler code; weak, so they never keep a fiber alive
     *
     * @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, true>
     */
    protected \WeakMap $handler_fibers;

    /**
     * Every fiber created, so they can be released in a controlled way
     *
     * @var list<\Fiber<mixed, mixed, mixed, mixed>>
     */
    protected array $fibers = [];

    /**
     * The fiber running the handler itself
     *
     * @var \Fiber<mixed, mixed, mixed, mixed>|null
     */
    protected ?\Fiber $main = null;

    /**
     * Whether the handler returned
     *
     * @var bool
     */
    protected bool $returned = false;

    /**
     * What the handler returned
     *
     * @var mixed
     */
    protected mixed $return_value = null;

    /**
     * What the handler threw
     *
     * @var \Throwable|null
     */
    protected ?\Throwable $thrown = null;

    /**
     * Whether this request is over (no more steps may be found)
     *
     * @var bool
     */
    protected bool $done = false;

    /**
     * Creates the engine
     *
     * @param InngestFunction      $function       Function to run
     * @param bool                 $failure        Run the `on_failure`
     *                                             handler
     * @param RunRequest           $request        Parsed request
     * @param string|null          $requested_step Hashed ID of the step
     *                                             Inngest wants run
     * @param LoggerInterface      $logger         Logger
     * @param ErrorSerializer|null $serializer     Error serializer
     * @param Client|null          $client         Client, for step tools
     *                                             that send events, signals,
     *                                             or HTTP requests
     */
    public function __construct(
        protected InngestFunction $function,
        protected bool $failure,
        protected RunRequest $request,
        protected ?string $requested_step,
        protected LoggerInterface $logger,
        ?ErrorSerializer $serializer = null,
        protected ?Client $client = null
    ) {
        $this->serializer      = $serializer ?? new ErrorSerializer();
        $this->ids             = new StepIdResolver();
        $this->handler_fibers  = new \WeakMap();
        $this->remaining_order = $request->stack;
        $this->context         = $this->createContext();
    }

    /**
     * Returns the context passed to the handler
     *
     * @return Context
     */
    public function getContext(): Context {
        return $this->context;
    }

    /**
     * Whether a step's code is running right now
     *
     * @return bool
     */
    public function isExecutingStep(): bool {
        return $this->executing_step !== null;
    }

    /**
     * Runs the function until this request's work is done
     *
     * @return ExecutionResult
     */
    public function execute(): ExecutionResult {
        try {
            $result = $this->run();
        } finally {
            $this->releaseFibers();
        }

        return $result;
    }

    /**
     * Handles a step tool call (JS `stepHandler`)
     *
     * Called from inside the handler's fiber. Registers the step, then
     * suspends until the engine resumes it with a result (or throws its
     * error). If the engine never resumes it, this call never returns.
     *
     * @param StepOp        $op   What the tool wants
     * @param \Closure|null $fn   Code to run for the step (step.run)
     * @param list<mixed>   $args Arguments for that code
     *
     * @throws NonRetriableError  When called inside a running step
     * @throws ExecutionException When called outside the handler, or after
     *                            the request is over
     * @throws StepError          When the step failed for good
     *
     * @return mixed The step's result
     */
    public function step(
        StepOp $op,
        ?\Closure $fn = null,
        array $args = []
    ): mixed {
        $this->assertCanStep($op);

        [$id, $index] = $this->ids->resolve($op->id, $this->steps);
        if ($index !== null) {
            $this->maybeWarnParallelIndexing($op->id);
        }

        $hashed_id = StepIdResolver::hash($id);
        $state     = $this->request->steps[$hashed_id] ?? null;
        $opts      = $op->opts;

        // Inngest can replace a step's input before it runs.
        if (
            $state !== null                        &&
            $state->type === StepState::TYPE_INPUT &&
            is_array($state->input)                &&
            array_is_list($state->input)
        ) {
            if ($op->op === OpCode::StepPlanned) {
                $args          = $state->input;
                $opts['input'] = $state->input;
            } elseif ($op->op === OpCode::AiGateway) {
                $opts['body'] = static::replaceBody(
                    $opts['body']    ?? null,
                    $state->input[0] ?? null
                );
            }
        }

        $step = new FoundStep(
            id: $id,
            hashed_id: $hashed_id,
            op: $op->op,
            name: $op->name,
            display_name: $op->display_name ?? $op->id,
            opts: $opts,
            userland_id: $op->id,
            userland_index: $index,
            fn: $fn === null ? null : static fn () => $fn(...$args),
            fulfilled: $state?->isFulfilled() ?? false,
            has_step_state: $state !== null
        );
        $step->fiber = \Fiber::getCurrent();

        $this->steps[$hashed_id]     = $step;
        $this->found[$hashed_id]     = $step;
        $this->unhandled[$hashed_id] = $step;

        return \Fiber::suspend($step);
    }

    /**
     * Runs the handler and the step loop
     *
     * @return ExecutionResult
     */
    protected function run(): ExecutionResult {
        $handler = $this->handler();
        $context = $this->context;
        $main    = new \Fiber(static fn () => $handler($context));

        $this->main                  = $main;
        $this->fibers[]              = $main;
        $this->handler_fibers[$main] = true;

        $this->drive($main, static fn () => $main->start());
        unset($main);

        $result = null;
        while ($result === null) {
            if ($this->returned) {
                $result = $this->resolved();
            } elseif ($this->thrown !== null) {
                $result = $this->rejected($this->thrown);
            } else {
                $next = $this->nextToHandle();
                if ($next !== null) {
                    $this->handle($next);
                } else {
                    $result = $this->rollUp();
                }
            }
        }

        return $result;
    }

    /**
     * Starts or resumes a fiber and records how the handler ended
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber  Fiber
     * @param \Closure                           $action Starts, resumes, or
     *                                                   throws into it
     *
     * @return void
     */
    protected function drive(\Fiber $fiber, \Closure $action): void {
        try {
            $action();
            if ($fiber->isTerminated() && $fiber === $this->main) {
                $this->returned     = true;
                $this->return_value = $fiber->getReturn();
            }
        } catch (\Throwable $e) {
            $this->thrown = $e;
        }
    }

    /**
     * Returns the next found step to resume, in completion order
     *
     * @return FoundStep|null
     */
    protected function nextToHandle(): ?FoundStep {
        $result = null;

        foreach ($this->remaining_order as $position => $hashed_id) {
            $step = $this->unhandled[$hashed_id] ?? null;
            if ($step !== null && !$step->handled) {
                unset($this->unhandled[$hashed_id]);
                array_splice($this->remaining_order, $position, 1);
                $result = $step;
                break;
            }
        }

        return $result;
    }

    /**
     * Resumes a step's fiber with its stored result or error
     *
     * @param FoundStep $step Step
     *
     * @return void
     */
    protected function handle(FoundStep $step): void {
        $step->handled = true;
        $state         = $this->request->steps[$step->hashed_id] ?? null;
        $fiber         = $step->fiber;

        if ($step->fulfilled && $state !== null && $fiber !== null) {
            if ($state->type === StepState::TYPE_DATA) {
                $this->drive(
                    $fiber,
                    static fn () => $fiber->resume($state->data)
                );
            } else {
                $error = new StepError($step->id, $state->error);

                $this->recently_rejected = $error;
                $this->drive($fiber, static fn () => $fiber->throw($error));
            }
        }
    }

    /**
     * Reports everything found since the last report (JS `steps-found`)
     *
     * @throws ExecutionException When the run can't make progress
     *
     * @return ExecutionResult|null Null to keep going
     */
    protected function rollUp(): ?ExecutionResult {
        $steps           = array_values($this->found);
        $this->found     = [];
        $this->unhandled = [];

        $result = null;
        if ($steps !== []) {
            $op     = $this->tryExecuteStep($steps);
            $result = $op === null ?
                $this->newStepsResult($steps) :
                $this->stepRan($op);
        }

        return $result ?? $this->stalled();
    }

    /**
     * Decides what to do when nothing more can happen
     *
     * @throws ExecutionException When there's nothing left to try (7102)
     *
     * @return ExecutionResult|null Null after resuming a step
     */
    protected function stalled(): ?ExecutionResult {
        $result = null;

        if ($this->requested_step !== null) {
            $result = $this->stepNotFound();
        } else {
            // A step with a result that isn't in the completion order would
            // never be resumed (JS waits forever); resume it anyway.
            $waiting = null;
            foreach ($this->steps as $step) {
                if ($step->fulfilled && !$step->handled) {
                    $waiting = $step;
                    break;
                }
            }

            if ($waiting === null) {
                throw new ExecutionException(
                    'The function is waiting on steps that already have ' .
                    'state but can\'t be resumed',
                    7102
                );
            }

            $this->handle($waiting);
        }

        return $result;
    }

    /**
     * Runs the requested step, or the only new step when allowed
     *
     * @param list<FoundStep> $steps Steps found in this tick
     *
     * @return OutgoingOp|null The step's op, or null when nothing ran
     */
    protected function tryExecuteStep(array $steps): ?OutgoingOp {
        $target = $this->requested_step ?? $this->earlyExecutionStep($steps);
        $result = null;

        if ($target !== null) {
            foreach ($steps as $step) {
                if ($step->hashed_id === $target && $step->fn !== null) {
                    $result = $this->executeStep($step);
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Picks a step to run right away instead of reporting it
     *
     * Only when immediate execution is allowed, exactly one found step is
     * unfulfilled, it isn't part of a parallel group, and it's a planned
     * step (step.run).
     *
     * @param list<FoundStep> $steps Steps found in this tick
     *
     * @return string|null Hashed step ID
     */
    protected function earlyExecutionStep(array $steps): ?string {
        $result = null;

        if (!$this->request->disable_immediate_execution) {
            $unfulfilled = array_values(array_filter(
                $steps,
                static fn (FoundStep $step) => !$step->fulfilled
            ));

            if (
                count($unfulfilled) === 1                       &&
                !isset($unfulfilled[0]->opts['parallelMode'])   &&
                $unfulfilled[0]->op === OpCode::StepPlanned
            ) {
                $result = $unfulfilled[0]->hashed_id;
            }
        }

        return $result;
    }

    /**
     * Builds the "new steps found" result (JS `filterNewSteps`)
     *
     * @param list<FoundStep> $steps Candidate steps
     *
     * @return ExecutionResult|null Null when there are none, or when
     *                              Inngest asked for a specific step
     */
    protected function newStepsResult(array $steps): ?ExecutionResult {
        $result = null;

        if ($this->requested_step === null) {
            $ops = [];
            foreach ($steps as $step) {
                if (!$step->has_step_state) {
                    $ops[] = $step->toOp();
                }
            }

            if ($ops !== []) {
                $result = ExecutionResult::stepsFound($ops);
            }
        }

        return $result;
    }

    /**
     * Runs a step's code and builds its op (JS `executeStep`)
     *
     * @param FoundStep $step Step
     *
     * @return OutgoingOp
     */
    protected function executeStep(FoundStep $step): OutgoingOp {
        $op    = $step->toRunOp();
        $fn    = $step->fn;
        $start = $this->nowMs();

        $this->executing_step = $step;
        try {
            $op->withData($fn === null ? null : $fn());
        } catch (\Throwable $e) {
            $op->thrown = $e;
            $op->op     = $this->retriability($e) === false ?
                OpCode::StepFailed :
                OpCode::StepError;
        } finally {
            $this->executing_step = null;
        }

        $end        = $this->nowMs();
        $op->timing = [
            'a' => $start          * 1000000,
            'b' => ($end - $start) * 1000000,
        ];

        return $op;
    }

    /**
     * Builds the result for a step that ran (JS `stepRanHandler`)
     *
     * @param OutgoingOp $op The step's op
     *
     * @return ExecutionResult
     */
    protected function stepRan(OutgoingOp $op): ExecutionResult {
        $retriable = null;

        if ($op->thrown !== null) {
            $retriable = $this->retriability($op->thrown);
            $op->error = $this->serializer->serialize($op->thrown);

            if ($op->op === OpCode::StepFailed) {
                $op->withData([
                    '__serialized' => true,
                    'name'         => $op->error->name,
                    'message'      => $op->error->message,
                    'stack'        => '',
                ]);
            }
        }

        return ExecutionResult::stepRan($op, $retriable);
    }

    /**
     * Builds the result for a handler that returned
     *
     * Steps found but never run or reported (possible with parallel
     * branches) are reported instead.
     *
     * @return ExecutionResult
     */
    protected function resolved(): ExecutionResult {
        return $this->newStepsResult(array_values($this->steps)) ??
            ExecutionResult::resolved($this->return_value);
    }

    /**
     * Builds the result for a handler that threw
     *
     * @param \Throwable $error Error
     *
     * @return ExecutionResult
     */
    protected function rejected(\Throwable $error): ExecutionResult {
        return ExecutionResult::rejected(
            $this->serializer->serialize($error),
            $this->retriability($error)
        );
    }

    /**
     * Builds the "requested step not found" result
     *
     * @return ExecutionResult
     */
    protected function stepNotFound(): ExecutionResult {
        $found = [];
        foreach ($this->steps as $step) {
            if (!$step->has_step_state) {
                $found[] = array_filter(
                    [
                        'id'          => $step->hashed_id,
                        'name'        => $step->name,
                        'displayName' => $step->display_name,
                    ],
                    static fn ($value) => $value !== null
                );
            }
        }

        usort(
            $found,
            static fn (array $a, array $b) => strcmp($a['id'], $b['id'])
        );

        return ExecutionResult::stepNotFound(
            (string)$this->requested_step,
            array_slice($found, 0, self::MAX_FOUND_STEPS),
            count($found)
        );
    }

    /**
     * Decides whether an error should be retried (JS `retriability`)
     *
     * @param \Throwable $error Error
     *
     * @return bool|string False to stop, true to retry, or a Retry-After
     *                     value
     */
    protected function retriability(\Throwable $error): bool|string {
        $max = $this->request->max_attempts;

        $attempt = $this->request->attempt;

        if ($max !== null && $max !== 0 && $max - 1 === $attempt) {
            $result = false;
        } elseif (
            $error instanceof NonRetriableError ||
            (
                $error instanceof RemoteError &&
                $error->getErrorName() === 'NonRetriableError'
            )
        ) {
            $result = false;
        } elseif (
            $error instanceof StepError &&
            $error === $this->recently_rejected
        ) {
            // The function didn't catch the step's error.
            $result = false;
        } elseif ($error instanceof RetryAfterError) {
            $result = $error->getRetryAfter();
        } else {
            $result = true;
        }

        return $result;
    }

    /**
     * Checks that a step tool may be used right now
     *
     * @param StepOp $op The tool's op
     *
     * @throws NonRetriableError  When called inside a running step
     * @throws ExecutionException When called outside the handler or after
     *                            the request is over
     *
     * @return void
     */
    protected function assertCanStep(StepOp $op): void {
        if ($this->executing_step !== null) {
            $name = $op->display_name ?? $op->id;
            $this->logger->warning(
                "Nested step tooling detected in \"$name\"",
                [
                    'code'   => self::NESTING_STEPS,
                    'run_id' => $this->request->run_id,
                ]
            );

            throw new NonRetriableError(
                "Step tools can't be used inside a step (\"$name\" was " .
                'called inside "' . $this->executing_step->display_name .
                '"); call them from the function handler instead'
            );
        }

        if ($this->done) {
            throw new ExecutionException(
                "Step \"{$op->id}\" was reached after the request ended " .
                '(a finally block runs when a paused function is cleaned up)',
                7103
            );
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber === null || !isset($this->handler_fibers[$fiber])) {
            throw new ExecutionException(
                'Step tools can only be used from the function handler',
                7101
            );
        }
    }

    /**
     * Warns once when a repeated ID suggests parallel branches share IDs
     *
     * @param string $base The repeated ID
     *
     * @return void
     */
    protected function maybeWarnParallelIndexing(string $base): void {
        $hashed_id = StepIdResolver::hash($base);

        if (
            !$this->warned_parallel_indexing &&
            isset($this->steps[$hashed_id])  &&
            !isset($this->found[$hashed_id])
        ) {
            $this->warned_parallel_indexing = true;
            $this->logger->warning(
                "Duplicate step ID \"$base\" detected across parallel chains",
                [
                    'code'   => self::AUTOMATIC_PARALLEL_INDEXING,
                    'run_id' => $this->request->run_id,
                ]
            );
        }
    }

    /**
     * Destroys paused fibers now, so their `finally` blocks run here
     *
     * @return void
     */
    protected function releaseFibers(): void {
        $this->done = true;
        $this->main = null;

        foreach ($this->steps as $step) {
            $step->fiber = null;
        }

        while ($this->fibers !== []) {
            $fiber = array_pop($this->fibers);
            try {
                // Dropping the last reference destroys a paused fiber.
                $fiber = null;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'A finally block threw while a paused function was ' .
                    'cleaned up',
                    ['exception' => $e, 'run_id' => $this->request->run_id]
                );
            }
        }
    }

    /**
     * Returns the handler to run
     *
     * @throws \LogicException When the failure handler is missing
     *
     * @return \Closure
     */
    protected function handler(): \Closure {
        $handler = $this->failure ?
            $this->function->getFailureHandler() :
            $this->function->getHandler();

        if ($handler === null) {
            throw new \LogicException(
                'Cannot find function `on_failure` handler',
                7202
            );
        }

        return $handler;
    }

    /**
     * Builds the handler context
     *
     * For failure handlers, `error` is rebuilt from the
     * `inngest/function.failed` event.
     *
     * @return Context
     */
    protected function createContext(): Context {
        $error = null;
        if ($this->failure) {
            $error = $this->serializer->deserialize(
                StepError::normalize(
                    $this->request->event->data['error'] ?? null
                )
            );
        }

        return new Context(
            event: $this->request->event,
            events: $this->request->events,
            run_id: $this->request->run_id,
            attempt: $this->request->attempt,
            max_attempts: $this->request->max_attempts,
            step: new StepTools($this, $this->client, $this->logger),
            logger: $this->logger,
            error: $error
        );
    }

    /**
     * Merges replacement input into a step.ai.infer request body
     *
     * @param mixed $body        Body the function passed
     * @param mixed $replacement First item of the input from Inngest
     *
     * @return array<mixed>|\stdClass The merged body (`{}` when empty)
     */
    protected static function replaceBody(
        mixed $body,
        mixed $replacement
    ): array|\stdClass {
        $result = array_replace(
            is_array($body) || $body instanceof \stdClass ?
                (array)$body :
                [],
            is_array($replacement) ? $replacement : []
        );

        return $result === [] ? new \stdClass() : $result;
    }

    /**
     * Returns the time in milliseconds
     *
     * @return int
     */
    protected function nowMs(): int {
        return (int)floor(microtime(true) * 1000);
    }
}
