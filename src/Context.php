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
 * Edge cases:
 *
 * - Middleware can swap the event, step tools, or logger, and add values
 *   to `extras` (JS adds them to the context object itself).
 *
 * @package DealNews\Inngest
 */
class Context {

    /**
     * Creates the context
     *
     * @param Event                $event        Triggering event (first of
     *                                           a batch)
     * @param list<Event>          $events       All events (batches)
     * @param string               $run_id       Run ID
     * @param int                  $attempt      Zero-based attempt number
     * @param int|null             $max_attempts Attempt limit, when known
     * @param StepTools            $step         Step tools
     * @param LoggerInterface      $logger       Logger
     * @param \Throwable|null      $error        Why the function failed
     *                                           (only for `on_failure`
     *                                           handlers)
     * @param array<string, mixed> $extras       Values added by middleware
     */
    public function __construct(
        public readonly Event $event,
        public readonly array $events,
        public readonly string $run_id,
        public readonly int $attempt,
        public readonly ?int $max_attempts,
        public readonly StepTools $step,
        public readonly LoggerInterface $logger,
        public readonly ?\Throwable $error = null,
        public readonly array $extras = []
    ) {
    }

    /**
     * Returns a copy with some values replaced
     *
     * `extras` are merged into the existing ones (same keys replaced).
     *
     * Usage:
     *
     * ```php
     * $input->ctx = $input->ctx->with(extras: ['db' => $db]);
     * ```
     *
     * @param Event|null                $event  Triggering event
     * @param list<Event>|null          $events All events
     * @param StepTools|null            $step   Step tools
     * @param LoggerInterface|null      $logger Logger
     * @param array<string, mixed>|null $extras Values to add
     *
     * @return self
     */
    public function with(
        ?Event $event = null,
        ?array $events = null,
        ?StepTools $step = null,
        ?LoggerInterface $logger = null,
        ?array $extras = null
    ): self {
        return new self(
            event: $event   ?? $this->event,
            events: $events ?? $this->events,
            run_id: $this->run_id,
            attempt: $this->attempt,
            max_attempts: $this->max_attempts,
            step: $step     ?? $this->step,
            logger: $logger ?? $this->logger,
            error: $this->error,
            extras: $extras === null ?
                $this->extras :
                array_replace($this->extras, $extras)
        );
    }
}
