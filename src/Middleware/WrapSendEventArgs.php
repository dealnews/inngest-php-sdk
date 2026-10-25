<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Functions\InngestFunction;

/**
 * Argument of `Middleware::wrapSendEvent()`
 *
 * @package DealNews\Inngest\Middleware
 */
class WrapSendEventArgs {

    /**
     * Creates the arguments
     *
     * @param list<Event>          $events   Events being sent
     * @param InngestFunction|null $function Function sending them, or null
     *                                       for `Client::send()`
     * @param \Closure             $next     Runs the rest of the chain
     */
    public function __construct(
        public readonly array $events,
        public readonly ?InngestFunction $function,
        protected \Closure $next
    ) {
    }

    /**
     * Runs the rest of the chain (ending with the HTTP request)
     *
     * @return SendEventResult
     */
    public function next(): SendEventResult {
        return ($this->next)();
    }
}
