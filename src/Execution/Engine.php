<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

use DealNews\Inngest\Api\StaleDispatchError;
use DealNews\Inngest\Client;
use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\NonRetriableError;
use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Error\RetryAfterError;
use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Middleware\FunctionInput;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Middleware\RunArgs;
use DealNews\Inngest\Middleware\RunCompleteArgs;
use DealNews\Inngest\Middleware\RunErrorArgs;
use DealNews\Inngest\Middleware\StepArgs;
use DealNews\Inngest\Middleware\StepCompleteArgs;
use DealNews\Inngest\Middleware\StepErrorArgs;
use DealNews\Inngest\Middleware\StepInfo;
use DealNews\Inngest\Middleware\StepInput;
use DealNews\Inngest\Middleware\StepType;
use DealNews\Inngest\Step\ParallelMode;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
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
 * - With a Checkpointer (async checkpointing), a step that runs is sent to
 *   Inngest and the function keeps going in the same request; the run
 *   ends with a `RunComplete` op. Limits are checked between steps.
 * - Parallel branches (`step->parallel()`, `step->race()`) each run in a
 *   child fiber. A branch that returns or throws settles its group, which
 *   resumes the fiber waiting on it (JS `Promise.all` / `Promise.race`).
 * - Middleware `wrapFunctionHandler` and `wrapStep` hooks run inside the
 *   handler's fibers, so their `next()` pauses along with the function.
 *   A paused step is resumed with a StepResult, which the `wrapStep`
 *   chain turns into the value (or error) the handler sees.
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
     * Middleware for this request
     *
     * @var MiddlewareStack
     */
    protected MiddlewareStack $middleware;

    /**
     * Memoized step state, by hashed ID (after `transformFunctionInput`)
     *
     * @var array<string, StepState>
     */
    protected array $state;

    /**
     * Hashed IDs of memoized steps the handler hasn't reached yet (JS
     * `remainingStepsToBeSeen`)
     *
     * @var array<string, true>
     */
    protected array $unseen = [];

    /**
     * Converts sleep durations changed by middleware
     *
     * @var Duration
     */
    protected Duration $duration;

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
     * Parallel mode inherited by steps in each fiber (set by `race()`)
     *
     * @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, ParallelMode>
     */
    protected \WeakMap $fiber_modes;

    /**
     * Group and branch key of each parallel branch fiber
     *
     * @var \WeakMap<
     *     \Fiber<mixed, mixed, mixed, mixed>,
     *     array{ParallelGroup, int|string}
     * >
     */
    protected \WeakMap $branches;

    /**
     * Every parallel group created, so they can be released
     *
     * @var list<ParallelGroup>
     */
    protected array $groups = [];

    /**
     * Steps that ran but haven't been checkpointed yet
     *
     * @var list<OutgoingOp>
     */
    protected array $checkpoint_buffer = [];

    /**
     * When execute() started, in milliseconds
     *
     * @var int
     */
    protected int $started_ms = 0;

    /**
     * Whether a step has run in this request
     *
     * The runtime limit only applies after that (see runtimeExceeded()).
     *
     * @var bool
     */
    protected bool $step_ran = false;

    /**
     * When the last checkpoint was sent (or execute() started), in
     * milliseconds
     *
     * @var int
     */
    protected int $flushed_ms = 0;

    /**
     * Whether the last roll-up ran a step and resumed the function
     *
     * @var bool
     */
    protected bool $resumed = false;

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
     * @param Checkpointer|null    $checkpointer   Turns on async
     *                                             checkpointing
     * @param MiddlewareStack|null $middleware     Middleware instances for
     *                                             this request
     */
    public function __construct(
        protected InngestFunction $function,
        protected bool $failure,
        protected RunRequest $request,
        protected ?string $requested_step,
        protected LoggerInterface $logger,
        ?ErrorSerializer $serializer = null,
        protected ?Client $client = null,
        protected ?Checkpointer $checkpointer = null,
        ?MiddlewareStack $middleware = null
    ) {
        $this->middleware      = $middleware ??
            new MiddlewareStack([], $logger);
        $this->state           = $request->steps;
        $this->unseen          = array_fill_keys(
            array_map('strval', array_keys($request->steps)),
            true
        );
        $this->duration        = new Duration($logger);
        $this->serializer      = $serializer ?? new ErrorSerializer();
        $this->ids             = new StepIdResolver();
        $this->handler_fibers  = new \WeakMap();
        $this->fiber_modes     = new \WeakMap();
        $this->branches        = new \WeakMap();
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
     * Returns the function being run
     *
     * @return InngestFunction
     */
    public function getFunction(): InngestFunction {
        return $this->function;
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
        $this->started_ms = $this->nowMs();
        $this->flushed_ms = $this->started_ms;

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
     * suspends until the engine resumes it with a result (or error). If
     * the engine never resumes it, this call never returns.
     *
     * Middleware runs here too (JS `applyMiddlewareToStep`):
     * `transformStepInput` may change the step's ID or input, and the
     * result goes through `wrapStep`. A new step with code starts its
     * `wrapStep` chain right away, so middleware can add steps before it;
     * other steps start the chain once their result is known.
     *
     * @param StepOp        $op   What the tool wants
     * @param \Closure|null $fn   Code to run for the step (step.run)
     * @param list<mixed>   $args Arguments for that code
     *
     * @throws NonRetriableError         When called inside a running step
     * @throws ExecutionException        When called outside the handler,
     *                                   or after the request is over
     * @throws StepError                 When the step failed for good
     * @throws \InvalidArgumentException When middleware sets a sleep time
     *                                   that isn't a duration (10004)
     *
     * @return mixed The step's result
     */
    public function step(
        StepOp $op,
        ?\Closure $fn = null,
        array $args = []
    ): mixed {
        $this->assertCanStep($op->display_name ?? $op->id, $op->id);

        [$id, $index] = $this->ids->resolve($op->id, $this->steps);
        if ($index !== null) {
            $this->maybeWarnParallelIndexing($op->id);
        }

        $userland_id = $op->id;
        $hashed_id   = StepIdResolver::hash($id);
        $name        = $op->name;
        $opts        = $op->opts;
        $fiber       = \Fiber::getCurrent();

        // The step's own mode wins, then the one its fiber inherited.
        $mode = $op->parallel_mode ??
            ($fiber === null ? null : $this->fiber_modes[$fiber] ?? null);
        if ($mode !== null) {
            $opts['parallelMode'] = $mode->value;
        }

        $info = $this->stepInfo($op, $opts, $hashed_id);

        if ($info->step_type === StepType::Sleep && $info->input !== null) {
            $name = $this->sleepName($info->input[0] ?? null);
        }
        if (
            $info->step_type->inputIsOpts() &&
            is_array($info->input[0] ?? null)
        ) {
            $opts = $info->input[0];
        }

        // Middleware changed the ID: it's a different step now.
        if ($info->options->id !== $userland_id) {
            [$id, $new_index] = $this->ids->resolve(
                $info->options->id,
                $this->steps
            );
            $index       = $new_index ?? $index;
            $userland_id = $id;
            $hashed_id   = StepIdResolver::hash($id);

            $info->hashed_id = $hashed_id;
            $info->options   = new StepOptions(
                $id,
                $info->options->name,
                $info->options->parallel_mode
            );
        }

        $state          = $this->state[$hashed_id] ?? null;
        $info->memoized = $state?->isFulfilled()   ?? false;
        if ($state !== null) {
            unset($this->unseen[$hashed_id]);
            if ($this->unseen === []) {
                $this->memoizationEnded();
            }
        }

        $replayed = false;

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
                $replayed      = true;
            } elseif ($op->op === OpCode::AiGateway) {
                $opts['body'] = static::replaceBody(
                    $opts['body']    ?? null,
                    $state->input[0] ?? null
                );
                $replayed     = true;
            }
        }

        // Code gets the input as middleware left it.
        if (!$replayed && $info->input !== null) {
            $args = $info->input;
        }

        $step = new FoundStep(
            id: $id,
            hashed_id: $hashed_id,
            op: $op->op,
            name: $name,
            display_name: $op->display_name ?? $op->id,
            opts: $opts,
            userland_id: $userland_id,
            userland_index: $index,
            fn: $fn === null ? null : static fn () => $fn(...$args),
            fulfilled: $state?->isFulfilled() ?? false,
            has_step_state: $state !== null,
            info: $info
        );
        $step->fiber = $fiber;
        unset($fiber);

        $this->steps[$hashed_id] = $step;

        if (!$step->fulfilled && $state === null && $fn !== null) {
            // The step is reported from the middle of the chain, so
            // middleware can add steps first (or throw).
            $step->wrapped = true;
            $result        = $this->middleware->wrapStep(
                $this->context,
                $this->function,
                $info,
                fn () => $this->report($step)->unwrap()
            );
        } else {
            $resume = $this->report($step);
            $result = $this->middleware->wrapStep(
                $this->context,
                $this->function,
                $info,
                static fn () => ($resume->wrap_only ?
                    \Fiber::suspend($step) :
                    $resume)->unwrap()
            );
        }

        return $result;
    }

    /**
     * Runs branches side by side (JS `Promise.all` / `Promise.race`)
     *
     * Called from inside a handler fiber. Each branch starts in its own
     * fiber, in argument order, and runs until it returns, throws, or
     * reaches a step. If the group hasn't settled by then, the calling
     * fiber suspends until the engine settles it.
     *
     * @param array<int|string, \Closure> $branches Branches, by key
     * @param bool                        $race     Settle on the first
     *                                              branch to finish, and
     *                                              mark steps as racing
     *
     * @throws NonRetriableError  When called inside a running step
     * @throws ExecutionException When called outside the handler, or after
     *                            the request is over
     * @throws \Throwable         The first error a branch throws
     *
     * @return mixed Results by branch key, or the winning branch's result
     */
    public function parallel(array $branches, bool $race): mixed {
        $this->assertCanStep($race ? 'race' : 'parallel', 'parallel');

        $parent = \Fiber::getCurrent();
        $mode   = $race ?
            ParallelMode::Race :
            ($parent === null ? null : $this->fiber_modes[$parent] ?? null);
        $group  = new ParallelGroup(array_keys($branches), $race);

        $this->groups[] = $group;

        foreach ($branches as $key => $branch) {
            $fiber = new \Fiber(static fn () => $branch());

            $this->fibers[]               = $fiber;
            $this->handler_fibers[$fiber] = true;
            $this->branches[$fiber]       = [$group, $key];
            $this->middleware->inheritGuard($parent, $fiber);
            if ($mode !== null) {
                $this->fiber_modes[$fiber] = $mode;
            }

            try {
                $fiber->start();
                if ($fiber->isTerminated()) {
                    $group->settle($key, $fiber->getReturn(), null);
                }
            } catch (\Throwable $e) {
                $group->settle($key, null, $e);
            }
        }
        // Paused fibers must not keep references to themselves or their
        // branches, or releaseFibers() can't destroy them in order.
        unset($fiber, $branch);

        if (!$group->settled) {
            $group->parent = $parent;
            unset($parent);
            \Fiber::suspend();
        }

        return $group->result();
    }

    /**
     * Runs the handler and the step loop
     *
     * @return ExecutionResult
     */
    protected function run(): ExecutionResult {
        $handler = $this->handler();

        $this->applyFunctionInput($this->middleware->transformFunctionInput(
            new FunctionInput($this->context, $this->function, $this->state)
        ));

        if ($this->unseen === []) {
            $this->memoizationEnded();
        }
        if ($this->request->steps === [] && $this->request->attempt === 0) {
            $this->middleware->onRunStart(
                new RunArgs($this->context, $this->function)
            );
        }

        $context = $this->context;
        $chain   = $this->middleware->wrapFunctionHandler(
            $context,
            $this->function,
            static fn () => $handler($context)
        );
        $main    = new \Fiber(static fn () => $chain());

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
        $finished = false;
        $value    = null;
        $error    = null;

        try {
            $action();
            if ($fiber->isTerminated()) {
                $finished = true;
                $value    = $fiber->getReturn();
            }
        } catch (\Throwable $e) {
            $finished = true;
            $error    = $e;
        }

        if ($finished) {
            $this->finished($fiber, $value, $error);
        }
    }

    /**
     * Records a fiber that returned or threw
     *
     * The handler's fiber ends the run. A branch fiber settles its group;
     * if that decides the group, the fiber waiting on it is resumed.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber Fiber
     * @param mixed                              $value What it returned
     * @param \Throwable|null                    $error What it threw
     *
     * @return void
     */
    protected function finished(
        \Fiber $fiber,
        mixed $value,
        ?\Throwable $error
    ): void {
        if ($fiber === $this->main) {
            if ($error === null) {
                $this->returned     = true;
                $this->return_value = $value;
                $this->middleware->onRunComplete(new RunCompleteArgs(
                    $this->context,
                    $this->function,
                    $value
                ));
            } else {
                $this->thrown = $error;
                $this->middleware->onRunError(new RunErrorArgs(
                    $this->context,
                    $this->function,
                    $error,
                    $this->retriability($error) === false
                ));
            }
        } else {
            $branch = $this->branches[$fiber] ?? null;
            if ($branch !== null) {
                $group  = $branch[0];
                $parent = $group->parent;
                if (
                    $group->settle($branch[1], $value, $error) &&
                    $parent !== null
                ) {
                    $this->drive($parent, static fn () => $parent->resume());
                }
            }
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
        $state         = $this->state[$step->hashed_id] ?? null;
        $fiber         = $step->fiber;

        if ($step->fulfilled && $state !== null && $fiber !== null) {
            if ($state->type === StepState::TYPE_DATA) {
                $result = StepResult::data($state->data);
            } else {
                $error  = new StepError($step->id, $state->error);
                $result = StepResult::error($error);

                $this->recently_rejected = $error;
            }

            // The wrapStep chain runs again for the saved result.
            $step->info->memoized = true;
            $step->wrapped        = true;
            $this->drive($fiber, static fn () => $fiber->resume($result));
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

        $result        = null;
        $this->resumed = false;
        if ($steps !== []) {
            if ($this->checkpointer !== null) {
                $result = $this->checkpointSteps($steps);
            } else {
                $op     = $this->tryExecuteStep($steps);
                $result = $op === null ?
                    $this->newStepsResult($steps) :
                    $this->stepRan($op);
            }
        }

        if ($result === null && !$this->resumed) {
            $result = $this->stalled();
        }

        return $result;
    }

    /**
     * Handles found steps while checkpointing (JS
     * `asyncCheckpointingHandlers["steps-found"]`)
     *
     * Runs the one new step right away when allowed, checkpoints it, and
     * resumes the function. Anything else (several new steps, a sleep or
     * wait, an error, a checkpoint failure, or the runtime limit) flushes
     * the buffer and hands back to Inngest.
     *
     * @param list<FoundStep> $steps Steps found in this tick
     *
     * @return ExecutionResult|null Null to keep going
     */
    protected function checkpointSteps(array $steps): ?ExecutionResult {
        $result = null;

        $unfulfilled = array_filter(
            $steps,
            static fn (FoundStep $step) => !$step->fulfilled
        );

        if ($unfulfilled !== []) {
            $result = $this->intervalDue() ? $this->flushCheckpoints() : null;

            if ($result === null && $this->runtimeExceeded()) {
                $this->memoizationEnded();
                $result = $this->flushCheckpoints() ??
                    $this->newStepsResult(array_values($this->steps));
            } elseif ($result === null) {
                $result = $this->runNewStep($steps);
            }
        }

        return $result;
    }

    /**
     * Runs the one new step when allowed, otherwise hands back to Inngest
     *
     * @param list<FoundStep> $steps Steps found in this tick
     *
     * @return ExecutionResult|null Null when the function was resumed
     */
    protected function runNewStep(array $steps): ?ExecutionResult {
        $op = $this->tryExecuteStep($steps);
        if ($op !== null) {
            $this->step_ran = true;
        }

        if ($op === null) {
            $result = $this->flushCheckpoints() ??
                $this->newStepsResult($steps);
        } elseif ($op->thrown !== null) {
            $result = $this->flushCheckpoints() ?? $this->stepRan($op);
        } else {
            $result = $this->checkpointAndResume($op);
        }

        return $result;
    }

    /**
     * Buffers a step that ran, checkpoints when the buffer is full, and
     * resumes the function (JS `attemptCheckpointAndResume`)
     *
     * A request that's a retry (attempt > 0) returns the step instead, so
     * the next step gets a fresh retry count.
     *
     * @param OutgoingOp $op The step's op
     *
     * @return ExecutionResult|null Null when the function was resumed
     */
    protected function checkpointAndResume(OutgoingOp $op): ?ExecutionResult {
        $result = null;

        if ($this->request->attempt > 0) {
            $result = $this->stepRan($op);
        } else {
            $this->checkpoint_buffer[] = $op;

            $limit = $this->checkpointer?->config->buffered_steps ?? 1;
            if (count($this->checkpoint_buffer) >= max(1, $limit)) {
                $result = $this->flushCheckpoints();
            }

            if ($result === null) {
                $this->resumeRanStep($op);
            }
        }

        return $result;
    }

    /**
     * Resumes the function with the result of a step that just ran
     *
     * The data goes through JSON first, so the function sees what it would
     * see if Inngest had sent it back (JS `resumeStepWithResult`).
     *
     * @param OutgoingOp $op The step's op
     *
     * @return void
     */
    protected function resumeRanStep(OutgoingOp $op): void {
        $step  = $this->steps[$op->id];
        $fiber = $step->fiber;
        $data  = json_decode(
            (string)json_encode(
                $op->data,
                JSON_UNESCAPED_SLASHES      |
                JSON_UNESCAPED_UNICODE      |
                JSON_PRESERVE_ZERO_FRACTION |
                JSON_PARTIAL_OUTPUT_ON_ERROR
            ),
            true
        );

        $step->fulfilled      = true;
        $step->has_step_state = true;
        $step->handled        = true;
        $this->resumed        = true;

        if ($fiber !== null) {
            $result = StepResult::data($data);
            $this->drive($fiber, static fn () => $fiber->resume($result));
        }
    }

    /**
     * Sends buffered steps to Inngest
     *
     * @return ExecutionResult|null Null when sent (or nothing to send); a
     *                              stale-request rejection, or the buffered
     *                              steps for Inngest to save when sending
     *                              failed
     */
    protected function flushCheckpoints(): ?ExecutionResult {
        $ops    = $this->checkpoint_buffer;
        $result = null;

        $this->checkpoint_buffer = [];
        $this->flushed_ms        = $this->nowMs();

        if ($ops !== [] && $this->checkpointer !== null) {
            try {
                $this->checkpointer->checkpoint($ops);
            } catch (StaleDispatchError $e) {
                // Another request owns the run now; stop without retrying.
                $result = ExecutionResult::rejected(
                    $this->serializer->serialize($e),
                    false
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Checkpointing failed; returning steps to Inngest instead',
                    ['exception' => $e, 'run_id' => $this->request->run_id]
                );
                $result = ExecutionResult::stepsFound($ops);
            }
        }

        return $result;
    }

    /**
     * Whether the checkpointing runtime limit has passed
     *
     * Not before the first step of the request has run: JS's timer can
     * only take effect at an `await`, after the first step found has been
     * handled, so that step always runs (D52).
     *
     * @return bool
     */
    protected function runtimeExceeded(): bool {
        $limit = $this->checkpointer?->config->max_runtime_ms ?? 0;

        return $limit > 0       &&
            $this->step_ran     &&
            $this->nowMs() - $this->started_ms >= $limit;
    }

    /**
     * Whether buffered steps are due for an interval checkpoint
     *
     * @return bool
     */
    protected function intervalDue(): bool {
        $interval = $this->checkpointer?->config->max_interval_ms ?? 0;

        return $interval > 0                  &&
            $this->checkpoint_buffer !== []   &&
            $this->nowMs() - $this->flushed_ms >= $interval;
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
                $this->memoizationEnded();
                $result = ExecutionResult::stepsFound($ops);
            }
        }

        return $result;
    }

    /**
     * Runs a step's code and builds its op (JS `executeStep`)
     *
     * Middleware: `onStepStart`, then the step's `wrapStep` chain is
     * started (if it wasn't already), then the code runs inside
     * `wrapStepHandler`, then `onStepComplete` or `onStepError`.
     *
     * @param FoundStep $step Step
     *
     * @return OutgoingOp
     */
    protected function executeStep(FoundStep $step): OutgoingOp {
        $op    = $step->toRunOp();
        $fn    = $step->fn;
        $info  = $step->info;
        $fiber = $step->fiber;

        $this->memoizationEnded();
        $this->middleware->onStepStart(
            new StepArgs($this->context, $this->function, $info)
        );

        if (!$step->wrapped && $fiber !== null) {
            $step->wrapped = true;
            $wrap_only     = StepResult::wrapOnly();
            $this->drive(
                $fiber,
                static fn () => $fiber->resume($wrap_only)
            );
        }
        unset($fiber);

        $start = $this->nowMs();
        $data  = null;

        $this->executing_step = $step;
        try {
            $data = $this->middleware->wrapStepHandler(
                $this->context,
                $this->function,
                $info,
                static fn () => $fn === null ? null : $fn()
            );
            $op->withData($data);
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

        if ($op->thrown === null) {
            $this->middleware->onStepComplete(new StepCompleteArgs(
                $this->context,
                $this->function,
                $info,
                $data
            ));
        } else {
            $this->middleware->onStepError(new StepErrorArgs(
                $this->context,
                $this->function,
                $info,
                $op->thrown,
                $op->op === OpCode::StepFailed
            ));
        }

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
        $result = $this->newStepsResult(array_values($this->steps));

        if ($this->checkpointer !== null) {
            // Unsent steps go back with the final ops (JS only does this for
            // RunComplete; it drops them when new steps are reported).
            $ops = $this->checkpoint_buffer;
            if ($result !== null) {
                $ops = array_merge($ops, $result->ops);
            } else {
                $ops[] = (new OutgoingOp(
                    StepIdResolver::hash(self::RUN_COMPLETE_STEP_ID),
                    OpCode::RunComplete
                ))->withData($this->return_value);
            }

            $this->checkpoint_buffer = [];
            $result                  = ExecutionResult::stepsFound($ops);
        }

        return $result ?? ExecutionResult::resolved($this->return_value);
    }

    /**
     * Builds the result for a handler that threw
     *
     * @param \Throwable $error Error
     *
     * @return ExecutionResult
     */
    protected function rejected(\Throwable $error): ExecutionResult {
        // Steps that ran before the error are still worth saving.
        return $this->flushCheckpoints() ?? ExecutionResult::rejected(
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
        $this->memoizationEnded();

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
     * @param string $name Name of the step (or tool) for messages
     * @param string $id   Step ID (or tool) for messages
     *
     * @throws NonRetriableError  When called inside a running step
     * @throws ExecutionException When called outside the handler or after
     *                            the request is over
     *
     * @return void
     */
    protected function assertCanStep(string $name, string $id): void {
        if ($this->executing_step !== null) {
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
                "Step \"$id\" was reached after the request ended " .
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

        foreach ($this->groups as $group) {
            $group->parent = null;
        }
        $this->groups      = [];
        $this->branches    = new \WeakMap();
        $this->fiber_modes = new \WeakMap();

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
     * Registers a found step for the next roll-up and pauses until the
     * engine resumes it
     *
     * @param FoundStep $step Step
     *
     * @return StepResult
     */
    protected function report(FoundStep $step): StepResult {
        $this->found[$step->hashed_id]     = $step;
        $this->unhandled[$step->hashed_id] = $step;

        return \Fiber::suspend($step);
    }

    /**
     * Builds what middleware knows about a step and runs
     * `transformStepInput` (JS `MiddlewareManager.applyToStep`)
     *
     * @param StepOp               $op        Op from the step tool
     * @param array<string, mixed> $opts      Op options
     * @param string               $hashed_id Hashed step ID
     *
     * @return StepInfo
     */
    protected function stepInfo(
        StepOp $op,
        array $opts,
        string $hashed_id
    ): StepInfo {
        $type  = StepType::fromOp($op->op, $opts);
        $state = $this->state[$hashed_id] ?? null;

        $input = null;
        if ($type->inputIsOpts()) {
            $input = [$opts];
        } elseif (
            is_array($opts['input'] ?? null) &&
            array_is_list($opts['input'])
        ) {
            $input = $opts['input'];
        }

        $info = new StepInfo(
            hashed_id: $hashed_id,
            step_type: $type,
            options: new StepOptions($op->id, $op->display_name ?? $op->id),
            memoized: $state?->isFulfilled() ?? false,
            input: $input
        );

        if ($this->middleware->has('transformStepInput')) {
            $changed = $this->middleware->transformStepInput(new StepInput(
                $this->function,
                $hashed_id,
                $type,
                $info->memoized,
                $info->options,
                $input ?? []
            ));

            $info->options = $changed->step_options;
            $info->input   = $input === null && $changed->input === [] ?
                null :
                array_values($changed->input);
        }

        return $info;
    }

    /**
     * Builds a sleep op's name from a duration middleware set
     *
     * @param mixed $time Duration or date
     *
     * @throws \InvalidArgumentException When it isn't one (10004)
     *
     * @return string
     */
    protected function sleepName(mixed $time): string {
        if (
            !is_int($time)                   &&
            !is_float($time)                 &&
            !is_string($time)                &&
            !$time instanceof \DateInterval  &&
            !$time instanceof \DateTimeInterface
        ) {
            throw new \InvalidArgumentException(
                'Sleep time must be a string, number, DateInterval, or ' .
                'DateTimeInterface',
                10004
            );
        }

        return $this->duration->toTimeString($time);
    }

    /**
     * Runs `onMemoizationEnd` (once per request)
     *
     * @return void
     */
    protected function memoizationEnded(): void {
        $this->middleware->onMemoizationEnd(
            new RunArgs($this->context, $this->function)
        );
    }

    /**
     * Applies what `transformFunctionInput` returned
     *
     * The context is replaced. Memoized steps only take changed `data`
     * (JS `applyFunctionInputMutations`).
     *
     * @param FunctionInput $input Middleware result
     *
     * @return void
     */
    protected function applyFunctionInput(FunctionInput $input): void {
        $this->context = $input->ctx;

        foreach ($input->steps as $hashed_id => $changed) {
            $existing = $this->state[$hashed_id] ?? null;
            if (
                $existing       !== null                          &&
                $existing->type !== StepState::TYPE_INPUT         &&
                $changed instanceof StepState                     &&
                $changed->type === StepState::TYPE_DATA           &&
                $changed->data !== $existing->data
            ) {
                $this->state[$hashed_id] = new StepState(
                    StepState::TYPE_DATA,
                    $changed->data
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
