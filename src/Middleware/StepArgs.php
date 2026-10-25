<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument of `Middleware::onStepStart()`
 *
 * Port of JS `Middleware.OnStepStartArgs`. The other step hook arguments
 * extend this one.
 *
 * @package DealNews\Inngest\Middleware
 */
class StepArgs extends RunArgs {

    /**
     * Creates the arguments
     *
     * @param Context         $ctx       Handler context
     * @param InngestFunction $function  Function being run
     * @param StepInfo        $step_info The step
     */
    public function __construct(
        Context $ctx,
        InngestFunction $function,
        public readonly StepInfo $step_info
    ) {
        parent::__construct($ctx, $function);
    }
}
