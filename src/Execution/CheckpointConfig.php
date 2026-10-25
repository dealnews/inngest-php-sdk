<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

use DealNews\Inngest\Config\Checkpointing;
use DealNews\Inngest\Support\Duration;

/**
 * Resolved checkpointing settings for one request
 *
 * Port of JS `InngestFunction.shouldAsyncCheckpoint()` and
 * `InternalCheckpointingOptions` (types.ts).
 *
 * @package DealNews\Inngest\Execution
 */
class CheckpointConfig {

    /**
     * Creates the config
     *
     * @param int   $buffered_steps  Steps to run before each checkpoint
     * @param float $max_runtime_ms  Runtime limit; 0 means none
     * @param float $max_interval_ms Checkpoint interval; 0 means none
     */
    public function __construct(
        public readonly int $buffered_steps =
            Checkpointing::DEFAULT_BUFFERED_STEPS,
        public readonly float $max_runtime_ms =
            Checkpointing::DEFAULT_MAX_RUNTIME_MS,
        public readonly float $max_interval_ms = 0
    ) {
    }

    /**
     * Decides whether a request checkpoints, and with which settings
     *
     * Only discovery requests do: no requested step, a function ID from
     * Inngest, and immediate execution allowed. The function's setting
     * wins over the client's; both default to on.
     *
     * @param bool|Checkpointing|null $function       Function setting
     * @param bool|Checkpointing|null $client         Client setting
     * @param string|null             $requested_step Requested step
     * @param string|null             $fn_id          Inngest's function ID
     * @param bool                    $disable_immediate Immediate
     *                                                   execution disabled
     * @param Duration                $duration       Duration parser
     *
     * @throws \InvalidArgumentException When a duration can't be parsed
     *
     * @return self|null Null when the request shouldn't checkpoint
     */
    public static function resolve(
        bool|Checkpointing|null $function,
        bool|Checkpointing|null $client,
        ?string $requested_step,
        ?string $fn_id,
        bool $disable_immediate,
        Duration $duration
    ): ?self {
        $result  = null;
        $setting = $function ?? $client ?? true;

        if (
            $requested_step === null &&
            $fn_id !== null          &&
            $fn_id !== ''            &&
            !$disable_immediate      &&
            $setting !== false
        ) {
            $result = $setting === true ?
                new self() :
                new self(
                    $setting->buffered_steps ??
                        Checkpointing::DEFAULT_BUFFERED_STEPS,
                    $setting->max_runtime === null ?
                        Checkpointing::DEFAULT_MAX_RUNTIME_MS :
                        $duration->toMilliseconds($setting->max_runtime),
                    $setting->max_interval === null ?
                        0 :
                        $duration->toMilliseconds($setting->max_interval)
                );
        }

        return $result;
    }
}
