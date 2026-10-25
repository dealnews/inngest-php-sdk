<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\Client;
use DealNews\Inngest\Error\NonRetriableError;
use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Execution\StepIdResolver;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Http\Response;
use DealNews\Inngest\Middleware\FunctionInput;
use DealNews\Inngest\Middleware\Middleware;
use DealNews\Inngest\Middleware\RunArgs;
use DealNews\Inngest\Middleware\RunCompleteArgs;
use DealNews\Inngest\Middleware\RunErrorArgs;
use DealNews\Inngest\Middleware\SendEventInput;
use DealNews\Inngest\Middleware\StepArgs;
use DealNews\Inngest\Middleware\StepCompleteArgs;
use DealNews\Inngest\Middleware\StepErrorArgs;
use DealNews\Inngest\Middleware\StepInfo;
use DealNews\Inngest\Middleware\StepInput;
use DealNews\Inngest\Middleware\StepType;
use DealNews\Inngest\Middleware\WrapFunctionHandlerArgs;
use DealNews\Inngest\Middleware\WrapRequestArgs;
use DealNews\Inngest\Middleware\WrapSendEventArgs;
use DealNews\Inngest\Middleware\WrapStepArgs;
use DealNews\Inngest\Step\StepOptions;

/**
 * Middleware that records every hook call, like the JS fixture's `Rec`
 *
 * Mirrors tests/fixtures/middleware/generate.mts: same entries, same
 * changes for the functions named after a hook (only when tagged "c";
 * the "f" instance only changes sent events).
 *
 * Usage:
 *
 * ```php
 * $log = new \ArrayObject();
 * $options = new ClientOptions(middleware: [
 *     RecordingMiddleware::factory($log, 'c'),
 * ]);
 * ```
 *
 * @package DealNews\Inngest\Tests\mock
 */
class RecordingMiddleware extends Middleware {

    /**
     * Creates the middleware
     *
     * @param Client                                  $client Client
     * @param \ArrayObject<int, array<string, mixed>> $log    Shared log
     * @param string                                  $tag    Tag for
     *                                                        entries
     */
    public function __construct(
        Client $client,
        protected \ArrayObject $log,
        protected string $tag
    ) {
        parent::__construct($client);
    }

    /**
     * Returns a factory for the middleware lists
     *
     * @param \ArrayObject<int, array<string, mixed>> $log Shared log
     * @param string                                  $tag Tag for entries
     *
     * @return \Closure
     */
    public static function factory(\ArrayObject $log, string $tag): \Closure {
        return static fn (Client $client) => new self($client, $log, $tag);
    }

    /**
     * {@inheritDoc}
     */
    public function getId(): string {
        return 'rec-' . $this->tag;
    }

