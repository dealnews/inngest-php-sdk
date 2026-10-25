<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

use DealNews\Inngest\Error\StepError;

/**
 * What the engine resumes a paused step with
 *
 * The result goes through the `wrapStep` middleware chain inside the
 * step's fiber before the handler sees it, so errors are passed as values
 * and thrown there rather than thrown into the fiber.
 *
 * @package DealNews\Inngest\Execution
 */
class StepResult {

    /**
     * Creates the result; prefer the static constructors
     *
     * @param bool           $wrap_only Start the `wrapStep` chain and pause
     *                                  again (no result yet)
     * @param mixed          $data      Step result
     * @param StepError|null $error     Step error
     */
    protected function __construct(
        public readonly bool $wrap_only,
        public readonly mixed $data = null,
        public readonly ?StepError $error = null
    ) {
    }

    /**
     * A step that returned data
     *
     * @param mixed $data Step result
     *
     * @return self
     */
    public static function data(mixed $data): self {
        return new self(false, $data);
    }

    /**
     * A step that failed
     *
     * @param StepError $error Step error
     *
     * @return self
     */
    public static function error(StepError $error): self {
        return new self(false, null, $error);
    }

    /**
     * No result yet: run the `wrapStep` chain up to the step, then pause
     * again (used when a step is about to run its code)
     *
     * @return self
     */
    public static function wrapOnly(): self {
        return new self(true);
    }

    /**
     * Returns the data or throws the error
     *
     * @throws StepError When the step failed
     *
     * @return mixed
     */
    public function unwrap(): mixed {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->data;
    }
}
