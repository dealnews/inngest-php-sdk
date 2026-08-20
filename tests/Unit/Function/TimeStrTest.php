<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit\Function;

use DealNews\Inngest\Function\TimeStr;
use PHPUnit\Framework\TestCase;

class TimeStrTest extends TestCase
{
    public function testSimpleSeconds(): void
    {
        $this->assertSame(3000, TimeStr::toMilliseconds('3s'));
    }

    public function testSimpleMilliseconds(): void
    {
        $this->assertSame(500, TimeStr::toMilliseconds('500ms'));
    }

    public function testSimpleMinutes(): void
    {
        $this->assertSame(300_000, TimeStr::toMilliseconds('5m'));
    }

    public function testSimpleHours(): void
    {
        $this->assertSame(3_600_000, TimeStr::toMilliseconds('1h'));
    }

    public function testSimpleDays(): void
    {
        $this->assertSame(86_400_000, TimeStr::toMilliseconds('1d'));
    }

    public function testSimpleWeeks(): void
    {
        $this->assertSame(604_800_000, TimeStr::toMilliseconds('1w'));
    }

    public function testCompositeHoursAndMinutes(): void
    {
        $this->assertSame(9_900_000, TimeStr::toMilliseconds('2h45m'));
    }

    public function testMicroseconds(): void
    {
        // 500us = 0.5ms, 1500us = 1.5ms; round() rounds halves away from zero.
        $this->assertSame(1, TimeStr::toMilliseconds('500us'));
        $this->assertSame(2, TimeStr::toMilliseconds('1500us'));
    }

    public function testZeroSeconds(): void
    {
        $this->assertSame(0, TimeStr::toMilliseconds('0s'));
    }

    public function testEmptyStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeStr::toMilliseconds('');
    }

    public function testMissingUnitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeStr::toMilliseconds('3');
    }

    public function testInvalidUnitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeStr::toMilliseconds('3x');
    }

    public function testUnitBeforeNumberThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeStr::toMilliseconds('s3');
    }
}
