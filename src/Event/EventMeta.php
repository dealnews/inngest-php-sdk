<?php

declare(strict_types=1);

namespace DealNews\Inngest\Event;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Event meta shared across runs triggered by an event
 *
 * Currently holds sessions: keys are session names, values are session
 * IDs used to group runs. Port of the JS `EventMeta` send-side shape.
 *
 * Usage:
 *
 * ```php
 * new EventMeta(sessions: ['conversation_id' => 'c_123', 'priority' => 1]);
 * ```
 *
 * Edge cases:
 *
 * - Numbers become strings when sent (`1` → `"1"`).
 * - A `null` value is a tombstone that cuts an inherited session of that
 *   key; it's sent as `null`.
 * - Automatic session propagation from parent runs isn't supported yet
 *   (deferred in the plan).
 *
 * @package DealNews\Inngest\Event
 */
class EventMeta extends ValueObject {

    /**
     * Creates the meta
     *
     * @param array<string, string|int|float|null>|null $sessions Sessions
     */
    public function __construct(public ?array $sessions = null) {
    }

    /**
     * Returns a validated copy ready to send, or null when empty
     *
     * Port of the JS `normalizeEventMeta` for the manual sessions layer.
     *
     * @throws \InvalidArgumentException When a session key or value is
     *                                   invalid
     *
     * @return self|null
     */
    public function normalized(): ?self {
        $result = null;

        if (!empty($this->sessions)) {
            $sessions = [];
            foreach ($this->sessions as $key => $value) {
                $sessions[(string)$key] = static::normalizeSession(
                    (string)$key,
                    $value
                );
            }

            $result = new self($sessions);
        }

        return $result;
    }

    /**
     * Validates and stringifies one session value
     *
     * @param string $key   Session key
     * @param mixed  $value Session ID
     *
     * @throws \InvalidArgumentException When invalid
     *
     * @return string|null
     */
    protected static function normalizeSession(
        string $key,
        mixed $value
    ): ?string {
        if ($key === '') {
            throw new \InvalidArgumentException(
                'Event session keys cannot be empty',
                8001
            );
        }

        if ($value === null) {
            $result = null;
        } elseif (is_string($value) || is_int($value) || is_float($value)) {
            if (is_float($value) && !is_finite($value)) {
                throw new \InvalidArgumentException(
                    "Event session \"$key\" must be a finite number",
                    8003
                );
            }

            $result = (string)$value;
            if ($result === '') {
                throw new \InvalidArgumentException(
                    "Event session \"$key\" cannot have an empty ID",
                    8004
                );
            }
        } else {
            throw new \InvalidArgumentException(
                "Event session \"$key\" must be a string, number, or null",
                8002
            );
        }

        return $result;
    }
}
