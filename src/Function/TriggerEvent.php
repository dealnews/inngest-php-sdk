<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Event-based trigger for a function
 */
class TriggerEvent implements TriggerInterface
{
    /**
     * @param string $event Event name that triggers the function
     * @param string|null $expression Optional CEL expression to filter events
     */
    public function __construct(
        protected string $event,
        protected ?string $expression = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $trigger = ['event' => $this->event];

        if ($this->expression !== null) {
            $trigger['expression'] = $this->expression;
        }

        return $trigger;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function getExpression(): ?string
    {
        return $this->expression;
    }
}
