<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument and result of `Middleware::transformSendEvent()`
 *
 * Port of JS `Middleware.TransformSendEventArgs`. Return it with
 * different `events` to change what is sent.
 *
 * @package DealNews\Inngest\Middleware
 */
class SendEventInput {

    /**
     * Creates the input
     *
     * @param list<Event>          $events   Events about to be sent
     * @param InngestFunction|null $function Function sending them
     *                                       (`step->sendEvent()`), or null
     *                                       for `Client::send()`
     */
    public function __construct(
        public array $events,
        public readonly ?InngestFunction $function = null
    ) {
    }
}
