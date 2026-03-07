<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Represents rate limiting configuration for an Inngest function
 *
 * Rate limiting sets a hard limit on how many function runs can start within
 * a time period. Events that exceed the rate limit are skipped and do not
 * trigger functions to start. This uses the Generic Cell Rate Algorithm (GCRA).
 *
 * ## Usage
 *
 * ```php
 * // Simple limit (10 runs per hour)
 * $rate_limit = new RateLimit(limit: 10, period: '1h');
 *
 * // Per-user rate limiting
 * $rate_limit = new RateLimit(
 *     limit: 5,
 *     period: '30m',
 *     key: 'event.data.user_id'
 * );
 *
 * // Per-customer and region
 * $rate_limit = new RateLimit(
 *     limit: 100,
 *     period: '24h',
 *     key: 'event.data.customer_id + "-" + event.data.region'
 * );
 * ```
 *
 * ## Edge Cases
 *
 * - Period must be between 1 second and 24 hours (max 86400 seconds)
 * - Supported formats: "1s", "30s", "5m", "60m", "2h", "24h"
 * - Unlike Debounce, days (d) are not supported (max is 24h)
 * - The `key` is a CEL expression evaluated against event data
 * - Limit must be at least 1 (unlike Concurrency which allows 0)
 * - Events exceeding limit are skipped, not queued
 */
class RateLimit
{
    /**
     * @param int $limit Maximum number of function runs in the period (must be >= 1)
     * @param string $period Time period for the limit (e.g., "30s", "5m", "2h", "24h")
     * @param string|null $key Optional CEL expression for per-key rate limiting (e.g., "event.data.user_id")
     *
     * @throws \InvalidArgumentException If limit or period is invalid
     */
    public function __construct(
        protected int $limit,
        protected string $period,
        protected ?string $key = null
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException(
                'Rate limit must be at least 1'
            );
        }

        $this->validatePeriod($period);
    }

    /**
     * Validate the period format and range
     *
     * Ensures period is a valid time string between 1s and 24h (86400 seconds).
     * Only supports seconds (s), minutes (m), and hours (h) units.
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
                'Rate limit period cannot be empty'
            );
        }

        if (!preg_match('/^(\d+)([smh])$/', $period, $matches)) {
            throw new \InvalidArgumentException(
                'Rate limit period must be in format: <number><unit> '.
                '(e.g., "30s", "5m", "2h"). Only s, m, and h units are '.
                'supported (max 24h)'
            );
        }

        $value = (int)$matches[1];
        $unit = $matches[2];

        $seconds = $this->convertToSeconds($value, $unit);

        if ($seconds < 1) {
            throw new \InvalidArgumentException(
                'Rate limit period must be at least 1 second'
            );
        }

        $max_seconds = 24 * 60 * 60;
        if ($seconds > $max_seconds) {
            throw new \InvalidArgumentException(
                'Rate limit period must not exceed 24 hours (86400 seconds)'
            );
        }
    }

    /**
     * Convert time value to seconds
     *
     * @param int $value The numeric value
     * @param string $unit The unit (s, m, h)
     *
     * @return int Total seconds
     */
    protected function convertToSeconds(int $value, string $unit): int
    {
        $multipliers = [
            's' => 1,
            'm' => 60,
            'h' => 3600,
        ];

        return $value * $multipliers[$unit];
    }

    /**
     * Get the rate limit maximum
     *
     * @return int Maximum number of function runs in the period
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get the rate limit period
     *
     * @return string Time period for the limit
     */
    public function getPeriod(): string
    {
        return $this->period;
    }

    /**
     * Get the rate limit key expression
     *
     * @return string|null CEL expression for grouping rate limits
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

        if ($this->key !== null) {
            $data['key'] = $this->key;
        }

        return $data;
    }
}
