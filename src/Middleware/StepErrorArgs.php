<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument of `Middleware::onStepError()`
 *
 * @package DealNews\Inngest\Middleware
 */
class StepErrorArgs extends StepArgs {

    /**
     * Creates the arguments
     *
     * @param Context         $ctx              Handler context
     * @param InngestFunction $function         Function being run
     * @param StepInfo        $step_info        The step
     * @param \Throwable      $error            What the step's code threw
     * @param bool            $is_final_attempt True when the step won't
     *                                          be retried
     */
    public function __construct(
        Context $ctx,
        InngestFunction $function,
        StepInfo $step_info,
        public readonly \Throwable $error,
        public readonly bool $is_final_attempt
    ) {
        parent::__construct($ctx, $function, $step_info);
    }
}
