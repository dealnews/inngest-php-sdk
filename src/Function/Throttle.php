<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Represents throttling configuration for an Inngest function
 *
 * Throttling limits how many function runs can start within a time period.
 * When the limit is reached, new function runs over the throttling limit are
 * enqueued for future execution (FIFO). This uses the Generic Cell Rate
 * Algorithm (GCRA).
 *
 * ## Usage
 *
 * ```php
 * // Simple throttle (10 runs per hour)
 * $throttle = new Throttle(limit: 10, period: '1h');
 *
 * // With burst (allows 15 runs per hour: 10 + 5 burst)
 * $throttle = new Throttle(
 *     limit: 10,
 *     period: '1h',
 *     burst: 5
 * );
 *
 * // Per-user throttling
 * $throttle = new Throttle(
 *     limit: 5,
 *     period: '30m',
 *     key: 'event.data.user_id'
 * );
 *
 * // Complex key with burst
 * $throttle = new Throttle(
 *     limit: 100,
 *     period: '24h',
 *     burst: 10,
 *     key: 'event.data.customer_id + "-" + event.data.region'
 * );
 * ```
 *
 * ## Edge Cases
 *
 * - Period must be between 1 second and 7 days (max 604800 seconds)
 * - Supported formats: "1s", "30s", "5m", "60m", "2h", "24h", "7d"
 * - The `key` is a CEL expression evaluated against event data
 * - Limit must be at least 1 (same as RateLimit)
 * - Burst must be at least 0 (default is 0, meaning no bursting)
 * - Events exceeding limit are enqueued (FIFO), not skipped
 * - Maximum runs per period: limit + burst
 */
class Throttle
{
    /**
     * @param int $limit Maximum number of function runs in the period (must be >= 1)
     * @param string $period Time period for the limit (e.g., "30s", "5m", "2h", "7d")
     * @param int $burst Number of extra runs allowed in single burst (must be >= 0, default 0)
     * @param string|null $key Optional CEL expression for per-key throttling (e.g., "event.data.user_id")
     *
     * @throws \InvalidArgumentException If limit, burst, or period is invalid
     */
    public function __construct(
        protected int $limit,
        protected string $period,
        protected int $burst = 0,
        protected ?string $key = null
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException(
                'Throttle limit must be at least 1'
            );
        }

        if ($burst < 0) {
            throw new \InvalidArgumentException(
                'Throttle burst must be at least 0'
            );
        }

        $this->validatePeriod($period);
    }

    /**
     * Validate the period format and range
     *
     * Ensures period is a valid time string between 1s and 7d (604800 seconds).
     * Supports seconds (s), minutes (m), hours (h), and days (d) units.
     *
     * @param string $period The period string to validate
     *
     * @throws \InvalidArgumentException If period is invalid
     *
     * @return void
     */
    protected function validatePeriod(string $period): void
    {
        if (trim($period) === '') {
            throw new \InvalidArgumentException(
                'Throttle period cannot be empty'
            );
        }

        if (!preg_match('/^(\d+)([smhd])$/', $period, $matches)) {
            throw new \InvalidArgumentException(
                'Throttle period must be in format: <number><unit> '.
                '(e.g., "30s", "5m", "2h", "7d")'
            );
        }

        $value = (int)$matches[1];
        $unit = $matches[2];

        $seconds = $this->convertToSeconds($value, $unit);

        if ($seconds < 1) {
            throw new \InvalidArgumentException(
                'Throttle period must be at least 1 second'
            );
        }

        $max_seconds = 7 * 24 * 60 * 60;
        if ($seconds > $max_seconds) {
            throw new \InvalidArgumentException(
                'Throttle period must not exceed 7 days (604800 seconds)'
            );
        }
    }

    /**
     * Convert time value to seconds
     *
     * @param int $value The numeric value
     * @param string $unit The unit (s, m, h, d)
     *
     * @return int Total seconds
     */
    protected function convertToSeconds(int $value, string $unit): int
    {
        $multipliers = [
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
        ];

        return $value * $multipliers[$unit];
    }

    /**
     * Get the throttle limit
     *
     * @return int Maximum number of function runs in the period
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get the throttle period
     *
     * @return string Time period for the limit
     */
    public function getPeriod(): string
    {
        return $this->period;
    }

    /**
     * Get the throttle burst
     *
     * @return int Number of extra runs allowed in single burst
     */
    public function getBurst(): int
    {
        return $this->burst;
    }

    /**
     * Get the throttle key expression
     *
     * @return string|null CEL expression for grouping throttle limits
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
            'limit'  => $this->limit,
            'period' => $this->period,
        ];

        if ($this->burst > 0) {
            $data['burst'] = $this->burst;
        }

        if ($this->key !== null) {
            $data['key'] = $this->key;
        }

        return $data;
    }
}
