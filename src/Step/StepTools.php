<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

use DealNews\Inngest\Api\SendSignalResult;
use DealNews\Inngest\Client;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Event\EventMeta;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Functions\FunctionReference;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Support\Duration;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The step tools handed to a function as `$ctx->step`
 *
 * Port of `createStepTools()` (components/InngestStepTools.ts). Each tool
 * returns the step's result. The first time a step is reached, the engine
 * pauses the function (Fiber) and runs or reports the step; when Inngest
 * calls back, the stored result is returned without running it again.
 *
 * Usage:
 *
 * ```php
 * $user = $ctx->step->run('fetch-user', fn () => $users->find($id));
 * $ctx->step->sleep('cool-off', '1h');
 * $paid = $ctx->step->waitForEvent(
 *     'wait-for-payment',
 *     event: 'billing/paid',
 *     timeout: '3d',
 *     match: 'data.order_id'
 * );
 * ```
 *
 * Edge cases:
 *
 * - Step results go through JSON: objects come back as arrays.
 * - Call tools from the function handler only, not from inside a step
 *   (`fetch()` is the exception: inside a step it makes a plain request).
 * - Durations follow `Duration`: ints are milliseconds, strings are
 *   `"30m"`-style, and dates are absolute deadlines.
 *
 * @package DealNews\Inngest\Step
 */
class StepTools {

    /**
     * AI tools (`$ctx->step->ai->infer()`, `->wrap()`)
     *
     * @var AiTools
     */
    public readonly AiTools $ai;

    /**
     * Converts durations to Inngest time strings
     *
     * @var Duration
     */
    protected Duration $duration;

    /**
     * Creates the tools
     *
     * @param Engine               $engine Engine running the function
     * @param Client|null          $client Client, for tools that send events
     *                                     or signals, make HTTP requests, or
     *                                     invoke functions by reference
     * @param LoggerInterface|null $logger Logger for duration warnings
     */
    public function __construct(
        protected Engine $engine,
        protected ?Client $client = null,
        ?LoggerInterface $logger = null
    ) {
        $this->duration = new Duration($logger);
        $this->ai       = new AiTools($engine);
    }

    /**
     * Runs code as a retriable step and returns its result
     *
     * Extra arguments are passed to the callable and shown as the step's
     * input in the Inngest UI.
     *
     * @param string|StepOptions $id       Step ID or options
     * @param callable           $callable Code to run
     * @param mixed              ...$input Arguments for the callable
     *
     * @throws \DealNews\Inngest\Error\StepError When the step failed for
     *                                           good
     *
     * @return mixed The callable's return value (null for void)
     */
    public function run(
        string|StepOptions $id,
        callable $callable,
        mixed ...$input
    ): mixed {
        $input = array_values($input);

        return $this->engine->step(
            static::runOp(StepOptions::from($id), $input),
            \Closure::fromCallable($callable),
            $input
        );
    }

    /**
     * Pauses the function for a while
     *
     * @param string|StepOptions             $id       Step ID or options
     * @param int|float|string|\DateInterval $duration Milliseconds, a time
     *                                                 string (`"1h30m"`),
     *                                                 or an interval
     *
     * @throws \InvalidArgumentException When the duration can't be parsed
     *
     * @return void
     */
    public function sleep(
        string|StepOptions $id,
        int|float|string|\DateInterval $duration
    ): void {
        $options = StepOptions::from($id);

        $this->engine->step(new StepOp(
            id: $options->id,
            op: OpCode::Sleep,
            name: $this->duration->toTimeString($duration),
            display_name: $options->name ?? $options->id
        ));
    }

    /**
     * Pauses the function until a point in time
     *
     * @param string|StepOptions        $id   Step ID or options
     * @param \DateTimeInterface|string $time When to continue; strings are
     *                                        parsed by PHP (no time zone =
     *                                        PHP's default)
     *
     * @throws \InvalidArgumentException When the time can't be parsed (7302)
     *
     * @return void
     */
    public function sleepUntil(
        string|StepOptions $id,
        \DateTimeInterface|string $time
    ): void {
        $options = StepOptions::from($id);

        $this->engine->step(new StepOp(
            id: $options->id,
            op: OpCode::Sleep,
            name: Duration::toIsoString($this->parseDate($time)),
            display_name: $options->name ?? $options->id
        ));
    }

