<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument of `Middleware::onStepComplete()`
 *
 * @package DealNews\Inngest\Middleware
 */
class StepCompleteArgs extends StepArgs {

    /**
     * Creates the arguments
     *
     * @param Context         $ctx       Handler context
     * @param InngestFunction $function  Function being run
     * @param StepInfo        $step_info The step
     * @param mixed           $output    What the step's code returned
     *                                   (after `wrapStepHandler`)
     */
    public function __construct(
        Context $ctx,
        InngestFunction $function,
        StepInfo $step_info,
        public readonly mixed $output
    ) {
        parent::__construct($ctx, $function, $step_info);
    }
}
