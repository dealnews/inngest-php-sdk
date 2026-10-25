<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument of `Middleware::onRunComplete()`
 *
 * @package DealNews\Inngest\Middleware
 */
class RunCompleteArgs extends RunArgs {

    /**
     * Creates the arguments
     *
     * @param Context         $ctx      Handler context
     * @param InngestFunction $function Function being run
     * @param mixed           $output   What the handler returned (after
     *                                  `wrapFunctionHandler`)
     */
    public function __construct(
        Context $ctx,
        InngestFunction $function,
        public readonly mixed $output
    ) {
        parent::__construct($ctx, $function);
    }
}
