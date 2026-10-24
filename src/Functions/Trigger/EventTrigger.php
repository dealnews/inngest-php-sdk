<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Trigger;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Runs a function when a matching event is received
 *
 * Usage:
 *
 * ```php
 * new EventTrigger('app/user.created');
 * new EventTrigger('app/user.created', if: "event.data.plan == 'pro'");
 * ```
 *
 * @package DealNews\Inngest\Functions\Trigger
 */
class EventTrigger extends ValueObject implements Trigger {

    protected const WIRE_KEYS = [
        'if' => 'expression',
    ];

    /**
     * Creates the trigger
     *
     * @param string      $event Event name
     * @param string|null $if    Optional expression the event must match
     *                           (sent as `expression`)
     */
    public function __construct(
        public string $event,
        public ?string $if = null
    ) {
    }
}
