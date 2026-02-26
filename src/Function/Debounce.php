<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Represents debounce configuration for an Inngest function
 *
 * Debounce delays function execution until events stop arriving for a
 * specified period. This prevents wasted work when functions might be
 * triggered in quick succession by noisy events or rapid user input.
 *
 * The function runs using the last event received as input data.
 *
 * ## Usage
 *
 * ```php
 * // Basic: Wait 30 seconds after last event
 * $debounce = new Debounce(period: '30s');
 *
 * // With key: Separate debounce per user
 * $debounce = new Debounce(
 *     period: '5m',
 *     key: 'event.data.user_id'
 * );
 *
 * // With timeout: Force run after max wait
 * $debounce = new Debounce(
 *     period: '1m',
 *     timeout: '10m'
 * );
 *
 * // Complex key expression
 * $debounce = new Debounce(
 *     period: '30s',
 *     key: 'event.data.customer_id + "-" + event.data.region'
 * );
 * ```
 *
 * ## Edge Cases
 *
 * - Period must be between 1 second and 7 days (168 hours)
 * - Supported formats: "1s", "30s", "5m", "2h", "7d"
 * - The `key` is a CEL expression evaluated against event data
 * - Cannot combine debounce with batching (not yet enforced)
 * - If timeout is provided, function always runs after timeout even if
 *   new events continue arriving
 */
class Debounce
{
    /**
     * @param string $period Time delay to wait after last event (e.g., "30s", "5m", "1h", "7d")
     * @param string|null $key Optional CEL expression for per-key debouncing (e.g., "event.data.user_id")
     * @param string|null $timeout Optional maximum wait time before forced execution
     *
     * @throws \InvalidArgumentException If period format is invalid or out of range
     */
    public function __construct(
        protected string $period,
        protected ?string $key = null,
        protected ?string $timeout = null
    ) {
        $this->validatePeriod($period);
        
        if ($timeout !== null) {
            $this->validateTimeout($timeout);
        }
    }

    /**
     * Validate the period format and range
     *
     * Ensures period is a valid time string between 1s and 7d (168h).
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
                'Debounce period cannot be empty'
            );
        }

        if (!preg_match('/^(\d+)([smhd])$/', $period, $matches)) {
            throw new \InvalidArgumentException(
                'Debounce period must be in format: <number><unit> '.
                '(e.g., "30s", "5m", "2h", "7d")'
            );
        }

        $value = (int)$matches[1];
        $unit = $matches[2];

        $seconds = $this->convertToSeconds($value, $unit);

        if ($seconds < 1) {
            throw new \InvalidArgumentException(
                'Debounce period must be at least 1 second'
            );
        }

        $max_seconds = 7 * 24 * 60 * 60;
        if ($seconds > $max_seconds) {
            throw new \InvalidArgumentException(
                'Debounce period must not exceed 7 days (168 hours)'
            );
        }
    }

    /**
     * Validate the timeout format and range
     *
     * Uses same validation rules as period.
     *
     * @param string $timeout The timeout string to validate
     *
     * @throws \InvalidArgumentException If timeout is invalid
     *
     * @return void
     */
    protected function validateTimeout(string $timeout): void
    {
        if (trim($timeout) === '') {
            throw new \InvalidArgumentException(
                'Debounce timeout cannot be empty'
            );
        }

        if (!preg_match('/^(\d+)([smhd])$/', $timeout, $matches)) {
            throw new \InvalidArgumentException(
                'Debounce timeout must be in format: <number><unit> '.
                '(e.g., "30s", "5m", "2h", "7d")'
            );
        }

        $value = (int)$matches[1];
        $unit = $matches[2];

        $seconds = $this->convertToSeconds($value, $unit);

        if ($seconds < 1) {
            throw new \InvalidArgumentException(
                'Debounce timeout must be at least 1 second'
            );
        }

        $max_seconds = 7 * 24 * 60 * 60;
        if ($seconds > $max_seconds) {
            throw new \InvalidArgumentException(
                'Debounce timeout must not exceed 7 days (168 hours)'
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
     * Get the debounce period
     *
     * @return string Time delay after last event
     */
    public function getPeriod(): string
    {
        return $this->period;
    }

    /**
     * Get the debounce key expression
     *
     * @return string|null CEL expression for grouping debounce windows
     */
    public function getKey(): ?string
    {
        return $this->key;
    }

    /**
     * Get the debounce timeout
     *
     * @return string|null Maximum wait time before forced execution
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
        $data = [
            'period' => $this->period,
        ];

        if ($this->key !== null) {
            $data['key'] = $this->key;
        }

        if ($this->timeout !== null) {
            $data['timeout'] = $this->timeout;
        }

        return $data;
    }
}
