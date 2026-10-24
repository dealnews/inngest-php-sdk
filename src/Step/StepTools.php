<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\StepOp;

/**
 * The step tools handed to a function as `$ctx->step`
 *
 * Port of `createStepTools()` (components/InngestStepTools.ts). Each tool
 * returns the step's result. The first time a step is reached, the engine
 * pauses the function (Fiber) and runs or reports the step; when Inngest
 * calls back, the stored result is returned without running it again.
 *
 * Usage:
 *
 * ```php
 * $user = $ctx->step->run('fetch-user', fn () => $users->find($id));
 * ```
 *
 * Edge cases:
 *
 * - Step results go through JSON: objects come back as arrays.
 * - Call tools from the function handler only, not from inside a step.
 *
 * @package DealNews\Inngest\Step
 */
class StepTools {

    /**
     * Creates the tools
     *
     * @param Engine $engine Engine running the function
     */
    public function __construct(protected Engine $engine) {
    }

    /**
     * Runs code as a retriable step and returns its result
     *
     * Extra arguments are passed to the callable and shown as the step's
     * input in the Inngest UI.
     *
     * @param string|StepOptions $id       Step ID or options
     * @param callable           $callable Code to run
     * @param mixed              ...$input Arguments for the callable
     *
     * @throws \DealNews\Inngest\Error\StepError When the step failed for
     *                                           good
     *
     * @return mixed The callable's return value (null for void)
     */
    public function run(
        string|StepOptions $id,
        callable $callable,
        mixed ...$input
    ): mixed {
        $options = StepOptions::from($id);
        $input   = array_values($input);
        $opts    = $input === [] ? [] : ['input' => $input];

        return $this->engine->step(
            new StepOp(
                id: $options->id,
                op: OpCode::StepPlanned,
                name: $options->id,
                display_name: $options->name ?? $options->id,
                opts: $opts
            ),
            \Closure::fromCallable($callable),
            $input
        );
    }
}
