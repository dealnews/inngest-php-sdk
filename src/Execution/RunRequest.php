<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Api\ApiException;
use DealNews\Inngest\Event\Event;

/**
 * A parsed request to run a function (the `POST` body)
 *
 * Port of `parseFnData()` and `fetchAllFnData()` (helpers/functions.ts).
 *
 * Usage:
 *
 * ```php
 * $version = $request->header('x-inngest-req-version');
 * $run     = RunRequest::parse($raw_body, $version)->withApiData($api);
 * ```
 *
 * Edge cases:
 *
 * - The execution version comes from the `X-Inngest-Req-Version` header
 *   when it's `1` or `2`, else from the body. Missing or `-1` lets the SDK
 *   decide (version 2); `0` is no longer supported and fails.
 * - Without a stack from Inngest, the stack is the step IDs in state order.
 *
 * @package DealNews\Inngest\Execution
 */
class RunRequest {

    /**
     * Execution version this SDK uses when it gets to decide
     *
     * @var int
     */
    public const PREFERRED_VERSION = 2;

    /**
     * Creates the request; prefer parse()
     *
     * @param Event                    $event                       Triggering
     *                                                              event
     * @param list<Event>              $events                      Batch
     * @param array<string, StepState> $steps                       State by
     *                                                              hashed ID
     * @param int                      $version                     Execution
     *                                                              version
     * @param bool                     $sdk_decided                 Whether the
     *                                                              SDK chose
     *                                                              it
     * @param string                   $run_id                      Run ID
     * @param string|null              $fn_id                       Internal
     *                                                              function ID
     * @param int                      $attempt                     Zero-based
     *                                                              attempt
     * @param int|null                 $max_attempts                Attempt
     *                                                              limit
     * @param bool                     $disable_immediate_execution Report
     *                                                              steps
     *                                                              instead of
     *                                                              running
     * @param bool                     $use_api                     Fetch
     *                                                              events and
     *                                                              steps from
     *                                                              the API
     * @param string|null              $qi_id                       Queue item
     *                                                              ID
     * @param list<string>             $stack                       Hashed step
     *                                                              IDs in
     *                                                              completion
     *                                                              order
     */
    public function __construct(
        public readonly Event $event,
        public readonly array $events = [],
        public readonly array $steps = [],
        public readonly int $version = self::PREFERRED_VERSION,
        public readonly bool $sdk_decided = true,
        public readonly string $run_id = '',
        public readonly ?string $fn_id = null,
        public readonly int $attempt = 0,
        public readonly ?int $max_attempts = null,
        public readonly bool $disable_immediate_execution = false,
        public readonly bool $use_api = false,
        public readonly ?string $qi_id = null,
        public readonly array $stack = []
    ) {
    }

    /**
     * Parses and validates a run request body
     *
     * @param string      $body           Raw request body
     * @param string|null $header_version `X-Inngest-Req-Version` header
     *
     * @throws ExecutionException When the body isn't a valid run request
     *                            (code 7001)
     *
     * @return self
     */
    public static function parse(string $body, ?string $header_version): self {
        try {
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            if (!$data instanceof \stdClass) {
                throw new \UnexpectedValueException(
                    'The body must be a JSON object'
                );
            }

            [$version, $sdk_decided] = static::parseVersion(
                $header_version,
                $data
            );

            $ctx   = static::parseCtx($data->ctx ?? null);
            $steps = static::parseSteps($data);

            $result = new self(
                event: static::parseEvent($data->event ?? null, 'event'),
                events: static::parseEvents($data),
                steps: $steps,
                version: $version,
                sdk_decided: $sdk_decided,
                run_id: $ctx['run_id'],
                fn_id: $ctx['fn_id'],
                attempt: $ctx['attempt'],
                max_attempts: $ctx['max_attempts'],
                disable_immediate_execution: $ctx['disable'],
                use_api: $ctx['use_api'],
                qi_id: $ctx['qi_id'],
                stack: $ctx['stack']
            );
        } catch (\Throwable $e) {
            // The reason is in the message; no cause, like JS.
            throw new ExecutionException(
                static::failureMessage($e->getMessage()),
                7001
            );
        }

        return $result->withDefaultStack();
    }

    /**
     * Fetches events and step state from the API when the request says to
     *
     * Inngest sets `use_api` when the run data was too large to send.
     *
     * @param ApiClient $api API client
     *
     * @throws ExecutionException When the data can't be fetched (code 7002)
     *
     * @return self
     */
    public function withApiData(ApiClient $api): self {
        $result = $this;

        if ($this->use_api) {
            if ($this->run_id === '') {
                throw new ExecutionException(
                    'Failed to attempt retrieving data from API; Function ' .
                    "execution can't continue. run_id is missing from " .
                    'context.',
                    7002
                );
            }

            $events = static::fetch(
                fn () => $api->getRunBatch($this->run_id),
                'Failed to retrieve list of events'
            );
            $steps = static::fetch(
                fn () => $api->getRunSteps($this->run_id),
                'Failed to retrieve steps for function run'
            );

            $result = $this->copy(
                events: array_map(
                    static fn ($event) => static::parseEvent($event, 'events'),
                    array_values($events)
                ),
                steps: array_map(
                    static fn ($state) => StepState::fromWire($state),
                    $steps
                )
            )->withDefaultStack();
        }

        return $result;
    }

