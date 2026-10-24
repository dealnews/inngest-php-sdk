<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

/**
 * Kinds of execution result (JS `ExecutionResult["type"]`)
 *
 * @package DealNews\Inngest\Execution
 */
enum ResultType: string {
    case FunctionResolved = 'function-resolved';
    case FunctionRejected = 'function-rejected';
    case StepRan          = 'step-ran';
    case StepsFound       = 'steps-found';
    case StepNotFound     = 'step-not-found';
}
