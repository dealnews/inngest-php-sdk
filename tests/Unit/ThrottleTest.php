<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Function\Throttle;
use PHPUnit\Framework\TestCase;

class ThrottleTest extends TestCase
{
    public function testConstructorWithRequiredParameters(): void
    {
        $throttle = new Throttle(limit: 10, period: '1h');

        $this->assertSame(10, $throttle->getLimit());
        $this->assertSame('1h', $throttle->getPeriod());
        $this->assertSame(0, $throttle->getBurst());
        $this->assertNull($throttle->getKey());
    }

    public function testConstructorWithAllParameters(): void
    {
        $throttle = new Throttle(
            limit: 5,
            period: '30m',
            burst: 3,
            key: 'event.data.user_id'
        );

        $this->assertSame(5, $throttle->getLimit());
        $this->assertSame('30m', $throttle->getPeriod());
        $this->assertSame(3, $throttle->getBurst());
        $this->assertSame('event.data.user_id', $throttle->getKey());
    }

    public function testConstructorWithBurstOnly(): void
    {
        $throttle = new Throttle(
            limit: 10,
            period: '1h',
            burst: 5
        );

        $this->assertSame(10, $throttle->getLimit());
        $this->assertSame('1h', $throttle->getPeriod());
        $this->assertSame(5, $throttle->getBurst());
        $this->assertNull($throttle->getKey());
    }

    public function testConstructorWithKeyOnly(): void
    {
        $throttle = new Throttle(
            limit: 10,
            period: '1h',
            key: 'event.data.customer_id'
        );

        $this->assertSame(10, $throttle->getLimit());
        $this->assertSame('1h', $throttle->getPeriod());
        $this->assertSame(0, $throttle->getBurst());
        $this->assertSame('event.data.customer_id', $throttle->getKey());
    }

    public function testConstructorWithComplexKey(): void
    {
        $throttle = new Throttle(
            limit: 100,
            period: '24h',
            burst: 10,
            key: 'event.data.customer_id + "-" + event.data.region'
        );

        $this->assertSame(100, $throttle->getLimit());
        $this->assertSame('24h', $throttle->getPeriod());
        $this->assertSame(10, $throttle->getBurst());
        $this->assertSame(
            'event.data.customer_id + "-" + event.data.region',
            $throttle->getKey()
        );
    }

    public function testValidPeriodFormatsSeconds(): void
    {
        $valid_periods = ['1s', '30s', '60s'];

        foreach ($valid_periods as $period) {
            $throttle = new Throttle(limit: 10, period: $period);
            $this->assertSame($period, $throttle->getPeriod());
        }
    }

    public function testValidPeriodFormatsMinutes(): void
    {
        $valid_periods = ['1m', '5m', '30m', '60m'];

        foreach ($valid_periods as $period) {
            $throttle = new Throttle(limit: 10, period: $period);
            $this->assertSame($period, $throttle->getPeriod());
        }
    }

    public function testValidPeriodFormatsHours(): void
    {
        $valid_periods = ['1h', '2h', '12h', '24h'];

        foreach ($valid_periods as $period) {
            $throttle = new Throttle(limit: 10, period: $period);
            $this->assertSame($period, $throttle->getPeriod());
        }
    }

    public function testValidPeriodFormatsDays(): void
    {
        $valid_periods = ['1d', '2d', '7d'];

        foreach ($valid_periods as $period) {
            $throttle = new Throttle(limit: 10, period: $period);
            $this->assertSame($period, $throttle->getPeriod());
        }
    }

    public function testValidLimitValues(): void
    {
        $valid_limits = [1, 10, 100, 1000, 10000];

        foreach ($valid_limits as $limit) {
            $throttle = new Throttle(limit: $limit, period: '1h');
            $this->assertSame($limit, $throttle->getLimit());
        }
    }

    public function testValidBurstValues(): void
    {
        $valid_bursts = [0, 1, 5, 10, 100, 1000];

        foreach ($valid_bursts as $burst) {
            $throttle = new Throttle(limit: 10, period: '1h', burst: $burst);
            $this->assertSame($burst, $throttle->getBurst());
        }
    }

    public function testEmptyPeriodThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Throttle period cannot be empty');