    /**
     * Returns the hashed step IDs with state
     *
     * @return list<string>
     */
    public function stepIds(): array {
        return array_map('strval', array_keys($this->steps));
    }

    /**
     * Uses the step state order as the stack when there's no stack
     *
     * @return self
     */
    protected function withDefaultStack(): self {
        $result = $this;
        if ($this->stack === [] && $this->steps !== []) {
            $result = $this->copy(stack: $this->stepIds());
        }

        return $result;
    }

    /**
     * Returns a copy with some values replaced
     *
     * @param list<Event>|null              $events New events
     * @param array<string, StepState>|null $steps  New step state
     * @param list<string>|null             $stack  New stack
     *
     * @return self
     */
    protected function copy(
        ?array $events = null,
        ?array $steps = null,
        ?array $stack = null
    ): self {
        return new self(
            event: $this->event,
            events: $events ?? $this->events,
            steps: $steps   ?? $this->steps,
            version: $this->version,
            sdk_decided: $this->sdk_decided,
            run_id: $this->run_id,
            fn_id: $this->fn_id,
            attempt: $this->attempt,
            max_attempts: $this->max_attempts,
            disable_immediate_execution: $this->disable_immediate_execution,
            use_api: $this->use_api,
            qi_id: $this->qi_id,
            stack: $stack ?? $this->stack
        );
    }

    /**
     * Calls the API, turning failures into an ExecutionException
     *
     * @param \Closure(): array<mixed> $call    API call
     * @param string                   $message Failure message
     *
     * @throws ExecutionException On failure (code 7002)
     *
     * @return array<mixed>
     */
    protected static function fetch(\Closure $call, string $message): array {
        try {
            $result = $call();
        } catch (ApiException $e) {
            throw new ExecutionException(
                "$message; Function execution can't continue. " .
                $e->getMessage(),
                7002,
                $e
            );
        }

        return $result;
    }

    /**
     * Picks the execution version from the header or the body
     *
     * @param string|null $header_version Header value
     * @param \stdClass   $data           Body
     *
     * @throws \UnexpectedValueException When the version is invalid
     *
     * @return array{int, bool} Version and whether the SDK decided it
     */
    protected static function parseVersion(
        ?string $header_version,
        \stdClass $data
    ): array {
        $result = null;

        // Only an explicit 1 or 2 in the header counts.
        if (
            $header_version !== null    &&
            is_numeric($header_version) &&
            in_array((float)$header_version, [1.0, 2.0], true)
        ) {
            $result = [(int)$header_version, false];
        }

        if ($result === null) {
            $version = property_exists($data, 'version') ?
                $data->version :
                null;

            if ($version === null || $version === -1) {
                $result = [self::PREFERRED_VERSION, true];
            } elseif ($version === 1 || $version === 2) {
                $result = [$version, false];
            } elseif ($version === 0) {
                throw new \UnexpectedValueException(
                    'V0 execution version is no longer supported'
                );
            } else {
                throw new \UnexpectedValueException(
                    'Invalid execution version'
                );
            }
        }

        return $result;
    }

    /**
     * Parses an event record
     *
     * @param mixed  $value Decoded JSON
     * @param string $path  Field name, for errors
     *
     * @throws \UnexpectedValueException When it isn't an object
     *
     * @return Event
     */
    protected static function parseEvent(mixed $value, string $path): Event {
        $is_object = $value instanceof \stdClass ||
                     (
                         is_array($value) &&
                         ($value === [] || !array_is_list($value))
                     );
        if (!$is_object) {
            throw new \UnexpectedValueException("\"$path\" must be an object");
        }

        return Event::fromArray(StepState::toPhp($value));
    }

    /**
     * Parses the event batch (default: none)
     *
     * @param \stdClass $data Body
     *
     * @throws \UnexpectedValueException When it isn't a list of objects
     *
     * @return list<Event>
     */
    protected static function parseEvents(\stdClass $data): array {
        $events = property_exists($data, 'events') ? $data->events : [];
        if (!is_array($events)) {
            throw new \UnexpectedValueException('"events" must be a list');
        }

        $result = [];
        foreach ($events as $event) {
            $result[] = static::parseEvent($event, 'events');
        }

        return $result;
    }

