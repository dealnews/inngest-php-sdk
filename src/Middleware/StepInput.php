<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Step\StepOptions;

/**
 * Argument and result of `Middleware::transformStepInput()`
 *
 * Port of JS `Middleware.TransformStepInputArgs`. Return it with
 * different `step_options` (a new ID makes it a different step) or
 * `input` to change the step.
 *
 * What `input` holds, by step type:
 *
 * - `run`, `ai.wrap`: the arguments passed to the step's code
 * - `invoke`, `waitForEvent`: `[opts]`, the op options sent to Inngest
 * - `sleep`: empty; set `[duration]` to change the wait
 * - anything else: empty, and changes are ignored
 *
 * @package DealNews\Inngest\Middleware
 */
class StepInput {

    /**
     * Creates the input
     *
     * @param InngestFunction $function     Function being run
     * @param string          $hashed_id    Hashed step ID (before changes)
     * @param StepType        $step_type    Step tool the step came from
     * @param bool            $memoized     Whether Inngest has its result
     * @param StepOptions     $step_options Step ID and name
     * @param list<mixed>     $input        Step input
     */
    public function __construct(
        public readonly InngestFunction $function,
        public readonly string $hashed_id,
        public readonly StepType $step_type,
        public readonly bool $memoized,
        public StepOptions $step_options,
        public array $input
    ) {
    }
}
