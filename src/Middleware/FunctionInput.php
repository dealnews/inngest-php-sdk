<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Context;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument and result of `Middleware::transformFunctionInput()`
 *
 * Port of JS `Middleware.TransformFunctionInputArgs`. Return it with a
 * different `ctx` (see `Context::with()`) or different `steps` to change
 * what the handler gets.
 *
 * Edge cases:
 *
 * - Only `data` changes to memoized steps are applied; adding steps or
 *   changing errors and inputs has no effect (same as JS).
 *
 * @package DealNews\Inngest\Middleware
 */
class FunctionInput {

    /**
     * Creates the input
     *
     * @param Context                  $ctx      Handler context
     * @param InngestFunction          $function Function being run
     * @param array<string, StepState> $steps    Memoized steps, by hashed
     *                                           step ID
     */
    public function __construct(
        public Context $ctx,
        public readonly InngestFunction $function,
        public array $steps
    ) {
    }
}
