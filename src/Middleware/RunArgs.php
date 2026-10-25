<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument of the run hooks (`onMemoizationEnd`, `onRunStart`)
 *
 * Port of JS `Middleware.OnRunStartArgs`. The other run and step hook
 * arguments extend this one.
 *
 * @package DealNews\Inngest\Middleware
 */
class RunArgs {

    /**
     * Creates the arguments
     *
     * @param Context         $ctx      Handler context
     * @param InngestFunction $function Function being run
     */
    public function __construct(
        public readonly Context $ctx,
        public readonly InngestFunction $function
    ) {
    }
}
