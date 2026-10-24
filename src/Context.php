<?php

declare(strict_types=1);

namespace DealNews\Inngest;

use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Step\StepTools;
use Psr\Log\LoggerInterface;

/**
 * What a function handler gets: the event, run details, and step tools
 *
 * Port of the JS handler argument (`createFnArg` in execution/engine.ts).
 *
 * Usage:
 *
 * ```php
 * $inngest->createFunction($options, function (Context $ctx) {
 *     $name = $ctx->event->data['name'];
 *     return $ctx->step->run('greet', fn () => "Hello, $name");
 * });
 * ```
 *
 * @package DealNews\Inngest
 */
class Context {

    /**
     * Creates the context
     *
     * @param Event           $event        Triggering event (first of a
     *                                      batch)
     * @param list<Event>     $events       All events (batches)
     * @param string          $run_id       Run ID
     * @param int             $attempt      Zero-based attempt number
     * @param int|null        $max_attempts Attempt limit, when known
     * @param StepTools       $step         Step tools
     * @param LoggerInterface $logger       Logger
     * @param \Throwable|null $error        Why the function failed (only
     *                                      for `on_failure` handlers)
     */
    public function __construct(
        public readonly Event $event,
        public readonly array $events,
        public readonly string $run_id,
        public readonly int $attempt,
        public readonly ?int $max_attempts,
        public readonly StepTools $step,
        public readonly LoggerInterface $logger,
        public readonly ?\Throwable $error = null
    ) {
    }
}
