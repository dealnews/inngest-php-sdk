<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Function\FunctionContext;

/**
 * Abstract base class for Inngest middleware
 *
 * Middleware allows hooking into the function execution lifecycle to:
 * - Transform input data before execution
 * - Perform logging or monitoring
 * - Modify function results
 * - Intercept events being sent
 *
 * All lifecycle methods are no-ops in this base class and should be overridden
 * as needed by concrete middleware implementations.
 *
 * ## Lifecycle Order
 *
 * 1. `transformInput()` - Before handler, can mutate memoized step data
 * 2. `afterMemoization()` - When first encountering an unmemoized step
 * 3. `beforeExecution()` - Immediately after transformInput
 * 4. Handler execution
 * 5. `afterExecution()` - After handler returns
 * 6. `transformOutput()` - Before response building, can mutate result/error
 * 7. `beforeResponse()` - Before building response array
 * 8. `beforeSendEvents()` - Before sending events via client
 * 9. `afterSendEvents()` - After events are sent
 *
 * ## Usage Example
 *
 * ```php
 * class LoggingMiddleware extends AbstractMiddleware
 * {
 *     public function beforeExecution(FunctionContext $ctx): void
 *     {
 *         echo "Executing function for event: " . $ctx->getEvent()->getName() . "\n";
 *     }
 *
 *     public function afterExecution(FunctionContext $ctx): void
 *     {
 *         echo "Function execution completed\n";
 *     }
 * }
 * ```
 */
abstract class AbstractMiddleware
{
    /**
     * Transform input data before handler execution
     *
     * Called before the function handler runs. Can mutate the steps array
     * to modify memoized step data that will be used by the handler.
     *
     * @param FunctionContext $ctx The function execution context
     * @param array<string, mixed> &$steps Memoized step data (pass by reference)
     *
     * @return void
     */
    public function transformInput(FunctionContext $ctx, array &$steps): void
    {
        // No-op in base class
    }

    /**
     * Called after memoization when an unmemoized step is encountered
     *
     * This hook is triggered when a step is encountered for the first time
     * during function execution.
     *
     * @param FunctionContext $ctx The function execution context
     *
     * @return void
     */
    public function afterMemoization(FunctionContext $ctx): void
    {
        // No-op in base class
    }

    /**
     * Called immediately before the handler function is executed
     *
     * @param FunctionContext $ctx The function execution context
     *
     * @return void
     */
    public function beforeExecution(FunctionContext $ctx): void
    {
        // No-op in base class
    }

    /**
     * Called immediately after the handler function completes
     *
     * @param FunctionContext $ctx The function execution context
     *
     * @return void
     */
    public function afterExecution(FunctionContext $ctx): void
    {
        // No-op in base class
    }

    /**
     * Transform the function output before response building
     *
     * Called before building the response array. Allows mutating the result,
     * error, and step data to transform what is returned to the server.
     *
     * @param FunctionContext $ctx The function execution context
     * @param mixed &$result The function result (pass by reference)
     * @param \Throwable|null &$error Any exception thrown during execution (pass by reference)
     * @param array<string, mixed>|null &$step_data The step data (pass by reference)
     *
     * @return void
     */
    public function transformOutput(FunctionContext $ctx, mixed &$result, ?\Throwable &$error, ?array &$step_data): void
    {
        // No-op in base class
    }

    /**
     * Called before building the response array for the server
     *
     * @param array<string, mixed> &$response The response array (pass by reference)
     *
     * @return void
     */
    public function beforeResponse(array &$response): void
    {
        // No-op in base class
    }

    /**
     * Called before sending events via the Inngest client
     *
     * @param array<array<string, mixed>> &$events The events to be sent (pass by reference)
     *
     * @return void
     */
    public function beforeSendEvents(array &$events): void
    {
        // No-op in base class
    }

    /**
     * Called after events have been sent via the Inngest client
     *
     * @param array<string> $event_ids The IDs of events that were sent
     * @param \Throwable|null $error Any error that occurred during sending
     *
     * @return void
     */
    public function afterSendEvents(array $event_ids, ?\Throwable $error = null): void
    {
        // No-op in base class
    }
}
