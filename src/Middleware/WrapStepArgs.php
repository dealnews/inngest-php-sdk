<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument of `Middleware::wrapStep()` and `wrapStepHandler()`
 *
 * @package DealNews\Inngest\Middleware
 */
class WrapStepArgs extends StepArgs {

    /**
     * Creates the arguments
     *
     * @param Context         $ctx       Handler context
     * @param InngestFunction $function  Function being run
     * @param StepInfo        $step_info The step
     * @param \Closure        $next      Runs the rest of the chain
     */
    public function __construct(
        Context $ctx,
        InngestFunction $function,
        StepInfo $step_info,
        protected \Closure $next
    ) {
        parent::__construct($ctx, $function, $step_info);
    }

    /**
     * Runs the rest of the chain
     *
     * In `wrapStep`, returns only once the step has a result; when the
     * step still has to run, it never returns in this request.
     *
     * @return mixed The step's result
     */
    public function next(): mixed {
        return ($this->next)();
    }
}
