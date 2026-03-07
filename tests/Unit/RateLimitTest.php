<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Function\RateLimit;
use PHPUnit\Framework\TestCase;

class RateLimitTest extends TestCase
{
    public function testConstructorWithRequiredParameters(): void
    {
        $rate_limit = new RateLimit(limit: 10, period: '1h');

        $this->assertSame(10, $rate_limit->getLimit());
        $this->assertSame('1h', $rate_limit->getPeriod());
        $this->assertNull($rate_limit->getKey());
    }

    public function testConstructorWithAllParameters(): void
    {
        $rate_limit = new RateLimit(
            limit: 5,
            period: '30m',
            key: 'event.data.user_id'
        );

        $this->assertSame(5, $rate_limit->getLimit());
        $this->assertSame('30m', $rate_limit->getPeriod());
        $this->assertSame('event.data.user_id', $rate_limit->getKey());
    }

    public function testConstructorWithComplexKey(): void
    {
        $rate_limit = new RateLimit(
            limit: 100,
            period: '24h',
            key: 'event.data.customer_id + "-" + event.data.region'
        );

        $this->assertSame(100, $rate_limit->getLimit());
        $this->assertSame('24h', $rate_limit->getPeriod());
        $this->assertSame(
            'event.data.customer_id + "-" + event.data.region',
            $rate_limit->getKey()
        );
    }

    public function testValidPeriodFormatsSeconds(): void
    {
        $valid_periods = ['1s', '30s', '60s'];

        foreach ($valid_periods as $period) {
            $rate_limit = new RateLimit(limit: 10, period: $period);
            $this->assertSame($period, $rate_limit->getPeriod());
        }
    }

    public function testValidPeriodFormatsMinutes(): void
    {
        $valid_periods = ['1m', '5m', '30m', '60m'];

        foreach ($valid_periods as $period) {
            $rate_limit = new RateLimit(limit: 10, period: $period);
            $this->assertSame($period, $rate_limit->getPeriod());
        }
    }

    public function testValidPeriodFormatsHours(): void
    {
        $valid_periods = ['1h', '2h', '12h', '24h'];

        foreach ($valid_periods as $period) {
            $rate_limit = new RateLimit(limit: 10, period: $period);
            $this->assertSame($period, $rate_limit->getPeriod());
        }
    }

    public function testValidLimitValues(): void
    {
        $valid_limits = [1, 10, 100, 1000, 10000];

        foreach ($valid_limits as $limit) {
            $rate_limit = new RateLimit(limit: $limit, period: '1h');
            $this->assertSame($limit, $rate_limit->getLimit());
        }
    }

    public function testEmptyPeriodThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rate limit period cannot be empty');

        new RateLimit(limit: 10, period: '');
    }

    public function testWhitespaceOnlyPeriodThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rate limit period cannot be empty');

        new RateLimit(limit: 10, period: '   ');
    }

    public function testInvalidPeriodFormatNoUnitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rate limit period must be in format: <number><unit>'
        );

        new RateLimit(limit: 10, period: '30');
    }

    public function testInvalidPeriodFormatOnlyUnitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rate limit period must be in format: <number><unit>'
        );

        new RateLimit(limit: 10, period: 's');
    }

    public function testInvalidPeriodFormatInvalidUnitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rate limit period must be in format: <number><unit>'
        );

        new RateLimit(limit: 10, period: '30x');
    }

    public function testPeriodWithDaysThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rate limit period must be in format: <number><unit>'
        );

        new RateLimit(limit: 10, period: '7d');
    }

    public function testPeriodWithDecimalThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rate limit period must be in format: <number><unit>'
        );

        new RateLimit(limit: 10, period: '30.5s');
    }

    public function testZeroPeriodThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rate limit period must be at least 1 second'
        );

        new RateLimit(limit: 10, period: '0s');
    }

    public function testPeriodExceeding24HoursThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rate limit period must not exceed 24 hours (86400 seconds)'
        );

        new RateLimit(limit: 10, period: '25h');
    }

    public function testPeriodExceeding24HoursInSecondsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rate limit period must not exceed 24 hours (86400 seconds)'
        );

        new RateLimit(limit: 10, period: '86401s');
    }

    public function testPeriodExceeding24HoursInMinutesThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rate limit period must not exceed 24 hours (86400 seconds)'
        );

        new RateLimit(limit: 10, period: '1441m');
    }

    public function testPeriodExactly24HoursIsValid(): void
    {
        $rate_limit = new RateLimit(limit: 10, period: '24h');
        $this->assertSame('24h', $rate_limit->getPeriod());

        $rate_limit = new RateLimit(limit: 10, period: '1440m');
        $this->assertSame('1440m', $rate_limit->getPeriod());

        $rate_limit = new RateLimit(limit: 10, period: '86400s');
        $this->assertSame('86400s', $rate_limit->getPeriod());
    }

    public function testZeroLimitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rate limit must be at least 1');

        new RateLimit(limit: 0, period: '1h');
    }

    public function testNegativeLimitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rate limit must be at least 1');

        new RateLimit(limit: -1, period: '1h');
    }

    public function testToArrayWithoutKey(): void
    {
        $rate_limit = new RateLimit(limit: 10, period: '1h');

        $array = $rate_limit->toArray();

        $this->assertSame([
            'limit'  => 10,
            'period' => '1h',
        ], $array);
    }

    public function testToArrayWithKey(): void
    {
        $rate_limit = new RateLimit(
            limit: 5,
            period: '30m',
            key: 'event.data.user_id'
        );

        $array = $rate_limit->toArray();

        $this->assertSame([
            'limit'  => 5,
            'period' => '30m',
            'key'    => 'event.data.user_id',
        ], $array);
    }

    public function testToArrayWithComplexKey(): void
    {
        $rate_limit = new RateLimit(
            limit: 100,
            period: '24h',
            key: 'event.data.customer_id + "-" + event.data.region'
        );

        $array = $rate_limit->toArray();

        $this->assertSame([
            'limit'  => 100,
            'period' => '24h',
            'key'    => 'event.data.customer_id + "-" + event.data.region',
        ], $array);
    }
}
