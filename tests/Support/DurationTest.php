<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Support;

use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Tests\mock\RecordingLogger;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for Duration (port of JS `timeStr()` tests in strings.test.ts)
 *
 * @package DealNews\Inngest\Tests\Support
 */
#[CoversClass(Duration::class)]
class DurationTest extends TestCase {

    /**
     * Numeric and string inputs produce JS-identical time strings
     *
     * @param int|float|string $input    Duration
     * @param string           $expected Time string
     *
     * @return void
     */
    #[DataProvider('timeStringProvider')]
    public function testToTimeString(
        int|float|string $input,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            (new Duration())->toTimeString($input)
        );
    }

    /**
     * Cases for testToTimeString; the first group mirrors the JS tests
     *
     * @return array<string, array{int|float|string, string}>
     */
    public static function timeStringProvider(): array {
        return [
            // JS strings.test.ts
            'ms to string'         => [1000, '1s'],
            'ms string day'        => ['1 day', '1d'],
            'sub-second 1'         => [1, '1s'],
            'sub-second 500'       => [500, '1s'],
            'sub-second "500ms"'   => ['500ms', '1s'],
            'sub-second 999'       => [999, '1s'],
            'floors remainder'     => [1500, '1s'],
            'floors 65500'         => [65500, '1m5s'],
            'whole minute'         => [60000, '1m'],
            'fraction 999.6'       => [999.6, '1s'],
            'fraction 0.4'         => [0.4, '1s'],
            'zero'                 => [0, ''],
            'negative'             => [-500, ''],
            '30s'                  => ['30s', '30s'],
            '5m'                   => ['5m', '5m'],
            '2h'                   => ['2h', '2h'],
            '3d'                   => ['3d', '3d'],
            '1w'                   => ['1w', '1w'],
            '2w'                   => ['2w', '2w'],
            '1.5 hours'            => ['1.5 hours', '1h30m'],

            // ms package units and formats
            'bare number string'   => ['2000', '2s'],
            'decimal no leading 0' => ['.5m', '30s'],
            'upper case unit'      => ['2H', '2h'],
            'long unit'            => ['3 minutes', '3m'],
            'abbrev unit'          => ['10 secs', '10s'],
            'hrs'                  => ['1hr', '1h'],
            'msecs'                => ['2000 msecs', '2s'],
            'year'                 => ['1y', '52w1d6h'],
            'negative string'      => ['-1h', ''],
            'mixed periods'        => [694861000, '1w1d1h1m1s'],

            // Compound strings (PHP addition; JS returns "")
            'compound h+m'         => ['1h30m', '1h30m'],
            'compound full'        => ['1w2d3h4m5s', '1w2d3h4m5s'],
            'compound normalizes'  => ['90m', '1h30m'],
            'compound d+s'         => ['1d10s', '1d10s'],
        ];
    }

    /**
     * Unparseable strings throw instead of becoming a zero wait
     *
     * @param string $input Invalid duration
     *
     * @return void
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidStringsThrow(string $input): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(2001);
        (new Duration())->toTimeString($input);
    }

    /**
     * Cases for testInvalidStringsThrow
     *
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array {
        return [
            'empty'          => [''],
            'words'          => ['soon'],
            'bad unit'       => ['5 fortnights'],
            'wrong order'    => ['30m1h'],
            'too long'       => [str_repeat('1', 101)],
            'compound space' => ['1h 30m'],
        ];
    }

    /**
     * Dates format like JS `Date#toISOString()`, always in UTC
     *
     * @return void
     */
    public function testDateToIsoString(): void {
        $duration = new Duration();

        $this->assertSame(
            '1970-01-01T00:00:00.000Z',
            $duration->toTimeString(new \DateTimeImmutable('@0'))
        );
        $this->assertSame(
            '2026-10-23T16:30:00.250Z',
            $duration->toTimeString(
                new \DateTime(
                    '2026-10-23 12:30:00.250',
                    new \DateTimeZone('America/New_York')
                )
            )
        );
    }

    /**
     * Intervals convert like JS Temporal.Duration
     *
     * @param string $spec     DateInterval spec
     * @param string $expected Time string
     *
     * @return void
     */
    #[DataProvider('intervalProvider')]
    public function testIntervals(string $spec, string $expected): void {
        $this->assertSame(
            $expected,
            (new Duration())->toTimeString(new \DateInterval($spec))
        );
    }

    /**
     * Cases for testIntervals (from the JS Temporal.Duration cases)
     *
     * @return array<string, array{string, string}>
     */
    public static function intervalProvider(): array {
        return [
            '30 seconds'      => ['PT30S', '30s'],
            '5 minutes'       => ['PT5M', '5m'],
            '2 hours'         => ['PT2H', '2h'],
            '3 days'          => ['P3D', '3d'],
            '1 week'          => ['P1W', '1w'],
            '2 weeks'         => ['P2W', '2w'],
            '1h30m'           => ['PT1H30M', '1h30m'],
            'minutes balance' => ['PT90M', '1h30m'],
        ];
    }

    /**
     * Calendar units resolve against the current date
     *
     * @return void
     */
    public function testCalendarIntervalIsCalendarCorrect(): void {
        $now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $days  = (int)$now->diff($now->add(new \DateInterval('P1M')))->days;
        $whole = intdiv($days, 7);
        $rest  = $days % 7;

        $expected = ($whole > 0 ? $whole . 'w' : '') .
                    ($rest > 0 ? $rest . 'd' : '');

        $this->assertSame(
            $expected,
            (new Duration())->toTimeString(new \DateInterval('P1M'))
        );
    }

    /**
     * Inverted (negative) intervals are no wait, like negative numbers
     *
     * @return void
     */
    public function testInvertedIntervalIsEmpty(): void {
        $interval         = new \DateInterval('PT1H');
        $interval->invert = 1;

        $this->assertSame('', (new Duration())->toTimeString($interval));
    }

    /**
     * Sub-second intervals round up like sub-second numbers
     *
     * @return void
     */
    public function testSubSecondIntervalRoundsUp(): void {
        $interval    = new \DateInterval('PT0S');
        $interval->f = 0.25;

        $this->assertSame('1s', (new Duration())->toTimeString($interval));
    }

    /**
     * toMilliseconds() exposes the parsed value
     *
     * @return void
     */
    public function testToMilliseconds(): void {
        $duration = new Duration();

        $this->assertSame(5400000.0, $duration->toMilliseconds('1h30m'));
        $this->assertSame(1500.0, $duration->toMilliseconds(1500));
        $this->assertSame(
            60000.0,
            $duration->toMilliseconds(new \DateInterval('PT1M'))
        );
    }

    /**
     * The sub-second clamp warns once per instance, like JS warnOnce
     *
     * @return void
     */
    public function testSubSecondWarnsOnce(): void {
        $logger   = new RecordingLogger();
        $duration = new Duration($logger);

        $this->assertSame('1s', $duration->toTimeString(500));
        $this->assertSame('1s', $duration->toTimeString(700));
        $this->assertSame('1s', $duration->toTimeString(1500));

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertStringContainsString(
            'round up to 1s',
            $logger->records[0]['message']
        );
        $this->assertSame(
            ['requested_ms' => 500.0],
            $logger->records[0]['context']
        );
    }

    /**
     * Durations of 1s or more never warn
     *
     * @return void
     */
    public function testNoWarningAtOrAboveOneSecond(): void {
        $logger = new RecordingLogger();

        (new Duration($logger))->toTimeString(1000);

        $this->assertCount(0, $logger->records);
    }
}
