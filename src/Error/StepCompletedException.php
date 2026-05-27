<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

/**
 * Exception thrown when a step has been completed
 *
 * This exception is used internally to signal that a step execution has
 * completed and the result is available in the step_result property.
 */
class StepCompletedException extends \Exception
{
    /**
     * @param array<mixed> $step_result The result data from the completed step
     */
    public function __construct(public readonly array $step_result)
    {
        parent::__construct('Step completed');
    }
}
