<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument of `Middleware::onRunError()`
 *
 * @package DealNews\Inngest\Middleware
 */
class RunErrorArgs extends RunArgs {

    /**
     * Creates the arguments
     *
     * @param Context         $ctx              Handler context
     * @param InngestFunction $function         Function being run
     * @param \Throwable      $error            What the handler threw
     * @param bool            $is_final_attempt True when the function
     *                                          won't be retried
     */
    public function __construct(
        Context $ctx,
        InngestFunction $function,
        public readonly \Throwable $error,
        public readonly bool $is_final_attempt
    ) {
        parent::__construct($ctx, $function);
    }
}
