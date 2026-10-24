<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Wire format for an error sent to Inngest
 *
 * Matches the JS SDK's `SerializedError`: `name`, `message`, `stack`,
 * optional `code` and `cause`, and an `__serialized: true` marker so either
 * side can tell it apart from ordinary data.
 *
 * @package DealNews\Inngest\Error
 */
class SerializedError extends ValueObject {

    protected const WIRE_KEYS = [
        'serialized' => '__serialized',
    ];

    /**
     * Error name, e.g. `Error`, `NonRetriableError`
     *
     * @var string
     */
    public string $name = 'Error';

    /**
     * Error message
     *
     * @var string
     */
    public string $message = '';

    /**
     * Stack trace; empty when not available
     *
     * @var string
     */
    public string $stack = '';

    /**
     * Error code, when the original error had one
     *
     * @var int|string|null
     */
    public int|string|null $code = null;

    /**
     * Cause: a SerializedError, or any other value the cause was
     *
     * @var mixed
     */
    public mixed $cause = null;

    /**
     * Marker identifying this as a serialized error
     *
     * @var bool
     */
    public bool $serialized = true;
}
