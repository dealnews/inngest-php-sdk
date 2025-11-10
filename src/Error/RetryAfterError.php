<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

use DateTime;

/**
 * Exception that specifies when to retry a function or step
 */
class RetryAfterError extends InngestException
{
    /**
     * @param int|DateTime|string $retry_after Seconds, DateTime, or ISO 8601 string
     */
    public function __construct(
        string $message,
        protected int|DateTime|string $retry_after,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getRetryAfter(): int|DateTime|string
    {
        return $this->retry_after;
    }

    public function getRetryAfterHeader(): string
    {
        if ($this->retry_after instanceof DateTime) {
            return $this->retry_after->format(DateTime::RFC3339);
        }

        if (is_int($this->retry_after)) {
            return (string) $this->retry_after;
        }

        return $this->retry_after;
    }
}
