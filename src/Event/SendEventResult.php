<?php

declare(strict_types=1);

namespace DealNews\Inngest\Event;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Result of sending events
 *
 * @package DealNews\Inngest\Event
 */
class SendEventResult extends ValueObject {

    /**
     * Creates the result
     *
     * @param list<string> $ids IDs of the sent events, in send order
     */
    public function __construct(public array $ids = []) {
    }
}