        new Throttle(limit: 10, period: '');
    }

    public function testWhitespaceOnlyPeriodThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Throttle period cannot be empty');

        new Throttle(limit: 10, period: '   ');
    }

    public function testInvalidPeriodFormatNoUnitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Throttle period must be in format: <number><unit>'
        );

        new Throttle(limit: 10, period: '30');
    }

    public function testInvalidPeriodFormatOnlyUnitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Throttle period must be in format: <number><unit>'
        );

        new Throttle(limit: 10, period: 's');
    }

    public function testInvalidPeriodFormatInvalidUnitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Throttle period must be in format: <number><unit>'
        );

        new Throttle(limit: 10, period: '30x');
    }

    public function testPeriodWithDecimalThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Throttle period must be in format: <number><unit>'
        );

        new Throttle(limit: 10, period: '30.5s');
    }

    public function testZeroPeriodThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Throttle period must be at least 1 second'
        );

        new Throttle(limit: 10, period: '0s');
    }

    public function testPeriodExceeding7DaysThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Throttle period must not exceed 7 days (604800 seconds)'
        );

        new Throttle(limit: 10, period: '8d');
    }

    public function testPeriodExceeding7DaysInHoursThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Throttle period must not exceed 7 days (604800 seconds)'
        );

        new Throttle(limit: 10, period: '169h');
    }

    public function testPeriodExceeding7DaysInSecondsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Throttle period must not exceed 7 days (604800 seconds)'
        );

        new Throttle(limit: 10, period: '604801s');
    }

    public function testPeriodExactly7DaysIsValid(): void
    {
        $throttle = new Throttle(limit: 10, period: '7d');
        $this->assertSame('7d', $throttle->getPeriod());

        $throttle = new Throttle(limit: 10, period: '168h');
        $this->assertSame('168h', $throttle->getPeriod());

        $throttle = new Throttle(limit: 10, period: '604800s');
        $this->assertSame('604800s', $throttle->getPeriod());
    }

    public function testZeroLimitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Throttle limit must be at least 1');

        new Throttle(limit: 0, period: '1h');
    }

    public function testNegativeLimitThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Throttle limit must be at least 1');

        new Throttle(limit: -1, period: '1h');
    }

    public function testNegativeBurstThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Throttle burst must be at least 0');

        new Throttle(limit: 10, period: '1h', burst: -1);
    }

    public function testToArrayWithoutBurstOrKey(): void
    {
        $throttle = new Throttle(limit: 10, period: '1h');

        $array = $throttle->toArray();

        $this->assertSame([
            'limit'  => 10,
            'period' => '1h',
        ], $array);
    }

    public function testToArrayWithBurstOnly(): void
    {
        $throttle = new Throttle(limit: 10, period: '1h', burst: 5);

        $array = $throttle->toArray();

        $this->assertSame([
            'limit'  => 10,
            'period' => '1h',
            'burst'  => 5,
        ], $array);
    }

    public function testToArrayWithKeyOnly(): void
    {
        $throttle = new Throttle(
            limit: 5,
            period: '30m',
            key: 'event.data.user_id'
        );

        $array = $throttle->toArray();

        $this->assertSame([
            'limit'  => 5,
            'period' => '30m',
            'key'    => 'event.data.user_id',
        ], $array);
    }

    public function testToArrayWithBurstAndKey(): void
    {
        $throttle = new Throttle(
            limit: 100,
            period: '24h',
            burst: 10,
            key: 'event.data.customer_id'
        );

        $array = $throttle->toArray();

        $this->assertSame([
            'limit'  => 100,
            'period' => '24h',
            'burst'  => 10,
            'key'    => 'event.data.customer_id',
        ], $array);
    }

    public function testToArrayWithZeroBurstExcludesBurst(): void
    {
        $throttle = new Throttle(limit: 10, period: '1h', burst: 0);

        $array = $throttle->toArray();

        $this->assertSame([
            'limit'  => 10,
            'period' => '1h',
        ], $array);
        $this->assertArrayNotHasKey('burst', $array);
    }

    public function testToArrayWithComplexKey(): void
    {
        $throttle = new Throttle(
            limit: 50,
            period: '5m',
            burst: 5,
            key: 'event.data.customer_id + "-" + event.data.region'
        );

        $array = $throttle->toArray();

        $this->assertSame([
            'limit'  => 50,
            'period' => '5m',
            'burst'  => 5,
            'key'    => 'event.data.customer_id + "-" + event.data.region',
        ], $array);
    }
}
