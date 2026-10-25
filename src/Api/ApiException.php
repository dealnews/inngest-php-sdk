<?php

declare(strict_types=1);

namespace DealNews\Inngest\Api;

/**
 * Thrown when a request to Inngest fails
 *
 * Codes:
 * - 6001: transport failure (couldn't connect, timeout, ...)
 * - 6002: Inngest returned an error response
 * - 6003: Inngest returned a response we couldn't parse
 * - 6004: sending a signal failed
 * - 6005: a checkpoint failed
 * - 6006: a checkpoint was stale (StaleDispatchError, HTTP 409)
 *
 * @package DealNews\Inngest\Api
 */
class ApiException extends \RuntimeException {

    /**
     * Creates the exception
     *
     * @param string          $message     Error message
     * @param int             $code        Error code (see class docs)
     * @param int|null        $http_status HTTP status, when there was a
     *                                     response
     * @param \Throwable|null $previous    Underlying error
     */
    public function __construct(
        string $message,
        int $code,
        protected ?int $http_status = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Returns the HTTP status of the failed response, if there was one
     *
     * @return int|null
     */
    public function getHttpStatus(): ?int {
        return $this->http_status;
    }
}