    /**
     * Parses step state (default: none)
     *
     * @param \stdClass $data Body
     *
     * @throws \UnexpectedValueException When it isn't an object
     *
     * @return array<string, StepState>
     */
    protected static function parseSteps(\stdClass $data): array {
        $steps = property_exists($data, 'steps') ?
            $data->steps :
            new \stdClass();
        if (!$steps instanceof \stdClass) {
            throw new \UnexpectedValueException('"steps" must be an object');
        }

        $result = [];
        foreach (get_object_vars($steps) as $id => $state) {
            $result[(string)$id] = StepState::fromWire($state);
        }

        return $result;
    }

    /**
     * Parses `ctx` (optional, nullable)
     *
     * @param mixed $ctx Decoded `ctx`
     *
     * @throws \UnexpectedValueException When a field has the wrong type
     *
     * @return array{run_id: string, fn_id: ?string, attempt: int,
     *               max_attempts: ?int, disable: bool, use_api: bool,
     *               qi_id: ?string, stack: list<string>}
     */
    protected static function parseCtx(mixed $ctx): array {
        if ($ctx !== null && !$ctx instanceof \stdClass) {
            throw new \UnexpectedValueException('"ctx" must be an object');
        }

        $ctx ??= (object)['run_id' => ''];
        if (!is_string($ctx->run_id ?? null)) {
            throw new \UnexpectedValueException(
                '"ctx.run_id" must be a string'
            );
        }

        $disable = static::optional(
            $ctx,
            'disable_immediate_execution',
            'boolean'
        );
        $use_api = static::optional($ctx, 'use_api', 'boolean');

        return [
            'run_id'       => $ctx->run_id,
            'fn_id'        => static::optional($ctx, 'fn_id', 'string'),
            'attempt'      => static::optionalInt($ctx, 'attempt') ?? 0,
            'max_attempts' => static::optionalInt($ctx, 'max_attempts'),
            'disable'      => $disable ?? false,
            'use_api'      => $use_api ?? false,
            'qi_id'        => static::optional($ctx, 'qi_id', 'string'),
            'stack'        => static::parseStack($ctx->stack ?? null),
        ];
    }

    /**
     * Reads an optional, typed `ctx` field (absent = null)
     *
     * @param \stdClass $ctx   `ctx`
     * @param string    $field Field name
     * @param string    $type  `string`, `number`, or `boolean`
     *
     * @throws \UnexpectedValueException When the type is wrong
     *
     * @return mixed
     */
    protected static function optional(
        \stdClass $ctx,
        string $field,
        string $type
    ): mixed {
        $result = null;

        if (property_exists($ctx, $field)) {
            $value = $ctx->$field;
            $valid = match ($type) {
                'string'  => is_string($value),
                'number'  => is_int($value) || is_float($value),
                default   => is_bool($value),
            };
            if (!$valid) {
                throw new \UnexpectedValueException(
                    "\"ctx.$field\" must be a $type"
                );
            }
            $result = $value;
        }

        return $result;
    }

    /**
     * Reads an optional number as an int (huge values are capped)
     *
     * @param \stdClass $ctx   `ctx`
     * @param string    $field Field name
     *
     * @throws \UnexpectedValueException When the type is wrong
     *
     * @return int|null
     */
    protected static function optionalInt(\stdClass $ctx, string $field): ?int {
        $value = static::optional($ctx, $field, 'number');

        $result = null;
        if ($value !== null) {
            $result = (float)$value >= (float)PHP_INT_MAX ?
                PHP_INT_MAX :
                (int)$value;
        }

        return $result;
    }

    /**
     * Parses `ctx.stack` (`{stack: string[] | null, current: number}`)
     *
     * @param mixed $stack Decoded `ctx.stack`
     *
     * @throws \UnexpectedValueException When it has the wrong shape
     *
     * @return list<string>
     */
    protected static function parseStack(mixed $stack): array {
        $result = [];

        if ($stack !== null) {
            $current = $stack->current ?? null;
            if (
                !$stack instanceof \stdClass       ||
                !property_exists($stack, 'stack')  ||
                !(is_int($current) || is_float($current))
            ) {
                throw new \UnexpectedValueException(
                    '"ctx.stack" must be {stack, current}'
                );
            }

            $ids = $stack->stack ?? [];
            if (
                !is_array($ids) ||
                array_filter($ids, 'is_string') !== $ids
            ) {
                throw new \UnexpectedValueException(
                    '"ctx.stack.stack" must be a list of strings'
                );
            }
            $result = array_values($ids);
        }

        return $result;
    }

    /**
     * Builds the parse failure message (JS `parseFailureErr`)
     *
     * @param string $why Reason
     *
     * @return string
     */
    protected static function failureMessage(string $why): string {
        return 'Failed to parse data from executor; Function execution ' .
               "can't continue. $why. Make sure the raw request body is " .
               'passed to the serve handler.';
    }
}
