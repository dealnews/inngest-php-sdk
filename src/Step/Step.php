<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

use DealNews\Inngest\Error\StepError;

/**
 * Step execution engine
 */
class Step
{
    /** @var array<string> */
    protected array $planned_steps = [];

    /** @var array<string, int> */
    protected array $step_counts = [];

    protected int $total_steps = 0;

    public function __construct(protected StepContext $context)
    {
    }

    /**
     * Execute a retriable block of code
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws StepError
     */
    public function run(string $id, callable $fn): mixed
    {
        $hashed_id = $this->hashId($id);

        if ($this->context->hasStep($hashed_id)) {
            return $this->memoizeStep($hashed_id);
        }

        $this->planned_steps[] = [
            'id' => $hashed_id,
            'op' => 'StepPlanned',
            'displayName' => $id,
        ];

        $this->total_steps++;

        return $fn();
    }

    /**
     * Sleep for a specified duration
     *
     * @param string $id Step identifier
     * @param string|int $duration Duration as time string or seconds
     * @return null
     * @throws StepError
     */
    public function sleep(string $id, string|int $duration): mixed
    {
        $hashed_id = $this->hashId($id);

        if ($this->context->hasStep($hashed_id)) {
            return $this->memoizeStep($hashed_id);
        }

        $duration_str = is_int($duration) ? "{$duration}s" : $duration;

        $this->planned_steps[] = [
            'id' => $hashed_id,
            'op' => 'Sleep',
            'displayName' => $id,
            'opts' => [
                'duration' => $duration_str,
            ],
        ];

        $this->total_steps++;

        return null;
    }

    /**
     * Wait for an event to be received
     *
     * @param string $id Step identifier
     * @param string $event Event name to wait for
     * @param string $timeout Timeout duration
     * @param string|null $if Optional CEL expression
     * @return array<string, mixed>|null
     * @throws StepError
     */
    public function waitForEvent(string $id, string $event, string $timeout, ?string $if = null): ?array
    {
        $hashed_id = $this->hashId($id);

        if ($this->context->hasStep($hashed_id)) {
            return $this->memoizeStep($hashed_id);
        }

        $opts = [
            'event' => $event,
            'timeout' => $timeout,
        ];

        if ($if !== null) {
            $opts['if'] = $if;
        }

        $this->planned_steps[] = [
            'id' => $hashed_id,
            'op' => 'WaitForEvent',
            'displayName' => $id,
            'opts' => $opts,
        ];

        $this->total_steps++;

        return null;
    }

    /**
     * Invoke another Inngest function
     *
     * @param string $id Step identifier
     * @param string $function_id Composite function ID to invoke
     * @param array<string, mixed> $payload Event payload (without name)
     * @return mixed
     * @throws StepError
     */
    public function invoke(string $id, string $function_id, array $payload): mixed
    {
        $hashed_id = $this->hashId($id);

        if ($this->context->hasStep($hashed_id)) {
            return $this->memoizeStep($hashed_id);
        }

        $this->planned_steps[] = [
            'id' => $hashed_id,
            'op' => 'InvokeFunction',
            'displayName' => $id,
            'opts' => [
                'function_id' => $function_id,
                'payload' => $payload,
            ],
        ];

        $this->total_steps++;

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPlannedSteps(): array
    {
        return $this->planned_steps;
    }

    public function getTotalSteps(): int
    {
        return $this->total_steps;
    }

    protected function hashId(string $id): string
    {
        if (!isset($this->step_counts[$id])) {
            $this->step_counts[$id] = 0;
            $final_id = $id;
        } else {
            $final_id = $id . ':' . $this->step_counts[$id];
            $this->step_counts[$id]++;
        }

        return sha1($final_id);
    }

    /**
     * @throws StepError
     */
    protected function memoizeStep(string $hashed_id): mixed
    {
        $step_data = $this->context->getStep($hashed_id);

        if (is_array($step_data)) {
            if (isset($step_data['data'])) {
                return $step_data['data'];
            }

            if (isset($step_data['error'])) {
                $error = $step_data['error'];
                throw new StepError(
                    $error['message'] ?? 'Step failed',
                    $error['name'] ?? 'StepError',
                    $error['stack'] ?? null
                );
            }
        }

        return $step_data;
    }
}
