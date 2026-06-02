<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Represents batch event configuration for an Inngest function
 *
 * Batching delays function execution and groups events together, allowing
 * functions to process multiple events in a single invocation. This is useful
 * for operations like bulk database inserts or batch API requests.
 *
 * The function runs with all batched events available in context.getEvents().
 *
 * ## Usage
 *
 * ```php
 * // Basic: Max 100 events or 30 seconds timeout
 * $batch = new BatchEvents(max_size: 100, timeout: '30s');
 *
 * // With key: Separate batch per user
 * $batch = new BatchEvents(
 *     max_size: 50,
 *     timeout: '1m',
 *     key: 'event.data.user_id'
 * );
 *
 * // Complex key expression
 * $batch = new BatchEvents(
 *     max_size: 1000,
 *     timeout: '5m',
 *     key: 'event.data.customer_id + "-" + event.data.region'
 * );
 * ```
 *
 * ## Edge Cases
 *
 * - max_size must be at least 1
 * - timeout must be a valid duration string (e.g., "30s", "5m", "1h")
 * - The `key` is a CEL expression evaluated against event data
 * - Cannot combine batching with debouncing (not yet enforced)
 */
class BatchEvents
{
    /**
     * @param int $max_size Maximum number of events to batch before running function
     * @param string $timeout Duration to wait before running function even if not at max_size
     * @param string|null $key Optional CEL expression for per-key batching (e.g., "event.data.user_id")
     *
     * @throws \InvalidArgumentException If max_size is less than 1
     */
    public function __construct(
        protected int $max_size,
        protected string $timeout,
        protected ?string $key = null
    ) {
        if ($this->max_size < 1) {
            throw new \InvalidArgumentException('BatchEvents max_size must be at least 1');
        }

        if (!preg_match('/^\d+[smhdw]$/', $this->timeout)) {
            throw new \InvalidArgumentException(
                'BatchEvents timeout must be a valid duration string (e.g., "30s", "5m", "1h"). Got: ' . $this->timeout
            );
        }
    }

    /**
     * Get the maximum batch size
     *
     * @return int Maximum number of events per batch
     */
    public function getMaxSize(): int
    {
        return $this->max_size;
    }

    /**
     * Get the batch timeout
     *
     * @return string Duration before running function without reaching max_size
     */
    public function getTimeout(): string
    {
        return $this->timeout;
    }

    /**
     * Get the batch key expression
     *
     * @return string|null CEL expression for grouping batches
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
            'maxSize' => $this->max_size,
            'timeout' => $this->timeout,
        ];
        if ($this->key !== null) {
            $data['key'] = $this->key;
        }
        return $data;
    }
}
