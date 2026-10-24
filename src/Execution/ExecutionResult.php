<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

use DealNews\Inngest\Error\SerializedError;

/**
 * The outcome of one request to run a function
 *
 * Port of JS `ExecutionResult` (execution/InngestExecution.ts). Build with
 * the named constructors; only the fields for the type are set.
 *
 * @package DealNews\Inngest\Execution
 */
class ExecutionResult {

    /**
     * Creates the result; prefer the named constructors
     *
     * @param ResultType                     $type        Kind of result
     * @param mixed                          $data        Return value
     *                                                    (resolved)
     * @param SerializedError|null           $error       Error (rejected)
     * @param bool|string|null               $retriable   Whether to retry;
     *                                                    a string is a
     *                                                    Retry-After value
     * @param list<OutgoingOp>               $ops         Ops (step-ran: one,
     *                                                    steps-found: many)
     * @param string|null                    $step_id     Requested step that
     *                                                    wasn't found
     * @param list<array<string, string|null>> $found_steps Steps found
     *                                                    instead (capped)
     * @param int                            $total_found Uncapped count
     */
    public function __construct(
        public readonly ResultType $type,
        public readonly mixed $data = null,
        public readonly ?SerializedError $error = null,
        public readonly bool|string|null $retriable = null,
        public readonly array $ops = [],
        public readonly ?string $step_id = null,
        public readonly array $found_steps = [],
        public readonly int $total_found = 0
    ) {
    }

    /**
     * The function returned
     *
     * @param mixed $data Return value
     *
     * @return self
     */
    public static function resolved(mixed $data): self {
        return new self(ResultType::FunctionResolved, data: $data);
    }

    /**
     * The function threw
     *
     * @param SerializedError $error     Error
     * @param bool|string     $retriable Whether to retry, or Retry-After
     *
     * @return self
     */
    public static function rejected(
        SerializedError $error,
        bool|string $retriable
    ): self {
        return new self(
            ResultType::FunctionRejected,
            error: $error,
            retriable: $retriable
        );
    }

    /**
     * A step ran
     *
     * @param OutgoingOp       $op        The step's op
     * @param bool|string|null $retriable Set when the step failed
     *
     * @return self
     */
    public static function stepRan(
        OutgoingOp $op,
        bool|string|null $retriable = null
    ): self {
        return new self(
            ResultType::StepRan,
            retriable: $retriable,
            ops: [$op]
        );
    }

    /**
     * New steps were found and need to be planned by Inngest
     *
     * @param list<OutgoingOp> $ops Ops
     *
     * @return self
     */
    public static function stepsFound(array $ops): self {
        return new self(ResultType::StepsFound, ops: $ops);
    }

    /**
     * The step Inngest asked for wasn't found
     *
     * @param string                           $step_id     Requested step
     * @param list<array<string, string|null>> $found_steps New steps found
     * @param int                              $total_found Uncapped count
     *
     * @return self
     */
    public static function stepNotFound(
        string $step_id,
        array $found_steps,
        int $total_found
    ): self {
        return new self(
            ResultType::StepNotFound,
            step_id: $step_id,
            found_steps: $found_steps,
            total_found: $total_found
        );
    }
}
