<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

/**
 * Throw this to stop Inngest from retrying a step or function
 *
 * Use it when retrying can't help, such as invalid input or a missing
 * record. Port of the JS SDK's `NonRetriableError`.
 *
 * Usage:
 *
 * ```php
 * if ($user === null) {
 *     throw new NonRetriableError('User not found');
 * }
 * ```
 *
 * @package DealNews\Inngest\Error
 */
class NonRetriableError extends \RuntimeException {

    /**
     * Creates the error
     *
     * @param string          $message  Error message
     * @param \Throwable|null $previous The underlying cause, if any
     */
    public function __construct(
        string $message = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