    /**
     * Waits for an event, or gives up after a timeout
     *
     * Use `match` for the common case of a field that must be equal in
     * both events, or `if` for a full CEL expression (`event` = the
     * triggering event, `async` = the incoming one).
     *
     * @param string|StepOptions                                $id
     *        Step ID or options
     * @param string                                            $event
     *        Event name
     * @param int|float|string|\DateInterval|\DateTimeInterface $timeout
     *        How long to wait
     * @param string|null                                       $match
     *        Field path that must be equal in both events
     * @param string|null                                       $if
     *        CEL expression the incoming event must pass
     *
     * @throws \InvalidArgumentException When both `match` and `if` are given
     *                                   (7301)
     *
     * @return array<mixed>|null The event, or null on timeout
     */
    public function waitForEvent(
        string|StepOptions $id,
        string $event,
        int|float|string|\DateInterval|\DateTimeInterface $timeout,
        ?string $match = null,
        ?string $if = null
    ): ?array {
        if ($match !== null && $if !== null) {
            throw new \InvalidArgumentException(
                'waitForEvent() takes `match` or `if`, not both',
                7301
            );
        }

        $options = StepOptions::from($id);
        $opts    = ['timeout' => $this->duration->toTimeString($timeout)];
        if ($match !== null && $match !== '') {
            $opts['if'] = "event.$match == async.$match";
        } elseif ($if !== null && $if !== '') {
            $opts['if'] = $if;
        }

        $result = $this->engine->step(new StepOp(
            id: $options->id,
            op: OpCode::WaitForEvent,
            name: $event,
            display_name: $options->name ?? $options->id,
            opts: $opts
        ));

        return is_array($result) ? $result : null;
    }

    /**
     * Runs another function and returns what it returned
     *
     * @param string|StepOptions                                     $id
     *        Step ID or options
     * @param InngestFunction|FunctionReference                      $function
     *        Function to run
     * @param array<mixed>|null                                      $data
     *        Event data for the invoked function
     * @param string|null                                            $v
     *        Event schema version
     * @param EventMeta|null                                         $meta
     *        Event session meta
     * @param int|float|string|\DateInterval|\DateTimeInterface|null $timeout
     *        How long to wait for it to finish
     *
     * @throws \DealNews\Inngest\Error\StepError When the invoked function
     *                                           failed or timed out
     * @throws \InvalidArgumentException         When the meta is invalid
     *
     * @return mixed The invoked function's return value
     */
    public function invoke(
        string|StepOptions $id,
        InngestFunction|FunctionReference $function,
        ?array $data = null,
        ?string $v = null,
        ?EventMeta $meta = null,
        int|float|string|\DateInterval|\DateTimeInterface|null $timeout = null
    ): mixed {
        $options = StepOptions::from($id);

        $payload = [];
        if ($data !== null) {
            $payload['data'] = $data === [] ? new \stdClass() : $data;
        }
        if ($v !== null) {
            $payload['v'] = $v;
        }
        $meta = $meta?->normalized();
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        if ($function instanceof InngestFunction) {
            $function_id = $function->absoluteId();
        } elseif ($function->app_id !== null && $function->app_id !== '') {
            $function_id = $function->absoluteId($function->app_id);
        } else {
            $function_id = $function->absoluteId(
                $this->requireClient('invoke')->getAppId()
            );
        }

        $opts = [
            'payload'     => $payload === [] ? new \stdClass() : $payload,
            'function_id' => $function_id,
        ];
        if ($timeout !== null) {
            $opts['timeout'] = $this->duration->toTimeString($timeout);
        }

        return $this->engine->step(new StepOp(
            id: $options->id,
            op: OpCode::InvokeFunction,
            display_name: $options->name ?? $options->id,
            opts: $opts
        ));
    }

    /**
     * Sends events, exactly once
     *
     * Use this instead of `Client::send()` inside functions, so a retry
     * doesn't send the events again.
     *
     * @param string|StepOptions $id     Step ID or options
     * @param Event|array<mixed> $events Event, event array, or a list of
     *                                   either
     *
     * @throws \DealNews\Inngest\Error\StepError When sending failed for good
     *
     * @return SendEventResult
     */
    public function sendEvent(
        string|StepOptions $id,
        Event|array $events
    ): SendEventResult {
        $options = StepOptions::from($id);
        $client  = $this->requireClient('sendEvent');

        $result = $this->engine->step(
            new StepOp(
                id: $options->id,
                op: OpCode::StepPlanned,
                name: 'sendEvent',
                display_name: $options->name ?? $options->id,
                opts: ['type' => 'step.sendEvent']
            ),
            static fn () => $client->send($events)
        );

        return $result instanceof SendEventResult ?
            $result :
            SendEventResult::fromArray(is_array($result) ? $result : []);
    }

    /**
     * Sends a signal to the run waiting for it, exactly once
     *
     * @param string|StepOptions $id     Step ID or options
     * @param string             $signal Signal name
     * @param mixed              $data   Data for the waiting run
     *
     * @throws \DealNews\Inngest\Error\StepError When sending failed for good
     *
     * @return SendSignalResult `run_id` is null when no run was waiting
     */
    public function sendSignal(
        string|StepOptions $id,
        string $signal,
        mixed $data = null
    ): SendSignalResult {
        $options = StepOptions::from($id);
        $client  = $this->requireClient('sendSignal');

        $result = $this->engine->step(
            new StepOp(
                id: $options->id,
                op: OpCode::StepPlanned,
                name: 'sendSignal',
                display_name: $options->name ?? $options->id,
                opts: ['type' => 'step.sendSignal', 'signal' => $signal]
            ),
            static fn () => $client->sendSignal($signal, $data)
        );

        return $result instanceof SendSignalResult ?
            $result :
            SendSignalResult::fromArray(is_array($result) ? $result : []);
    }

