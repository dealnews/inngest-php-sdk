<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

use DealNews\Inngest\Support\Duration;

/**
 * Throw this to retry a step or function after a specific delay
 *
 * Port of the JS SDK's `RetryAfterError`. Inngest receives the delay in the
 * `Retry-After` response header, either as whole seconds or an ISO date.
 *
 * Usage:
 *
 * ```php
 * throw new RetryAfterError('Rate limited', '30s');
 * throw new RetryAfterError('Try tomorrow', new \DateTimeImmutable('+1 day'));
 * ```
 *
 * Edge cases:
 *
 * - Numbers are milliseconds (same as JS) and round **up** to whole
 *   seconds: `1500` becomes `"2"`.
 * - Strings accept anything `Duration` parses (`"30s"`, `"1 hour"`,
 *   `"1h30m"`).
 *
 * @package DealNews\Inngest\Error
 */
class RetryAfterError extends \RuntimeException {

    /**
     * When to retry: whole seconds, or an ISO 8601 date
     *
     * @var string
     */
    protected string $retry_after;

    /**
     * Creates the error
     *
     * @param string                              $message     Message
     * @param int|float|string|\DateTimeInterface $retry_after Delay in ms,
     *                                                         a duration
     *                                                         string, or a
     *                                                         date
     * @param \Throwable|null                     $previous    Cause
     *
     * @throws \InvalidArgumentException When the delay can't be understood
     */
    public function __construct(
        string $message,
        int|float|string|\DateTimeInterface $retry_after,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);

        if ($retry_after instanceof \DateTimeInterface) {
            $this->retry_after = Duration::toIsoString($retry_after);
        } else {
            $milliseconds = (new Duration())->toMilliseconds($retry_after);
            if (!is_finite($milliseconds)) {
                throw new \InvalidArgumentException(
                    'retryAfter must be a number of milliseconds, a duration ' .
                    'string, or a date',
                    3001
                );
            }

            $this->retry_after = (string)(int)ceil($milliseconds / 1000);
        }
    }

    /**
     * Returns the `Retry-After` value: whole seconds or an ISO date
     *
     * @return string
     */
    public function getRetryAfter(): string {
        return $this->retry_after;
    }
}
