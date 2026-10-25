<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Step\StepOptions;

/**
 * What middleware knows about a step
 *
 * Port of JS `Middleware.StepInfo`.
 *
 * @package DealNews\Inngest\Middleware
 */
class StepInfo {

    /**
     * Creates the info
     *
     * @param string           $hashed_id SHA-1 of the step ID (with the
     *                                    index suffix for repeated IDs)
     * @param StepType         $step_type Step tool the step came from
     * @param StepOptions      $options   ID and name, as the step tool got
     *                                    them (after `transformStepInput`)
     * @param bool             $memoized  Whether the result comes from
     *                                    Inngest rather than running now
     * @param list<mixed>|null $input     Step input: `step->run()`
     *                                    arguments, or `[opts]` for
     *                                    `invoke` and `waitForEvent`
     */
    public function __construct(
        public string $hashed_id,
        public StepType $step_type,
        public StepOptions $options,
        public bool $memoized,
        public ?array $input = null
    ) {
    }
}