    /**
     * Waits for a signal, or gives up after a timeout
     *
     * @param string|StepOptions                                $id
     *        Step ID or options
     * @param string                                            $signal
     *        Signal name
     * @param int|float|string|\DateInterval|\DateTimeInterface $timeout
     *        How long to wait
     * @param SignalConflict                                    $on_conflict
     *        What to do when another run waits for the same signal
     *
     * @return array<mixed>|null `['signal' => ..., 'data' => ...]`, or null
     *                           on timeout
     */
    public function waitForSignal(
        string|StepOptions $id,
        string $signal,
        int|float|string|\DateInterval|\DateTimeInterface $timeout,
        SignalConflict $on_conflict
    ): ?array {
        $options = StepOptions::from($id);

        $result = $this->engine->step(new StepOp(
            id: $options->id,
            op: OpCode::WaitForSignal,
            name: $signal,
            display_name: $options->name ?? $options->id,
            opts: [
                'signal'   => $signal,
                'timeout'  => $this->duration->toTimeString($timeout),
                'conflict' => $on_conflict->value,
            ]
        ));

        return is_array($result) ? $result : null;
    }

    /**
     * Makes an HTTP request through Inngest, as a step
     *
     * Port of JS `step.fetch`. Inngest makes the request, so the function
     * isn't running while it waits. The step ID is `step.fetch: <host>`.
     * Inside a running step, the request is made directly with the
     * client's Guzzle instead (like JS's fallback to the global `fetch`).
     *
     * @param string                $url     URL
     * @param string                $method  HTTP method
     * @param array<string, string> $headers Request headers
     * @param string|null           $body    Request body
     *
     * @throws \InvalidArgumentException When the URL has no host (7303)
     *
     * @return ResponseInterface The response; error statuses don't throw
     */
    public function fetch(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null
    ): ResponseInterface {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException(
                "step->fetch() needs an absolute URL; got \"$url\"",
                7303
            );
        }

        if ($this->engine->isExecutingStep()) {
            $request = ['headers' => $headers, 'http_errors' => false];
            if ($body !== null) {
                $request['body'] = $body;
            }

            $result = $this->requireClient('fetch')
                ->getHttpClient()
                ->request($method, $url, $request);
        } else {
            $sent_headers = [];
            foreach ($headers as $name => $value) {
                $sent_headers[strtolower((string)$name)] = $value;
            }

            $opts = [
                'url'     => $url,
                'method'  => $method,
                'headers' => $sent_headers === [] ?
                    new \stdClass() :
                    $sent_headers,
            ];
            if ($body !== null) {
                $opts['body'] = $body;
            }

            $id     = 'step.fetch: ' . strtolower($host);
            $result = static::gatewayResponse($this->engine->step(new StepOp(
                id: $id,
                op: OpCode::Gateway,
                display_name: $id,
                opts: $opts
            )));
        }

        return $result;
    }

    /**
     * Builds the op for `run()` and `ai->wrap()`
     *
     * @param StepOptions $options Step ID and name
     * @param list<mixed> $input   Arguments for the step's code
     * @param string|null $type    Step sub-type (`opts.type`)
     *
     * @return StepOp
     */
    public static function runOp(
        StepOptions $options,
        array $input,
        ?string $type = null
    ): StepOp {
        $opts = [];
        if ($input !== []) {
            $opts['input'] = $input;
        }
        if ($type !== null) {
            $opts['type'] = $type;
        }

        return new StepOp(
            id: $options->id,
            op: OpCode::StepPlanned,
            name: $options->id,
            display_name: $options->name ?? $options->id,
            opts: $opts
        );
    }

    /**
     * Turns a Gateway step result into a response
     *
     * @param mixed $data `{status_code, headers, body}` from Inngest
     *
     * @return ResponseInterface
     */
    protected static function gatewayResponse(mixed $data): ResponseInterface {
        $data = is_array($data) ? $data : [];

        return new Response(
            (int)($data['status_code'] ?? 200),
            is_array($data['headers'] ?? null) ? $data['headers'] : [],
            is_string($data['body'] ?? null) ? $data['body'] : ''
        );
    }

    /**
     * Parses a sleepUntil() time
     *
     * @param \DateTimeInterface|string $time Time
     *
     * @throws \InvalidArgumentException When it can't be parsed (7302)
     *
     * @return \DateTimeInterface
     */
    protected function parseDate(
        \DateTimeInterface|string $time
    ): \DateTimeInterface {
        $result = $time;

        if (is_string($time)) {
            try {
                if (trim($time) === '') {
                    throw new \InvalidArgumentException('Empty date string');
                }
                $result = new \DateTimeImmutable($time);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException(
                    'Invalid date or date string passed to sleepUntil: ' .
                    $time,
                    7302,
                    $e
                );
            }
        }

        return $result;
    }

    /**
     * Returns the client, which some tools need
     *
     * @param string $tool Tool name, for the error
     *
     * @throws \LogicException When the tools were built without a client
     *                         (7304)
     *
     * @return Client
     */
    protected function requireClient(string $tool): Client {
        if ($this->client === null) {
            throw new \LogicException(
                "step->$tool() needs the Inngest client, but these step " .
                'tools were created without one',
                7304
            );
        }

        return $this->client;
    }
}
