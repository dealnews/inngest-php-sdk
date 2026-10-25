<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Client;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Http\Response;

/**
 * Base class for middleware: hooks into runs, steps, and event sends
 *
 * Port of JS `Middleware.BaseMiddleware` (components/middleware/
 * middleware.ts). Extend it, override the hooks you need, and list the
 * class in `ClientOptions::$middleware` (every function) or
 * `FunctionOptions::$middleware` (one function).
 *
 * A new instance is created for every request Inngest makes (and for
 * every `Client::send()`), so properties are safe for per-request state.
 *
 * Hook order: `on*` and `transform*` hooks run in list order (client
 * middleware, then function middleware); `wrap*` hooks nest, so the
 * first middleware is the outermost.
 *
 * Usage:
 *
 * ```php
 * class TimingMiddleware extends Middleware {
 *     public function wrapStepHandler(WrapStepArgs $args): mixed {
 *         $start = microtime(true);
 *         try {
 *             return $args->next();
 *         } finally {
 *             $this->client->getLogger()->info('step took', [
 *                 'step' => $args->step_info->options->id,
 *                 'ms'   => (microtime(true) - $start) * 1000,
 *             ]);
 *         }
 *     }
 * }
 * ```
 *
 * Edge cases:
 *
 * - Inngest reruns the handler from the top on each request; steps that
 *   already ran return their saved result. `wrapFunctionHandler` and
 *   `wrapStep` only get a result back from `next()` when the handler or
 *   step finishes in this request. Otherwise `next()` never returns, and
 *   only `finally` blocks run (when the SDK cleans up the paused
 *   function).
 * - Errors thrown by `on*` hooks are logged and ignored. Errors thrown by
 *   `transform*` and `wrap*` hooks fail whatever they wrap.
 * - Don't change hook arguments in place; return a changed copy.
 *
 * @package DealNews\Inngest\Middleware
 */
abstract class Middleware {

    /**
     * Creates the middleware for one request
     *
     * @param Client $client Client the middleware is registered on
     */
    public function __construct(protected Client $client) {
    }

    /**
     * Names the middleware in logs
     *
     * @return string Defaults to the class name
     */
    public function getId(): string {
        return static::class;
    }

    /**
     * Called once when the middleware is added to a client or function
     *
     * For one-time setup that needs the client.
     *
     * @param Client               $client   Client
     * @param InngestFunction|null $function Function, for function
     *                                       middleware; null for client
     *                                       middleware
     *
     * @return void
     */
    public static function onRegister(
        Client $client,
        ?InngestFunction $function
    ): void {
    }

    /**
     * Changes what the handler gets; called once per request
     *
     * For dependency injection (`Context::with(extras: ...)`) or decoding
     * event data and memoized step data.
     *
     * @param FunctionInput $input Context, function, and memoized steps
     *
     * @return FunctionInput
     */
    public function transformFunctionInput(
        FunctionInput $input
    ): FunctionInput {
        return $input;
    }

    /**
     * Called once per request, when the handler has replayed every step
     * that already ran and reaches new code
     *
     * @param RunArgs $args Context and function
     *
     * @return void
     */
    public function onMemoizationEnd(RunArgs $args): void {
    }

    /**
     * Called once per run, on its first request (no saved steps, first
     * attempt)
     *
     * @param RunArgs $args Context and function
     *
     * @return void
     */
    public function onRunStart(RunArgs $args): void {
    }

    /**
     * Wraps the handler; called once per request
     *
     * Must return `$args->next()` (or a changed version of it).
     *
     * @param WrapFunctionHandlerArgs $args Context, function, and next
     *
     * @return mixed The function's output
     */
    public function wrapFunctionHandler(
        WrapFunctionHandlerArgs $args
    ): mixed {
        return $args->next();
    }

    /**
     * Called when the handler returns
     *
     * @param RunCompleteArgs $args Context, function, and output
     *
     * @return void
     */
    public function onRunComplete(RunCompleteArgs $args): void {
    }

    /**
     * Called when the handler throws
     *
     * @param RunErrorArgs $args Context, function, error, and whether this
     *                           was the last attempt
     *
     * @return void
     */
    public function onRunError(RunErrorArgs $args): void {
    }

    /**
     * Changes a step's options or input; called for every step on every
     * request
     *
     * @param StepInput $input Step options and input
     *
     * @return StepInput
     */
    public function transformStepInput(StepInput $input): StepInput {
        return $input;
    }

    /**
     * Wraps the result a step returns to the handler; called for every
     * step on every request
     *
     * For decoding step output or adding steps before or after a step
     * (step tools called here go through the other middleware, not this
     * one). Must return `$args->next()` (or a changed version of it).
     *
     * @param WrapStepArgs $args Context, function, step, and next
     *
     * @return mixed The step's result, as the handler sees it
     */
    public function wrapStep(WrapStepArgs $args): mixed {
        return $args->next();
    }

    /**
     * Called before a step's code runs (`run`, `sendEvent`, `sendSignal`,
     * `ai->wrap`)
     *
     * @param StepArgs $args Context, function, and step
     *
     * @return void
     */
    public function onStepStart(StepArgs $args): void {
    }

    /**
     * Wraps one attempt of a step's code
     *
     * For encoding step output before it goes to Inngest, or handling an
     * attempt's error. Must return `$args->next()` (or a changed version
     * of it).
     *
     * @param WrapStepArgs $args Context, function, step, and next
     *
     * @return mixed The step's output
     */
    public function wrapStepHandler(WrapStepArgs $args): mixed {
        return $args->next();
    }

    /**
     * Called when a step's code returns (never for saved results)
     *
     * @param StepCompleteArgs $args Context, function, step, and output
     *
     * @return void
     */
    public function onStepComplete(StepCompleteArgs $args): void {
    }

    /**
     * Called when a step's code throws (never for saved errors)
     *
     * @param StepErrorArgs $args Context, function, step, error, and
     *                            whether the step will be retried
     *
     * @return void
     */
    public function onStepError(StepErrorArgs $args): void {
    }

    /**
     * Changes events before they're sent (`Client::send()` and
     * `step->sendEvent()`)
     *
     * @param SendEventInput $input Events and the sending function
     *
     * @return SendEventInput
     */
    public function transformSendEvent(
        SendEventInput $input
    ): SendEventInput {
        return $input;
    }

    /**
     * Wraps sending events to Inngest
     *
     * Must return `$args->next()` (or a changed version of it).
     *
     * @param WrapSendEventArgs $args Events, function, and next
     *
     * @return SendEventResult
     */
    public function wrapSendEvent(WrapSendEventArgs $args): SendEventResult {
        return $args->next();
    }

    /**
     * Wraps an execution request (`POST`) from Inngest
     *
     * Must return `$args->next()` (or a changed version of it). The
     * response is already signed, so changing its body breaks the
     * signature.
     *
     * @param WrapRequestArgs $args Request, function, run ID, and next
     *
     * @return Response
     */
    public function wrapRequest(WrapRequestArgs $args): Response {
        return $args->next();
    }
}
