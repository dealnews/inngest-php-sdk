<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

/**
 * Converts errors to and from Inngest's serialized error format
 *
 * Port of `serializeError`, `deserializeError`, and `isSerializedError`
 * from the JS SDK (helpers/errors.ts).
 *
 * Usage:
 *
 * ```php
 * $serializer = new ErrorSerializer();
 * $wire       = $serializer->serialize($exception);  // SerializedError
 * $error      = $serializer->deserialize($wire_data); // \Throwable
 * ```
 *
 * Edge cases:
 *
 * - Values that aren't errors (strings, arrays, null) still serialize: the
 *   message becomes the value as a string or JSON, like JS.
 * - An error with an empty message gets the message `{}` (the JSON of the
 *   error object), matching JS.
 * - Causes (`getPrevious()`) serialize recursively, up to 5 levels.
 * - Deserializing gives a `NonRetriableError` for that name and a
 *   `RemoteError` for everything else.
 *
 * @package DealNews\Inngest\Error
 */
class ErrorSerializer {

    /**
     * Maximum depth of causes to serialize, same as JS
     *
     * @var int
     */
    public const MAX_CAUSE_DEPTH = 5;

    /**
     * Error properties copied from error-like arrays, in wire order
     *
     * @var list<string>
     */
    public const ERROR_KEYS = ['name', 'message', 'stack', 'code', 'cause'];

    /**
     * Serializes any value to a SerializedError
     *
     * @param mixed $subject A Throwable, a serialized error (array, JSON
     *                       string, or SerializedError), or any other value
     *
     * @return SerializedError
     */
    public function serialize(mixed $subject): SerializedError {
        return $this->serializeValue($subject, false, 0);
    }

    /**
     * Returns the SerializedError if the value already is one
     *
     * Accepts a SerializedError, an array with `__serialized: true`, or a
     * JSON string of one (which must also have string name, message, and
     * stack).
     *
     * @param mixed $value Value to check
     *
     * @return SerializedError|null
     */
    public function isSerialized(mixed $value): ?SerializedError {
        $result = null;

        if ($value instanceof SerializedError) {
            $result = $value;
        } elseif (
            is_array($value) &&
            ($value['__serialized'] ?? null) === true
        ) {
            $result = SerializedError::fromArray($value);
        } elseif (is_string($value)) {
            $decoded = json_decode($value, true);
            if (
                is_array($decoded)                          &&
                ($decoded['__serialized'] ?? null) === true &&
                is_string($decoded['name'] ?? null)         &&
                is_string($decoded['message'] ?? null)      &&
                is_string($decoded['stack'] ?? null)
            ) {
                $result = SerializedError::fromArray($decoded);
            }
        }

        return $result;
    }

    /**
     * Rebuilds a Throwable from serialized error data
     *
     * @param array<mixed>|SerializedError $subject Serialized error data
     *
     * @return \Throwable
     */
    public function deserialize(array|SerializedError $subject): \Throwable {
        $data = static::errorData($subject);

        return $data === null ?
            $this->unknownError() :
            $this->buildError($data);
    }

    /**
     * Deserializes an error's cause, passing non-error values through
     *
     * @param mixed $cause Serialized cause data
     *
     * @return mixed A Throwable when the cause was an error, otherwise the
     *               value unchanged
     */
    public function deserializeCause(mixed $cause): mixed {
        $data = static::errorData($cause);

        return $data === null ? $cause : $this->buildError($data);
    }

    /**
     * Serializes a value, optionally passing non-errors through untouched
     *
     * @param mixed $subject       Value to serialize
     * @param bool  $allow_unknown Return non-error values as-is (for causes)
     * @param int   $depth         Current cause depth
     *
     * @return mixed SerializedError, or the original value when
     *               $allow_unknown is set and it isn't error-like
     */
    protected function serializeValue(
        mixed $subject,
        bool $allow_unknown,
        int $depth
    ): mixed {
        $existing = $this->isSerialized($subject);

        if ($existing !== null) {
            $result = $existing;
        } elseif ($subject instanceof \Throwable) {
            $result = $this->fromThrowable($subject, $depth);
        } elseif (is_array($subject) || is_object($subject)) {
            $result = $this->fromErrorLike($subject, $allow_unknown, $depth);
        } elseif ($allow_unknown) {
            $result = $subject;
        } else {
            $result          = new SerializedError();
            $result->message = is_string($subject) ?
                $subject :
                static::encode($subject);
        }

        return $result;
    }

    /**
     * Serializes a Throwable
     *
     * @param \Throwable $error Error to serialize
     * @param int        $depth Current cause depth
     *
     * @return SerializedError
     */
    protected function fromThrowable(
        \Throwable $error,
        int $depth
    ): SerializedError {
        $result          = new SerializedError();
        $result->name    = static::errorName($error);
        $result->message = $error->getMessage() !== '' ?
            $error->getMessage() :
            static::encode($error);

        if ($error instanceof RemoteError) {
            $result->stack = $error->getErrorStack() ?? '';
            $result->code  = $error->getErrorCode();
            $cause         = $error->getPrevious() ?? $error->getRawCause();
        } else {
            $result->stack = static::formatStack($error, $result->name);
            $result->code  = $error->getCode() !== 0 ? $error->getCode() : null;
            $cause         = $error->getPrevious();
        }

        if ($cause !== null && $depth < self::MAX_CAUSE_DEPTH) {
            $result->cause = $this->serializeValue($cause, true, $depth + 1);
        }

        return $result;
    }

