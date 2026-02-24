<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Represents priority configuration for an Inngest function
 *
 * Priority allows you to dynamically execute some runs ahead of or behind
 * others based on event data. This is useful for prioritizing work based on
 * user subscription levels, critical tasks, or onboarding workflows.
 *
 * ## Usage
 *
 * ```php
 * // Simple: Use priority value directly from event
 * $priority = new Priority(run: 'event.data.priority');
 *
 * // Conditional: Prioritize enterprise accounts
 * $priority = new Priority(
 *     run: 'event.data.account_type == "enterprise" ? 120 : 0'
 * );
 *
 * // Negative priority: Delay free tier users
 * $priority = new Priority(
 *     run: 'event.data.plan == "free" ? -60 : 0'
 * );
 * ```
 *
 * ## Edge Cases
 *
 * - The expression must return an integer between -600 and 600
 * - Positive numbers increase priority (run ahead of jobs enqueued up to N seconds ago)
 * - Negative numbers delay execution (defer by N seconds)
 * - Values outside the range are automatically clipped by Inngest
 * - Invalid expressions evaluate to 0 on the server (no priority)
 * - Expression uses CEL (Common Expression Language) syntax
 */
class Priority
{
    /**
     * @param string $run CEL expression that evaluates to an integer priority factor (-600 to 600)
     *
     * @throws \InvalidArgumentException If expression is empty or invalid
     */
    public function __construct(
        protected string $run
    ) {
        $this->validateExpression($run);
    }

    /**
     * Validate the CEL expression
     *
     * Performs basic validation to catch common errors. Full CEL syntax
     * validation happens server-side by Inngest.
     *
     * @param string $expression The CEL expression to validate
     *
     * @throws \InvalidArgumentException If expression is invalid
     *
     * @return void
     */
    protected function validateExpression(string $expression): void
    {
        // Empty check
        if (trim($expression) === '') {
            throw new \InvalidArgumentException(
                'Priority expression cannot be empty'
            );
        }

        // Basic length check (reasonable upper bound)
        if (strlen($expression) > 1000) {
            throw new \InvalidArgumentException(
                'Priority expression is too long (max 1000 characters)'
            );
        }

        // Check for common CEL patterns (this is basic sanity checking)
        // Valid CEL can contain: alphanumeric, dots, operators, quotes, etc.
        if (!preg_match('/^[\w\s\.\-\+\*\/\(\)\[\]\{\}==!=<>&|!?:,"\']+$/', $expression)) {
            throw new \InvalidArgumentException(
                'Priority expression contains invalid characters. '.
                'Must use CEL syntax with alphanumeric, operators, '.
                'dots, quotes, and parentheses.'
            );
        }
    }

    /**
     * Get the priority expression
     *
     * @return string CEL expression for priority calculation
     */
    public function getRun(): string
    {
        return $this->run;
    }

    /**
     * Convert to array for sync payload
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run' => $this->run,
        ];
    }
}
