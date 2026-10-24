<?php

declare(strict_types=1);

namespace DealNews\Inngest\Event;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * An event sent to or received from Inngest
 *
 * Port of the JS `EventPayload`.
 *
 * Usage:
 *
 * ```php
 * $client->send(new Event(
 *     name: 'app/user.signup',
 *     data: ['user_id' => 123],
 * ));
 * ```
 *
 * Edge cases:
 *
 * - Empty `data` is sent as `{}`.
 * - `ts` is milliseconds since the epoch; `send()` fills it in when unset.
 * - Set `id` to make sending idempotent: Inngest ignores later events
 *   with the same ID.
 *
 * @package DealNews\Inngest\Event
 */
class Event extends ValueObject {

    protected const OBJECT_KEYS = [
        'data',
    ];

    /**
     * Creates an event
     *
     * @param string         $name Event name, e.g. `app/user.signup`
     * @param array<mixed>   $data Event data
     * @param string|null    $id   Idempotency ID
     * @param int|null       $ts   Timestamp in milliseconds
     * @param string|null    $v    Event schema version
     * @param EventMeta|null $meta Session meta
     */
    public function __construct(
        public string $name = '',
        public array $data = [],
        public ?string $id = null,
        public ?int $ts = null,
        public ?string $v = null,
        public ?EventMeta $meta = null
    ) {
    }
}