    /**
     * Serializes an array or non-Throwable object
     *
     * @param array<mixed>|object $subject       Value to serialize
     * @param bool                $allow_unknown Pass through if not error-like
     * @param int                 $depth         Current cause depth
     *
     * @return mixed
     */
    protected function fromErrorLike(
        array|object $subject,
        bool $allow_unknown,
        int $depth
    ): mixed {
        $fields = is_array($subject) ? $subject : get_object_vars($subject);
        $picked = [];
        foreach (self::ERROR_KEYS as $key) {
            if (array_key_exists($key, $fields)) {
                $picked[$key] = $fields[$key];
            }
        }

        if ($allow_unknown && empty($picked['name'])) {
            $result = $subject;
        } else {
            $result          = new SerializedError();
            $result->name    = static::stringOr(
                $picked['name'] ?? null,
                'Error'
            );
            $result->message = static::stringOr(
                $picked['message'] ?? null,
                static::encode($subject)
            );
            $result->stack = static::stringOr($picked['stack'] ?? null, '');

            $code = $picked['code'] ?? null;
            if (is_int($code) || is_string($code)) {
                $result->code = $code;
            }

            if (
                !empty($picked['cause']) &&
                $depth < self::MAX_CAUSE_DEPTH
            ) {
                $result->cause = $this->serializeValue(
                    $picked['cause'],
                    true,
                    $depth + 1
                );
            }
        }

        return $result;
    }

    /**
     * Returns the value as error data, or null if it isn't error-shaped
     *
     * Error data needs both `name` and `message` keys, like JS.
     *
     * @param mixed $subject Candidate value
     *
     * @return array<mixed>|null
     */
    protected static function errorData(mixed $subject): ?array {
        if ($subject instanceof SerializedError) {
            $subject = $subject->toArray();
        }

        $result = null;
        if (
            is_array($subject)                 &&
            array_key_exists('name', $subject) &&
            array_key_exists('message', $subject)
        ) {
            $result = $subject;
        }

        return $result;
    }

    /**
     * Builds a Throwable from validated error data
     *
     * @param array<mixed> $data Error data with name and message keys
     *
     * @return \Throwable
     */
    protected function buildError(array $data): \Throwable {
        $cause = isset($data['cause']) ?
            $this->deserializeCause($data['cause']) :
            null;

        $previous  = $cause instanceof \Throwable ? $cause : null;
        $raw_cause = $previous === null ? $cause : null;
        $name      = static::stringOr($data['name'], 'Error');
        $message   = static::stringOr($data['message'], '');

        if ($name === 'NonRetriableError') {
            $result = new NonRetriableError($message, $previous);
        } else {
            $code   = $data['code']  ?? null;
            $stack  = $data['stack'] ?? null;
            $result = new RemoteError(
                $name,
                $message,
                is_string($stack) ? $stack : null,
                is_int($code) || is_string($code) ? $code : null,
                $previous,
                $raw_cause
            );
        }

        return $result;
    }

    /**
     * The error returned when serialized data can't be understood
     *
     * @return RemoteError
     */
    protected function unknownError(): RemoteError {
        return new RemoteError(
            'Error',
            'Unknown error; could not reserialize'
        );
    }

    /**
     * Returns the error name for a Throwable
     *
     * Rebuilt remote errors keep their original name; everything else uses
     * the short class name (JS uses the constructor name the same way).
     *
     * @param \Throwable $error Error
     *
     * @return string
     */
    protected static function errorName(\Throwable $error): string {
        if ($error instanceof RemoteError) {
            $name = $error->getErrorName();
        } else {
            $parts = explode('\\', $error::class);
            $name  = end($parts);
        }

        return $name;
    }

    /**
     * Builds a JS-like stack string for a Throwable
     *
     * @param \Throwable $error Error
     * @param string     $name  Error name
     *
     * @return string
     */
    protected static function formatStack(
        \Throwable $error,
        string $name
    ): string {
        return $name . ': ' . $error->getMessage() .
               ' in ' . $error->getFile() . ':' . $error->getLine() .
               "\nStack trace:\n" . $error->getTraceAsString();
    }

    /**
     * Returns a non-empty string value, or the fallback
     *
     * @param mixed  $value    Candidate value
     * @param string $fallback Fallback
     *
     * @return string
     */
    protected static function stringOr(mixed $value, string $fallback): string {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    /**
     * JSON-encodes a value the way JS `JSON.stringify()` would
     *
     * @param mixed $value Value to encode
     *
     * @return string
     */
    protected static function encode(mixed $value): string {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $json === false ?
            'Unknown error; error serialization could not find a message.' :
            $json;
    }
}
