<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument of `Middleware::wrapFunctionHandler()`
 *
 * @package DealNews\Inngest\Middleware
 */
class WrapFunctionHandlerArgs extends RunArgs {

    /**
     * Creates the arguments
     *
     * @param Context         $ctx      Handler context
     * @param InngestFunction $function Function being run
     * @param \Closure        $next     Runs the rest of the chain
     */
    public function __construct(
        Context $ctx,
        InngestFunction $function,
        protected \Closure $next
    ) {
        parent::__construct($ctx, $function);
    }

    /**
     * Runs the rest of the chain (ending with the handler)
     *
     * Returns only when the handler returns. When the handler reaches a
     * step that hasn't run, it never returns; put cleanup in `finally`.
     *
     * @return mixed What the handler returned
     */
    public function next(): mixed {
        return ($this->next)();
    }
}
