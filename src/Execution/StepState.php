<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

/**
 * What Inngest remembers about one step of a run
 *
 * Port of the JS `stepSchema` (api/schema.ts). A step's state is one of:
 *
 * - `data`: the step finished; its result
 * - `error`: the step failed for good; its serialized error
 * - `input`: the step hasn't run, but its input was changed (replay)
 *
 * Edge cases:
 *
 * - Anything that isn't exactly `{data}`, `{error}`, or `{input}` (each with
 *   an optional matching `type`) is taken as data as a whole. Inngest sends
 *   some step results (sleeps, waits) that way.
 * - An object with no `data`, `input`, or `error`, like `{}`, counts as an
 *   error with no details (JS `jsonErrorSchema` falls back to `{}`).
 *
 * @package DealNews\Inngest\Execution
 */
class StepState {

    public const TYPE_DATA  = 'data';
    public const TYPE_ERROR = 'error';
    public const TYPE_INPUT = 'input';

    /**
     * Creates the state
     *
     * @param string $type  One of the TYPE_* constants
     * @param mixed  $data  Step result (`data` type)
     * @param mixed  $error Serialized error data (`error` type)
     * @param mixed  $input Step input (`input` type)
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $data = null,
        public readonly mixed $error = null,
        public readonly mixed $input = null
    ) {
    }

    /**
     * Builds the state from wire data
     *
     * @param mixed $value Decoded JSON; objects may be stdClass or
     *                     associative arrays
     *
     * @return self
     */
    public static function fromWire(mixed $value): self {
        $fields = static::objectFields($value);
        $result = null;

        if ($fields !== null) {
            $type = $fields['type'] ?? null;
            $keys = array_keys($fields);

            if (static::matches($keys, $type, 'data', true)) {
                $result = new self(
                    self::TYPE_DATA,
                    data: static::toPhp($fields['data'])
                );
            } elseif (static::matches($keys, $type, 'error', false)) {
                $result = new self(
                    self::TYPE_ERROR,
                    error: static::toPhp($fields['error'] ?? null)
                );
            } elseif (static::matches($keys, $type, 'input', true)) {
                $result = new self(
                    self::TYPE_INPUT,
                    input: static::toPhp($fields['input'])
                );
            }
        }

        $result ??= new self(self::TYPE_DATA, data: static::toPhp($value));

        return $result;
    }

    /**
     * Whether the step finished (with data or an error)
     *
     * @return bool
     */
    public function isFulfilled(): bool {
        return $this->type !== self::TYPE_INPUT;
    }

    /**
     * Converts decoded JSON objects (stdClass) to associative arrays
     *
     * @param mixed $value Decoded JSON
     *
     * @return mixed
     */
    public static function toPhp(mixed $value): mixed {
        $result = $value;

        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = static::toPhp($item);
            }
        }

        return $result;
    }

    /**
     * Returns an object's fields, or null when the value isn't an object
     *
     * Non-empty associative arrays count as objects; lists don't.
     *
     * @param mixed $value Decoded JSON
     *
     * @return array<string, mixed>|null
     */
    protected static function objectFields(mixed $value): ?array {
        $result = null;

        if ($value instanceof \stdClass) {
            $result = get_object_vars($value);
        } elseif (
            is_array($value) &&
            $value !== []    &&
            !array_is_list($value)
        ) {
            $result = $value;
        }

        return $result;
    }

    /**
     * Whether fields match one strict state shape
     *
     * @param list<int|string> $keys     Field names present
     * @param mixed            $type     The `type` field, if any
     * @param string           $field    Shape's field (`data`, ...)
     * @param bool             $required Whether the field must be present
     *
     * @return bool
     */
    protected static function matches(
        array $keys,
        mixed $type,
        string $field,
        bool $required
    ): bool {
        $extra = array_diff($keys, ['type', $field]);

        return $extra === []                                      &&
               ($type === null || $type === $field)               &&
               (!in_array('type', $keys, true) || $type !== null) &&
               (!$required || in_array($field, $keys, true));
    }
}
