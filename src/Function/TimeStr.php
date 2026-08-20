<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Parses Inngest "Time String" values (e.g. "300ms", "3s", "2h45m") into a
 * number of milliseconds.
 */
final class TimeStr
{
    private const UNIT_MS = [
        'ns' => 0.000001,
        'us' => 0.001,
        'µs' => 0.001,
        'ms' => 1,
        's' => 1000,
        'm' => 60_000,
        'h' => 3_600_000,
        'd' => 86_400_000,
        'w' => 604_800_000,
    ];

    public static function toMilliseconds(string $value): int
    {
        if ($value === '' || !preg_match('/^(?:\d+(?:ns|us|µs|ms|s|m|h|d|w))+$/u', $value)) {
            throw new \InvalidArgumentException("Invalid time string: {$value}");
        }

        preg_match_all('/(\d+)(ns|us|µs|ms|s|m|h|d|w)/u', $value, $matches, PREG_SET_ORDER);

        $total = 0.0;
        foreach ($matches as [, $num, $unit]) {
            $total += (int) $num * self::UNIT_MS[$unit];
        }

        return (int) round($total);
    }
}
