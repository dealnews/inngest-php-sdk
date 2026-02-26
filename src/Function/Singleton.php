<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Represents singleton configuration for an Inngest function
 *
 * Singleton ensures only a single run of a function (or per unique key)
 * is executing at a time. Prevents duplicate work and race conditions
 * in data processing, expensive operations, and synchronization tasks.
 *
 * ## Usage
 *
 * ```php
 * // Basic: Skip new runs if one is already executing
 * $singleton = new Singleton(mode: 'skip');
 *
 * // Per-user: Each user has own singleton rule
 * $singleton = new Singleton(
 *     mode: 'skip',
 *     key: 'event.data.user_id'
 * );
 *
 * // Cancel mode: Always process latest event
 * $singleton = new Singleton(
 *     mode: 'cancel',
 *     key: 'event.data.user_id'
 * );
 *
 * // Complex key: Multi-field grouping
 * $singleton = new Singleton(
 *     mode: 'skip',
 *     key: 'event.data.customer_id + "-" + event.data.region'
 * );
 * ```
 *
 * ## Modes
 *
 * **Skip Mode (`skip`):**
 * - Preserves currently running function
 * - New runs are discarded when another is active
 * - Use for: preventing duplicate work, protecting resources
 *
 * **Cancel Mode (`cancel`):**
 * - Cancels in-progress run and starts new one
 * - Ensures latest event is always processed
 * - Rapid succession may cause some skips (debounce-like behavior)
 * - Use for: data sync where freshness matters
 *
 * ## Edge Cases
 *
 * - Mode must be either "skip" or "cancel" (case-sensitive)
 * - The `key` is a CEL expression evaluated against event data
 * - Cannot combine singleton with batching
 * - Incompatible with concurrency settings (singleton implies concurrency=1)
 * - Failed functions still skip new runs during retry
 * - Works with debounce, priority, rate limiting, and throttling
 */
class Singleton
{
    /**
     * @param string $mode Behavior when new run arrives ("skip" or "cancel")
     * @param string|null $key Optional CEL expression for per-key singleton (e.g., "event.data.user_id")
     *
     * @throws \InvalidArgumentException If mode is not "skip" or "cancel"
     */
    public function __construct(
        protected string $mode,
        protected ?string $key = null
    ) {
        $this->validateMode($mode);
    }

    /**
     * Validate the mode value
     *
     * Ensures mode is either "skip" or "cancel".
     *
     * @param string $mode The mode to validate
     *
     * @throws \InvalidArgumentException If mode is invalid
     *
     * @return void
     */
    protected function validateMode(string $mode): void
    {
        if (trim($mode) === '') {
            throw new \InvalidArgumentException(
                'Singleton mode cannot be empty'
            );
        }

        if (!in_array($mode, ['skip', 'cancel'], true)) {
            throw new \InvalidArgumentException(
                'Singleton mode must be either "skip" or "cancel", '.
                'got: "' . $mode . '"'
            );
        }
    }

    /**
     * Get the singleton mode
     *
     * @return string Either "skip" or "cancel"
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Get the singleton key expression
     *
     * @return string|null CEL expression for grouping singleton behavior
     */
    public function getKey(): ?string
    {
        return $this->key;
    }

    /**
     * Convert to array for sync payload
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'mode' => $this->mode,
        ];

        if ($this->key !== null) {
            $data['key'] = $this->key;
        }

        return $data;
    }
}
