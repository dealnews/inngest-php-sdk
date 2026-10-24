<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

/**
 * An error rebuilt from serialized data sent by Inngest
 *
 * JS can set `error.name` on any `Error`; PHP can't change an exception's
 * class at runtime. So errors that come back over the wire (for example
 * the error passed to an `onFailure` handler) become a `RemoteError` that
 * keeps the original name, stack, and code.
 *
 * Usage:
 *
 * ```php
 * if (
 *     $error instanceof RemoteError &&
 *     $error->getErrorName() === 'TypeError'
 * ) {
 *     // ...
 * }
 * ```
 *
 * @package DealNews\Inngest\Error
 */
class RemoteError extends \RuntimeException {

    /**
     * Creates the error
     *
     * @param string          $error_name  Original error name
     * @param string          $message     Original message
     * @param string|null     $error_stack Original stack trace, if sent
     * @param int|string|null $error_code  Original code, if sent
     * @param \Throwable|null $previous    Cause, when it was an error
     * @param mixed           $raw_cause   Cause, when it wasn't an error
     */
    public function __construct(
        protected string $error_name,
        string $message,
        protected ?string $error_stack = null,
        protected int|string|null $error_code = null,
        ?\Throwable $previous = null,
        protected mixed $raw_cause = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Returns the original error name (e.g. `TypeError`)
     *
     * @return string
     */
    public function getErrorName(): string {
        return $this->error_name;
    }

    /**
     * Returns the original stack trace, if one was sent
     *
     * @return string|null
     */
    public function getErrorStack(): ?string {
        return $this->error_stack;
    }

    /**
     * Returns the original error code, if one was sent
     *
     * @return int|string|null
     */
    public function getErrorCode(): int|string|null {
        return $this->error_code;
    }

    /**
     * Returns a cause that wasn't an error (errors are in getPrevious())
     *
     * @return mixed
     */
    public function getRawCause(): mixed {
        return $this->raw_cause;
    }
}
