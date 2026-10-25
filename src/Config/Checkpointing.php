<?php

declare(strict_types=1);

namespace DealNews\Inngest\Config;

/**
 * Tuning for async checkpointing
 *
 * Port of the object form of JS `CheckpointingOptions` (types.ts). With
 * checkpointing on (the default), the SDK runs a step, sends its result to
 * Inngest (a "checkpoint"), and keeps going in the same request instead of
 * returning after every step. Pass `true` or `false` instead of this class
 * to use the defaults or turn checkpointing off.
 *
 * Usage:
 *
 * ```php
 * new FunctionOptions(
 *     id: 'import',
 *     checkpointing: new Checkpointing(max_runtime: '25s', buffered_steps: 5)
 * );
 * ```
 *
 * Edge cases:
 *
 * - Durations follow `Duration`: ints are milliseconds, strings are
 *   `"30s"`-style.
 * - PHP can't interrupt running code, so limits are checked between steps.
 * - If the server dies before a buffered checkpoint is sent, those steps
 *   run again.
 *
 * @package DealNews\Inngest\Config
 */
class Checkpointing {

    /**
     * Default number of steps to buffer before checkpointing
     *
     * @var int
     */
    public const DEFAULT_BUFFERED_STEPS = 1;

    /**
     * Default maximum runtime for HTTP serving, in milliseconds
     *
     * @var int
     */
    public const DEFAULT_MAX_RUNTIME_MS = 10000;

    /**
     * Creates the options
     *
     * @param int|float|string|\DateInterval|null $max_runtime    How long to
     *        keep running steps in one request before handing the rest back
     *        to Inngest; `0` means no limit. Defaults to 10 seconds; keep it
     *        below your request timeout.
     * @param int|null                            $buffered_steps Steps to
     *        run before each checkpoint. Defaults to 1.
     * @param int|float|string|\DateInterval|null $max_interval   Send a
     *        checkpoint once this much time has passed, even if the buffer
     *        isn't full; `0` means never. Defaults to 0.
     */
    public function __construct(
        public readonly int|float|string|\DateInterval|null $max_runtime =
            null,
        public readonly ?int $buffered_steps = null,
        public readonly int|float|string|\DateInterval|null $max_interval =
            null
    ) {
    }
}
