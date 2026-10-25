<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

/**
 * How Inngest treats a step that runs alongside others
 *
 * Port of JS `parallelMode` (`StepOptions`, `group.parallel()`). Sent as
 * `opts.parallelMode` on the step's op.
 *
 * @package DealNews\Inngest\Step
 */
enum ParallelMode: string {

    /**
     * The first step to finish wins; Inngest may cancel the others
     */
    case Race = 'race';
}
