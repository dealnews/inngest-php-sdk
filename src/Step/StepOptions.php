<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\StepOp;

/**
 * A step's ID, optional display name, and optional parallel mode
 *
 * Every step tool takes either a string ID or one of these.
 *
 * Usage:
 *
 * ```php
 * $ctx->step->run(new StepOptions('fetch-user', 'Fetch the user'), $fn);
 * ```
 *
 * @package DealNews\Inngest\Step
 */
class StepOptions {

    /**
     * Creates the options
     *
     * @param string            $id            Step ID; keep it stable
     *                                         between deploys
     * @param string|null       $name          Name shown in the Inngest UI;
     *                                         defaults to the ID
     * @param ParallelMode|null $parallel_mode Parallel mode for this step;
     *                                         `step->race()` sets it for
     *                                         every step inside it
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name = null,
        public readonly ?ParallelMode $parallel_mode = null
    ) {
    }

    /**
     * Normalizes a string ID or options to options
     *
     * @param string|self $id_or_options ID or options
     *
     * @return self
     */
    public static function from(string|self $id_or_options): self {
        return is_string($id_or_options) ?
            new self($id_or_options) :
            $id_or_options;
    }

    /**
     * Builds the op for a step with these options
     *
     * @param OpCode               $op   Op code
     * @param string|null          $name Op name (tool-specific)
     * @param array<string, mixed> $opts Op options
     *
     * @return StepOp
     */
    public function toOp(
        OpCode $op,
        ?string $name = null,
        array $opts = []
    ): StepOp {
        return new StepOp(
            id: $this->id,
            op: $op,
            name: $name,
            display_name: $this->name ?? $this->id,
            opts: $opts,
            parallel_mode: $this->parallel_mode
        );
    }
}
