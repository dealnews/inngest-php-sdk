<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Http\Response;

/**
 * Argument of `Middleware::wrapRequest()`
 *
 * @package DealNews\Inngest\Middleware
 */
class WrapRequestArgs {

    /**
     * Creates the arguments
     *
     * @param Request              $request  Incoming request
     * @param InngestFunction|null $function Function named by `fnId`, if
     *                                       found
     * @param string               $run_id   Run ID from the body (empty
     *                                       when there isn't one)
     * @param \Closure             $next     Runs the rest of the chain
     */
    public function __construct(
        public readonly Request $request,
        public readonly ?InngestFunction $function,
        public readonly string $run_id,
        protected \Closure $next
    ) {
    }

    /**
     * Runs the rest of the chain (ending with the SDK's handling)
     *
     * @return Response The response that will be sent (already signed)
     */
    public function next(): Response {
        return ($this->next)();
    }
}
