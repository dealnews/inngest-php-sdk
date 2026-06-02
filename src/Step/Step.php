<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Event\Event;

/**
 * Step execution engine
 *
 * @property-read   AiStep      $ai
 */
class Step
{
    /** @var array<string, int> */
    protected array $step_counts = [];

    protected ?string $target_step_id = null;

    protected ?array $executed_step = null;

    protected ?AiStep $ai_step = null;

    /** @var callable|null */
    protected $after_memoization_callback = null;

    /** @var callable|null */
    protected $send_callback = null;

    protected bool $after_memoization_called = false;


    public function __construct(protected StepContext $context)
    {
    }

    public function __get(string $name): mixed {
        if ($name === 'ai') {
            return $this->_getAI();
        }
        throw new \RuntimeException("Undefined property: Step::\${$name}");
    }

    public function setAfterMemoizationCallback(callable $callback): void
    {
        $this->after_memoization_callback = $callback;
    }

    public function setSendCallback(callable $callback): void
    {
        $this->send_callback = $callback;
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

    public function wasAfterMemoizationCalled(): bool
    {
        return $this->after_memoization_called;
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
        return $this->_executeStep($id, ['StepRun', 'StepError'], null, $fn);
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
        $duration_str = is_int($duration) ? "{$duration}s" : $duration;

        return $this->_executeStep($id, 'Sleep', ['duration' => $duration_str]);
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
        $opts = ['event' => $event, 'timeout' => $timeout];
        if ($if !== null) {
            $opts['if'] = $if;
        }

        return $this->_executeStep($id, 'WaitForEvent', $opts);
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
        return $this->_executeStep($id, 'InvokeFunction', ['function_id' => $function_id, 'payload' => $payload]);
    }

    /**
     * Send one or more events
     *
     * @param string $id Step identifier
     * @param Event|array<Event> $events Event or array of events to send
     * @return mixed
     * @throws StepError
     * @throws \RuntimeException if setSendCallback() was not called before use
     */
    public function sendEvent(string $id, Event|array $events): mixed
    {
        $events_array = is_array($events) ? $events : [$events];

        $send = $this->send_callback;

        return $this->run($id, function () use ($events_array, $send) {
            if ($send === null) {
                throw new \RuntimeException('Step::sendEvent requires a send callback — call setSendCallback() before use');
            }
            return $send($events_array);
        });
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
        $opts = ['url' => $url, 'method' => $method];
        if ($headers !== null) {
            $opts['headers'] = $headers;
        }
        if ($body !== null) {
            $opts['body'] = $body;
        }

        return $this->_executeStep($id, 'Gateway', $opts);
    }

    /**
     * Get the AI step helper
     */
    protected function _getAI(): AiStep
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
        ?string $format = null,
        ?string $auth_key = null
    ): mixed {
        $opts = ['type' => 'step.ai.infer', 'url' => $url, 'body' => $body];
        if ($headers !== null) {
            $opts['headers'] = $headers;
        }
        if ($format !== null) {
            $opts['format'] = $format;
        }
        if ($auth_key !== null) {
            $opts['auth_key'] = $auth_key;
        }

        return $this->_executeStep($id, 'AIGateway', $opts);
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
     * Either return the cached step or execute the step and suspend further execution
     *
     * @param   string          $id         Step identifier
     * @param   string|array    $opcode     Specifies the type of Step being reported.
     *                                      If the opcode is dependent on the outcome of the provided function, then
     *                                      should provide an array where the first value is the "success" opcode if the function was successful
     *                                      and the second value is the "error" opcode if the function throws an exception.
     * @param   array|null      $opts       Step options
     * @param   callable|null   $function   Function to be called as part of this step
     *
     * @return  mixed
     *
     * @throws StepError
     * @throws \Throwable
     */
    protected function _executeStep(string $id, string|array $opcode, ?array $opts = null, ?callable $function = null): mixed
    {
        $hashed_id = $this->hashId($id);

        if ($this->context->hasStep($hashed_id)) {
            return $this->memoizeStep($hashed_id);
        }

        $this->triggerAfterMemoization();

        if ($this->target_step_id !== null && $hashed_id !== $this->target_step_id) {
            return null;
        }

        $success_opcode = $opcode;
        $error_opcode = $opcode;
        if (is_array($opcode)) {
            $success_opcode = $opcode[0];
            $error_opcode = $opcode[1];
        }

        $step_data = [
            'id' => $hashed_id,
            'op' => $success_opcode,
            'displayName' => $id,
        ];

        if (!is_null($opts)) {
            $step_data['opts'] = $opts;
        }
        if (!is_null($function)) {
            try {
                $result = $function();
                $step_data['data'] = $result;
            } catch (\Throwable $e) {
                $step_data['op'] = $error_opcode;
                $error = [
                    'name' => get_class($e),
                    'message' => $e->getMessage(),
                    'stack' => $e->getTraceAsString(),
                ];
                $step_data['error'] = $error;
            }
        }

        $this->executed_step = $step_data;
        return \Fiber::suspend($this->executed_step);
    }

    /**
     * @throws StepError
     */
    protected function memoizeStep(string $hashed_id): mixed
    {
        $step_data = $this->context->getStep($hashed_id);

        if (is_array($step_data)) {
            if (array_key_exists('data', $step_data)) {
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
