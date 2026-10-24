<?php

declare(strict_types=1);

namespace DealNews\Inngest\Support;

use Psr\Log\LoggerInterface;

/**
 * Converts durations and dates to Inngest time strings
 *
 * Port of the JS SDK's `timeStr()` (helpers/strings.ts). Inngest expects
 * relative durations as compact strings like `"1d"` or `"2h30m10s"`, and
 * absolute times as ISO 8601 strings.
 *
 * Accepted input:
 *
 * - `int|float` — milliseconds (same as JS)
 * - `string` — an `ms`-style value (`"500ms"`, `"30 mins"`, `"2.5d"`,
 *   `"1 hour"`) or an Inngest compound string (`"1h30m"`)
 * - `\DateInterval` — resolved against the current UTC time, so months and
 *   years are calendar-correct
 * - `\DateTimeInterface` — returned as a UTC ISO string with milliseconds
 *   (`1970-01-01T00:00:00.000Z`, same as JS `Date#toISOString()`)
 *
 * Usage:
 *
 * ```php
 * $duration = new Duration($logger);
 * $duration->toTimeString('90 minutes'); // "1h30m"
 * $duration->toTimeString(1500);         // "1s"
 * ```
 *
 * Edge cases:
 *
 * - Durations between 0 and 1s round **up** to `"1s"` (a durable wait
 *   can't be shorter), with a one-time warning per instance.
 * - Remainders below 1s on longer durations are dropped (`1500` → `"1s"`).
 * - Zero and negative durations return `""`, which Inngest treats as no
 *   wait.
 * - Unparseable strings throw. (JS silently returns `""` here; see the
 *   plan's decisions log.)
 *
 * @package DealNews\Inngest\Support
 */
class Duration {

    /**
     * Milliseconds per unit, keyed by every unit name the ms package accepts
     *
     * @var array<string, float>
     */
    public const UNIT_MS = [
        'years'        => 31557600000.0,
        'year'         => 31557600000.0,
        'yrs'          => 31557600000.0,
        'yr'           => 31557600000.0,
        'y'            => 31557600000.0,
        'weeks'        => 604800000.0,
        'week'         => 604800000.0,
        'w'            => 604800000.0,
        'days'         => 86400000.0,
        'day'          => 86400000.0,
        'd'            => 86400000.0,
        'hours'        => 3600000.0,
        'hour'         => 3600000.0,
        'hrs'          => 3600000.0,
        'hr'           => 3600000.0,
        'h'            => 3600000.0,
        'minutes'      => 60000.0,
        'minute'       => 60000.0,
        'mins'         => 60000.0,
        'min'          => 60000.0,
        'm'            => 60000.0,
        'seconds'      => 1000.0,
        'second'       => 1000.0,
        'secs'         => 1000.0,
        'sec'          => 1000.0,
        's'            => 1000.0,
        'milliseconds' => 1.0,
        'millisecond'  => 1.0,
        'msecs'        => 1.0,
        'msec'         => 1.0,
        'ms'           => 1.0,
    ];

    /**
     * Output periods, largest first, as [suffix, milliseconds]
     *
     * @var list<array{string, int}>
     */
    public const PERIODS = [
        ['w', 604800000],
        ['d', 86400000],
        ['h', 3600000],
        ['m', 60000],
        ['s', 1000],
    ];

    /**
     * Pattern for a single ms-style value, from the ms package (v2.1.3)
     *
     * @var string
     */
    public const MS_PATTERN = '/^(-?(?:\\d+)?\\.?\\d+) *' .
        '(milliseconds?|msecs?|ms|seconds?|secs?|s|minutes?|mins?|m|' .
        'hours?|hrs?|h|days?|d|weeks?|w|years?|yrs?|y)?$/i';

    /**
     * Pattern for an Inngest compound string such as "1w2d3h4m5s"
     *
     * @var string
     */
    public const COMPOUND_PATTERN = '/^(?:\\d+w)?(?:\\d+d)?(?:\\d+h)?' .
        '(?:\\d+m)?(?:\\d+s)?$/';

    /**
     * Whether the sub-second warning has been logged by this instance
     *
     * @var bool
     */
    protected bool $warned_sub_second = false;

    /**
     * Creates a converter
     *
     * @param LoggerInterface|null $logger Receives the one-time sub-second
     *                                     rounding warning
     */
    public function __construct(protected ?LoggerInterface $logger = null) {
    }

