<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Checkpoint configuration for a function.
 *
 * Checkpointing allows the SDK to execute multiple steps within a single
 * invocation by persisting step results to the Inngest Server's API in
 * the background, rather than yielding after each step.
 */
class Checkpoint
{
    /**
     * @param int $bufferedSteps Maximum steps to buffer before flushing (0 = checkpoint after every step)
     * @param string|null $maxInterval Maximum time between flushes (e.g. "3s"); null = disabled
     * @param string|null $maxRuntime Maximum total execution time per invocation; null = disabled
     */
    public function __construct(
        public readonly int $bufferedSteps = 0,
        public readonly ?string $maxInterval = null,
        public readonly ?string $maxRuntime = null,
    ) {
    }

    /**
     * Checkpoint after every step. Most durable.
     */
    public static function safe(): self
    {
        return new self(bufferedSteps: 0);
    }

    /**
     * Batch up to 1000 steps before checkpointing. Fastest, least durable.
     */
    public static function performant(): self
    {
        return new self(bufferedSteps: 1000);
    }

    /**
     * Checkpoint after 3 steps or 3 seconds, whichever comes first. Balanced.
     */
    public static function blended(): self
    {
        return new self(bufferedSteps: 3, maxInterval: '3s');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_steps' => $this->bufferedSteps,
            'batch_interval' => $this->maxInterval ?? '0s',
            'max_runtime' => $this->maxRuntime ?? '0s',
        ];
    }
}
