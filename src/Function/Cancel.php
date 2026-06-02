<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Represents a cancellation trigger for an Inngest function
 *
 * Allows a function to be cancelled based on events, with optional conditions
 * and timeouts for when the cancellation is no longer valid.
 *
 * ## Usage
 *
 * ```php
 * // Basic: Cancel on specific event
 * $cancel = new Cancel(event: 'order/cancelled');
 *
 * // With condition: Cancel only if certain conditions are met
 * $cancel = new Cancel(
 *     event: 'order/cancelled',
 *     if: 'event.data.order_id == async.data.order_id'
 * );
 *
 * // With timeout: Cancellation only valid for limited time
 * $cancel = new Cancel(
 *     event: 'order/cancelled',
 *     if: 'event.data.order_id == async.data.order_id',
 *     timeout: '5m'
 * );
 * ```
 *
 * ## Edge Cases
 *
 * - The event parameter is required and specifies which event triggers cancellation
 * - The if parameter is a CEL expression evaluated against the triggering event
 * - The timeout is a duration string specifying how long the cancellation is valid
 */
class Cancel
{
    /**
     * @param string $event The event name that triggers cancellation (e.g., "order/cancelled")
     * @param string|null $if Optional CEL expression condition for when to cancel
     * @param string|null $timeout Optional duration after which cancellation is no longer valid
     */
    public function __construct(
        protected string $event,
        protected ?string $if = null,
        protected ?string $timeout = null
    ) {
    }

    /**
     * Get the cancellation event
     *
     * @return string Event name that triggers cancellation
     */
    public function getEvent(): string
    {
        return $this->event;
    }

    /**
     * Get the cancellation condition
     *
     * @return string|null CEL expression condition or null
     */
    public function getIf(): ?string
    {
        return $this->if;
    }

    /**
     * Get the cancellation timeout
     *
     * @return string|null Duration or null
     */
    public function getTimeout(): ?string
    {
        return $this->timeout;
    }

    /**
     * Convert to array for sync payload
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['event' => $this->event];
        if ($this->if !== null) {
            $data['if'] = $this->if;
        }
        if ($this->timeout !== null) {
            $data['timeout'] = $this->timeout;
        }
        return $data;
    }
}
