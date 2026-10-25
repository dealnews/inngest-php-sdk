<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

use DealNews\Inngest\Middleware\StepInfo;

/**
 * A step the function reached during this request
 *
 * Port of JS `FoundStep` (InngestStepTools.ts), minus checkpointing
 * details.
 *
 * @package DealNews\Inngest\Execution
 */
class FoundStep {

    /**
     * Whether the engine has dealt with this step (resumed it or decided
     * it has to wait)
     *
     * @var bool
     */
    public bool $handled = false;

    /**
     * Fiber waiting on this step's result; released once the run is over
     *
     * @var \Fiber<mixed, mixed, mixed, mixed>|null
     */
    public ?\Fiber $fiber = null;

    /**
     * Whether the step's `wrapStep` middleware chain has started
     *
     * @var bool
     */
    public bool $wrapped = false;

    /**
     * Creates the step
     *
     * @param string               $id             Final ID (with index)
     * @param string               $hashed_id      SHA-1 of the ID
     * @param OpCode               $op             Op code
     * @param string|null          $name           Name
     * @param string               $display_name   Name shown in the UI
     * @param array<string, mixed> $opts           Options
     * @param string               $userland_id    ID with index suffix
     * @param int|null             $userland_index Index for repeated IDs
     * @param \Closure|null        $fn             Code to run (step.run)
     * @param bool                 $fulfilled      Has a result or error
     *                                             (set once a checkpointed
     *                                             step is resumed)
     * @param bool                 $has_step_state Inngest has state for it
     * @param StepInfo             $info           What middleware knows
     *                                             about the step
     */
    public function __construct(
        public readonly string $id,
        public readonly string $hashed_id,
        public readonly OpCode $op,
        public readonly ?string $name,
        public readonly string $display_name,
        public readonly array $opts,
        public readonly string $userland_id,
        public readonly ?int $userland_index,
        public readonly ?\Closure $fn,
        public bool $fulfilled,
        public bool $has_step_state,
        public readonly StepInfo $info
    ) {
    }

    /**
     * Builds the op that reports this step as planned
     *
     * @return OutgoingOp
     */
    public function toOp(): OutgoingOp {
        return $this->buildOp($this->op);
    }

    /**
     * Builds the op for running this step (before its result is known)
     *
     * @return OutgoingOp
     */
    public function toRunOp(): OutgoingOp {
        return $this->buildOp(OpCode::StepRun);
    }

    /**
     * Builds an op for this step
     *
     * @param OpCode $op Op code
     *
     * @return OutgoingOp
     */
    protected function buildOp(OpCode $op): OutgoingOp {
        return new OutgoingOp(
            id: $this->hashed_id,
            op: $op,
            name: $this->name,
            opts: $this->opts,
            display_name: $this->display_name,
            userland_id: $this->userland_id,
            userland_index: $this->userland_index
        );
    }
}
