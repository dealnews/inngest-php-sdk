<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Step\Step;

/**
 * Context passed to function execution
 */
class FunctionContext
{
    /**
     * @param Event $event The event that triggered this function
     * @param array<Event> $events All events (for batch functions)
     * @param string $run_id The ID of this function run
     * @param int $attempt Current attempt number (0-indexed)
     * @param Step $step Step tooling for creating retriable steps
     */
    public function __construct(
        protected Event $event,
        protected array $events,
        protected string $run_id,
        protected int $attempt,
        protected Step $step
    ) {
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    /**
     * @return array<Event>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function getRunId(): string
    {
        return $this->run_id;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getStep(): Step
    {
        return $this->step;
    }
}