    /**
     * {@inheritDoc}
     */
    public function transformFunctionInput(
        FunctionInput $input
    ): FunctionInput {
        $this->add('transformFunctionInput', [
            'steps' => array_map('strval', array_keys($input->steps)),
        ]);

        $result = $input;
        if ($this->changes($input->function, 'tfi')) {
            $result = clone $input;
            $steps  = $input->steps;
            $a      = StepIdResolver::hash('A');
            if (($steps[$a] ?? null)?->type === StepState::TYPE_DATA) {
                $steps[$a] = new StepState(StepState::TYPE_DATA, 'A-changed');
            }

            $event       = clone $input->ctx->event;
            $event->data = array_replace($event->data, ['n' => 99]);

            $result->steps = $steps;
            $result->ctx   = $input->ctx->with(
                event: $event,
                extras: ['db' => 'db']
            );
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function onMemoizationEnd(RunArgs $args): void {
        $this->add('onMemoizationEnd');
    }

    /**
     * {@inheritDoc}
     */
    public function onRunStart(RunArgs $args): void {
        $this->add('onRunStart');
    }

    /**
     * {@inheritDoc}
     */
    public function wrapFunctionHandler(
        WrapFunctionHandlerArgs $args
    ): mixed {
        $this->add('wrapFunctionHandler:before');
        if ($this->changes($args->function, 'wfh-throws')) {
            throw new \Error('hook broke');
        }
        try {
            $out = $args->next();
            if ($this->changes($args->function, 'wfh-out')) {
                $out = ['wrapped' => $out];
            }
            $this->add('wrapFunctionHandler:after', [
                'output' => static::json($out),
            ]);
        } catch (\Throwable $e) {
            $this->add('wrapFunctionHandler:error', [
                'error' => static::error($e),
            ]);
            if ($this->changes($args->function, 'wfh-err')) {
                throw new NonRetriableError(
                    'converted: ' . $e->getMessage()
                );
            }
            throw $e;
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function onRunComplete(RunCompleteArgs $args): void {
        $this->add('onRunComplete', ['output' => static::json($args->output)]);
    }

    /**
     * {@inheritDoc}
     */
    public function onRunError(RunErrorArgs $args): void {
        $this->add('onRunError', [
            'error'          => static::error($args->error),
            'isFinalAttempt' => $args->is_final_attempt,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function transformStepInput(StepInput $input): StepInput {
        $this->add('transformStepInput', [
            'id'       => $input->step_options->id,
            'type'     => $input->step_type->value,
            'memoized' => $input->memoized,
            'input'    => static::json($input->input),
        ]);

        $result = clone $input;
        $type   = $input->step_type;
        if (
            $this->changes($input->function, 'tsi-run') &&
            $type === StepType::Run
        ) {
            $result->input = [2];
        } elseif (
            $this->changes($input->function, 'tsi-id') &&
            $input->step_options->id === 'B'
        ) {
            $result->step_options = new StepOptions(
                'A',
                $input->step_options->name
            );
        } elseif ($this->changes($input->function, 'tsi-kinds')) {
            $opts = $input->input[0] ?? [];
            if ($type === StepType::Sleep) {
                $result->input = ['1s'];
            } elseif ($type === StepType::WaitForEvent) {
                $opts['timeout'] = '1s';
                $result->input   = [$opts];
            } elseif ($type === StepType::Invoke) {
                $opts['payload']['data'] = ['value' => 2];
                $result->input           = [$opts];
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function wrapStep(WrapStepArgs $args): mixed {
        $step = static::step($args->step_info);
        $id   = $args->step_info->options->id;
        $this->add('wrapStep:before', ['step' => $step]);

        if ($this->changes($args->function, 'prepend') && $id === 'main') {
            $args->ctx->step->run('pre', static fn () => 'pre');
        }
        if ($this->changes($args->function, 'ws-throws') && $id === 'A') {
            throw new \Error('hook broke');
        }
        if ($this->changes($args->function, 'ws-bookend') && $id === 'S') {
            $args->ctx->step->run('before', static fn () => 'before');
            $out = $args->next();
            $args->ctx->step->run('after', static fn () => 'after');
            $this->add('wrapStep:after', [
                'step'   => static::step($args->step_info),
                'output' => static::json($out),
            ]);

            return $out;
        }

        try {
            $out = $args->next();
            if (
                $this->changes($args->function, 'serde') &&
                is_array($out)                           &&
                array_key_exists('enc', $out)
            ) {
                $out = $out['enc'];
            }
            $this->add('wrapStep:after', [
                'step'   => static::step($args->step_info),
                'output' => static::json($out),
            ]);
        } catch (\Throwable $e) {
            $this->add('wrapStep:error', [
                'step'  => static::step($args->step_info),
                'error' => static::error($e),
            ]);
            if ($this->changes($args->function, 'ws-change-error')) {
                throw new \Error('replaced: ' . $e->getMessage());
            }
            throw $e;
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function onStepStart(StepArgs $args): void {
        $this->add('onStepStart', ['step' => static::step($args->step_info)]);
    }

    /**
     * {@inheritDoc}
     */
    public function wrapStepHandler(WrapStepArgs $args): mixed {
        $this->add('wrapStepHandler:before', [
            'step' => static::step($args->step_info),
        ]);
        try {
            $out = $args->next();
            if ($this->changes($args->function, 'serde')) {
                $out = ['enc' => $out];
            }
            $this->add('wrapStepHandler:after', [
                'output' => static::json($out),
            ]);
        } catch (\Throwable $e) {
            $this->add('wrapStepHandler:error', [
                'error' => static::error($e),
            ]);
            throw $e;
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function onStepComplete(StepCompleteArgs $args): void {
        $this->add('onStepComplete', [
            'step'   => static::step($args->step_info),
            'output' => static::json($args->output),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function onStepError(StepErrorArgs $args): void {
        $this->add('onStepError', [
            'step'           => static::step($args->step_info),
            'error'          => static::error($args->error),
            'isFinalAttempt' => $args->is_final_attempt,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function transformSendEvent(
        SendEventInput $input
    ): SendEventInput {
        $this->add('transformSendEvent', [
            'fn'     => $input->function?->id(),
            'events' => array_map(static fn ($e) => $e->name, $input->events),
        ]);

        $result = $input;
        if ($this->tag === 'f') {
            $result = clone $input;
            foreach ($result->events as $key => $event) {
                $event                = clone $event;
                $event->data['extra'] = 1;
                $result->events[$key] = $event;
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function wrapSendEvent(WrapSendEventArgs $args): SendEventResult {
        $this->add('wrapSendEvent:before', [
            'events' => array_map(static fn ($e) => $e->name, $args->events),
        ]);
        $out = $args->next();
        $this->add('wrapSendEvent:after', ['output' => static::json($out)]);

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function wrapRequest(WrapRequestArgs $args): Response {
        $this->add('wrapRequest:before', [
            'fn'    => $args->function?->id(),
            'runId' => $args->run_id,
        ]);
        $response = $args->next();
        $this->add('wrapRequest:after', ['status' => $response->status]);

        return $response;
    }

    /**
     * Records an entry
     *
     * @param string               $hook  Hook name
     * @param array<string, mixed> $extra Details
     *
     * @return void
     */
    protected function add(string $hook, array $extra = []): void {
        $this->log[] = array_merge(
            ['tag' => $this->tag, 'hook' => $hook],
            $extra
        );
    }

    /**
     * Whether this instance changes things for a function
     *
     * @param InngestFunction|null $function Function
     * @param string               $id       Function ID
     *
     * @return bool
     */
    protected function changes(?InngestFunction $function, string $id): bool {
        return $this->tag === 'c' && $function?->id() === $id;
    }

    /**
     * Summarizes a step like the JS fixture
     *
     * @param StepInfo $info Step
     *
     * @return array<string, mixed>
     */
    protected static function step(StepInfo $info): array {
        $result = [
            'id'       => $info->options->id,
            'name'     => $info->options->name,
            'hashedId' => $info->hashed_id,
            'type'     => $info->step_type->value,
            'memoized' => $info->memoized,
        ];
        if ($info->input !== null) {
            $result['input'] = static::json($info->input);
        }

        return $result;
    }

    /**
     * Summarizes an error like the JS fixture
     *
     * @param \Throwable $error Error
     *
     * @return array<string, string>
     */
    protected static function error(\Throwable $error): array {
        $parts = explode('\\', $error::class);

        return [
            'name'    => $error instanceof RemoteError ?
                $error->getErrorName() :
                end($parts),
            'message' => $error->getMessage(),
        ];
    }

    /**
     * Round-trips a value through JSON
     *
     * @param mixed $value Value
     *
     * @return mixed
     */
    protected static function json(mixed $value): mixed {
        return json_decode((string)json_encode($value), true);
    }
}
