<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

/**
 * Context for step execution
 */
class StepContext
{
    /**
     * @param string $run_id The ID of this function run
     * @param int $attempt Current attempt number (0-indexed)
     * @param bool $disable_immediate_execution Whether to disable immediate step execution
     * @param bool $use_api Whether to use API to retrieve full payload
     * @param array<string, mixed> $stack Stack information for step recovery
     * @param array<string, mixed> $steps Memoized step data
     */
    public function __construct(
        protected string $run_id,
        protected int $attempt,
        protected bool $disable_immediate_execution,
        protected bool $use_api,
        protected array $stack,
        protected array $steps = []
    ) {
    }

    public function getRunId(): string
    {
        return $this->run_id;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function shouldDisableImmediateExecution(): bool
    {
        return $this->disable_immediate_execution;
    }

    public function shouldUseApi(): bool
    {
        return $this->use_api;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStack(): array
    {
        return $this->stack;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @param array<string, mixed> $steps
     */
    public function setSteps(array $steps): void
    {
        $this->steps = $steps;
    }

    public function hasStep(string $id): bool
    {
        return isset($this->steps[$id]);
    }

    /**
     * @return mixed
     */
    public function getStep(string $id): mixed
    {
        return $this->steps[$id] ?? null;
    }
}
