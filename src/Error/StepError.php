<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

use Throwable;

/**
 * Exception representing a step that failed
 */
class StepError extends InngestException
{
    protected string $step_name;
    protected ?string $step_stack = null;

    public function __construct(
        string $message,
        string $step_name,
        ?string $step_stack = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $this->step_name = $step_name;
        $this->step_stack = $step_stack;
        parent::__construct($message, $code, $previous);
    }

    public function getStepName(): string
    {
        return $this->step_name;
    }

    public function getStepStack(): ?string
    {
        return $this->step_stack;
    }

    /**
     * @param  int $depth Current depth in the cause chain
     * @return array<string, mixed>
     */
    public function toArray(int $depth = 0): array
    {
        $error = [
            'name' => $this->step_name,
            'message' => $this->getMessage(),
        ];

        if ($this->step_stack !== null) {
            $error['stack'] = $this->step_stack;
        }

        $previous = $this->getPrevious();
        if ($previous !== null && $depth < ErrorFormatter::MAX_CAUSE_DEPTH) {
            $error['cause'] = ErrorFormatter::format($previous, $depth + 1);
        }

        return $error;
    }
}
