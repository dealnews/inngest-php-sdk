<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Event\Event;

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

    protected ?string $target_step_id = null;

    protected ?array $executed_step = null;

    protected ?AiStep $ai_step = null;

    /** @var callable|null */
    protected $after_memoization_callback = null;

    protected bool $after_memoization_called = false;

    public function __construct(protected StepContext $context)
    {
    }

    public function setAfterMemoizationCallback(callable $callback): void
    {
        $this->after_memoization_callback = $callback;
    }

    private function triggerAfterMemoization(): void
    {
        if (!$this->after_memoization_called && $this->after_memoization_callback !== null) {
            $this->after_memoization_called = true;
            ($this->after_memoization_callback)();
        }
    }

    public function setTargetStepId(string $id): void
    {
        $this->target_step_id = $id;
    }

    public function getExecutedStep(): ?array
    {
        return $this->executed_step;
    }

    public function wasTargetStepFound(): bool
    {
        return $this->executed_step !== null;
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

        $this->triggerAfterMemoization();

        if ($this->target_step_id !== null) {
            if ($hashed_id === $this->target_step_id) {
                try {
                    $result = $fn();
                    $this->executed_step = [
                        'id' => $hashed_id,
                        'op' => 'StepRun',
                        'displayName' => $id,
                        'data' => $result,
                    ];
                    throw new \DealNews\Inngest\Error\StepCompletedException($this->executed_step);
                } catch (\DealNews\Inngest\Error\StepCompletedException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $this->executed_step = [
                        'id' => $hashed_id,
                        'op' => 'StepError',
                        'displayName' => $id,
                        'error' => [
                            'name' => get_class($e),
                            'message' => $e->getMessage(),
                            'stack' => $e->getTraceAsString(),
                        ],
                    ];
                    throw new \DealNews\Inngest\Error\StepCompletedException($this->executed_step);
                }
            }
            return null;
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

        $this->triggerAfterMemoization();

        if ($this->target_step_id !== null) {
            return null;
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

        $this->triggerAfterMemoization();

        if ($this->target_step_id !== null) {
            return null;
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

        $this->triggerAfterMemoization();

        if ($this->target_step_id !== null) {
            return null;
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
     * Send one or more events
     *
     * @param string $id Step identifier
     * @param Event|array<Event> $events Event or array of events to send
     * @return mixed
     * @throws StepError
     */
    public function sendEvent(string $id, Event|array $events): mixed
    {
        $hashed_id = $this->hashId($id);

        if ($this->context->hasStep($hashed_id)) {
            return $this->memoizeStep($hashed_id);
        }

        $this->triggerAfterMemoization();

        if ($this->target_step_id !== null) {
            return null;
        }

        $events_array = is_array($events) ? $events : [$events];

        $this->planned_steps[] = [
            'id' => $hashed_id,
            'op' => 'SendEvent',
            'displayName' => $id,
            'opts' => [
                'payload' => array_map(fn($e) => $e->toArray(), $events_array),
            ],
        ];

        $this->total_steps++;

        return null;
    }

    /**
     * Perform an HTTP fetch as a step
     *
     * @param string $id Step identifier
     * @param string $url URL to fetch
     * @param string $method HTTP method
     * @param array<string, string>|null $headers Optional HTTP headers
     * @param mixed $body Optional request body
     * @return mixed
     * @throws StepError
     */
    public function fetch(
        string $id,
        string $url,
        string $method = 'GET',
        ?array $headers = null,
        mixed $body = null
    ): mixed {
        $hashed_id = $this->hashId($id);

        if ($this->context->hasStep($hashed_id)) {
            return $this->memoizeStep($hashed_id);
        }

        $this->triggerAfterMemoization();

        if ($this->target_step_id !== null) {
            return null;
        }

        $opts = [
            'url' => $url,
            'method' => $method,
        ];

        if ($headers !== null) {
            $opts['headers'] = $headers;
        }

        if ($body !== null) {
            $opts['body'] = $body;
        }

        $this->planned_steps[] = [
            'id' => $hashed_id,
            'op' => 'Gateway',
            'displayName' => $id,
            'opts' => $opts,
        ];

        $this->total_steps++;

        return null;
    }

    /**
     * Get the AI step helper
     */
    public function ai(): AiStep
    {
        if ($this->ai_step === null) {
            $this->ai_step = new AiStep($this);
        }
        return $this->ai_step;
    }

    /**
     * Perform an AI inference step (called by AiStep)
     *
     * @param string $id Step identifier
     * @param string $url AI model URL
     * @param array<string, mixed> $body Request body
     * @param array<string, string>|null $headers Optional HTTP headers
     * @param string|null $format Optional response format
     * @return mixed
     * @throws StepError
     */
    public function aiInfer(
        string $id,
        string $url,
        array $body,
        ?array $headers = null,
        ?string $format = null
    ): mixed {
        $hashed_id = $this->hashId($id);

        if ($this->context->hasStep($hashed_id)) {
            return $this->memoizeStep($hashed_id);
        }

        $this->triggerAfterMemoization();

        if ($this->target_step_id !== null) {
            return null;
        }

        $opts = [
            'url' => $url,
            'body' => $body,
        ];

        if ($headers !== null) {
            $opts['headers'] = $headers;
        }

        if ($format !== null) {
            $opts['format'] = $format;
        }

        $this->planned_steps[] = [
            'id' => $hashed_id,
            'op' => 'AiGateway',
            'displayName' => $id,
            'opts' => $opts,
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
            $this->step_counts[$id] = 1;
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
