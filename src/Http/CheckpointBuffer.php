<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

/**
 * In-memory buffer of pending StepRun results awaiting a checkpoint flush.
 */
final class CheckpointBuffer
{
    /** @var array<int, array<string, mixed>> */
    private array $steps = [];

    private ?int $started_at_ms = null;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly int $bufferedSteps,
        private readonly ?int $maxIntervalMs,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) round(microtime(true) * 1000);
    }

    /**
     * @param array<string, mixed> $step_suspension
     */
    public function add(array $step_suspension): void
    {
        if ($this->steps === []) {
            $this->started_at_ms = ($this->clock)();
        }

        $this->steps[] = $step_suspension;
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    public function shouldFlush(): bool
    {
        if ($this->steps === []) {
            return false;
        }

        // bufferedSteps 0 or 1 means "flush after every step" - count is always >= 1
        // here since add() has already been called, so this covers that case with no
        // special-casing.
        if (count($this->steps) >= $this->bufferedSteps) {
            return true;
        }

        return $this->maxIntervalMs !== null
            && $this->started_at_ms !== null
            && (($this->clock)() - $this->started_at_ms) >= $this->maxIntervalMs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function peek(): array
    {
        return $this->steps;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function drain(): array
    {
        $steps = $this->steps;
        $this->steps = [];
        $this->started_at_ms = null;
        return $steps;
    }
}