    /**
     * Converts a duration or date to an Inngest time string
     *
     * @param int|float|string|\DateInterval|\DateTimeInterface $input Value
     *
     * @throws \InvalidArgumentException When a string can't be parsed
     *
     * @return string
     */
    public function toTimeString(
        int|float|string|\DateInterval|\DateTimeInterface $input
    ): string {
        if ($input instanceof \DateTimeInterface) {
            $result = static::toIsoString($input);
        } else {
            $result = $this->format($this->toMilliseconds($input));
        }

        return $result;
    }

    /**
     * Converts a relative duration to milliseconds
     *
     * @param int|float|string|\DateInterval $input Duration
     *
     * @throws \InvalidArgumentException When a string can't be parsed
     *
     * @return float
     */
    public function toMilliseconds(
        int|float|string|\DateInterval $input
    ): float {
        if ($input instanceof \DateInterval) {
            $result = static::intervalToMilliseconds($input);
        } elseif (is_string($input)) {
            $result = static::parseString($input);
        } else {
            $result = (float)$input;
        }

        return $result;
    }

    /**
     * Formats a date as a UTC ISO 8601 string with milliseconds
     *
     * Matches JS `Date#toISOString()`.
     *
     * @param \DateTimeInterface $date Date to format
     *
     * @return string
     */
    public static function toIsoString(\DateTimeInterface $date): string {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.v\\Z');
    }

    /**
     * Formats milliseconds as a compound time string ("2h30m10s")
     *
     * @param float $milliseconds Duration in milliseconds
     *
     * @return string
     */
    protected function format(float $milliseconds): string {
        if ($milliseconds > 0 && $milliseconds < 1000) {
            $this->warnSubSecond($milliseconds);
            $milliseconds = 1000.0;
        }

        $result    = '';
        $remaining = $milliseconds;

        foreach (self::PERIODS as $period) {
            $count = (int)floor($remaining / $period[1]);
            if ($count > 0) {
                $result   .= $count . $period[0];
                $remaining = fmod($remaining, $period[1]);
            }
        }

        return $result;
    }

    /**
     * Logs the sub-second rounding warning once per instance
     *
     * @param float $milliseconds The requested duration
     *
     * @return void
     */
    protected function warnSubSecond(float $milliseconds): void {
        if ($this->logger !== null && !$this->warned_sub_second) {
            $this->warned_sub_second = true;
            $this->logger->warning(
                'Durable waits have a minimum resolution of 1s; durations ' .
                'under 1s round up to 1s',
                ['requested_ms' => $milliseconds]
            );
        }
    }

    /**
     * Parses an ms-style or compound duration string to milliseconds
     *
     * @param string $input Duration string
     *
     * @throws \InvalidArgumentException When the string can't be parsed
     *
     * @return float
     */
    protected static function parseString(string $input): float {
        $matches = [];

        if (
            strlen($input) <= 100 &&
            preg_match(self::MS_PATTERN, $input, $matches)
        ) {
            $unit   = strtolower($matches[2] ?? '');
            $unit   = $unit === '' ? 'ms' : $unit;
            $result = (float)$matches[1] * self::UNIT_MS[$unit];
        } elseif (
            $input !== '' &&
            preg_match(self::COMPOUND_PATTERN, $input)
        ) {
            $result = static::parseCompound($input);
        } else {
            throw new \InvalidArgumentException(
                "Invalid duration string: \"$input\"",
                2001
            );
        }

        return $result;
    }

    /**
     * Parses a validated compound string ("1h30m") to milliseconds
     *
     * @param string $input Compound duration string
     *
     * @return float
     */
    protected static function parseCompound(string $input): float {
        $result  = 0.0;
        $matches = [];

        preg_match_all('/(\\d+)([wdhms])/', $input, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $result += (float)$match[1] * self::UNIT_MS[$match[2]];
        }

        return $result;
    }

    /**
     * Converts an interval to milliseconds relative to now (UTC)
     *
     * @param \DateInterval $interval Interval
     *
     * @return float
     */
    protected static function intervalToMilliseconds(
        \DateInterval $interval
    ): float {
        $now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $later = $now->add($interval);

        $seconds = $later->getTimestamp()   - $now->getTimestamp();
        $micros  = (int)$later->format('u') - (int)$now->format('u');

        return $seconds * 1000 + $micros / 1000;
    }
}
