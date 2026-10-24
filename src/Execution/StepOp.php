<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

/**
 * What a step tool asks the engine to do (before ID hashing)
 *
 * Port of the op a JS step tool's `matchOp` returns.
 *
 * @package DealNews\Inngest\Execution
 */
class StepOp {

    /**
     * Creates the op
     *
     * @param string               $id           Step ID the user gave
     * @param OpCode               $op           Op code
     * @param string|null          $name         Name (tool-specific)
     * @param string|null          $display_name Name shown in the UI
     * @param array<string, mixed> $opts         Options
     */
    public function __construct(
        public readonly string $id,
        public readonly OpCode $op,
        public readonly ?string $name = null,
        public readonly ?string $display_name = null,
        public readonly array $opts = []
    ) {
    }
}
