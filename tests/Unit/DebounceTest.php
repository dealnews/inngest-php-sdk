<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Function\Debounce;
use PHPUnit\Framework\TestCase;

class DebounceTest extends TestCase
{
    public function testConstructorWithPeriodOnly(): void
    {
        $debounce = new Debounce(period: '30s');

        $this->assertSame('30s', $debounce->getPeriod());
        $this->assertNull($debounce->getKey());
        $this->assertNull($debounce->getTimeout());
    }

    public function testConstructorWithAllParameters(): void
    {
        $debounce = new Debounce(
            period: '5m',
            key: 'event.data.user_id',
            timeout: '10m'
        );

        $this->assertSame('5m', $debounce->getPeriod());
        $this->assertSame('event.data.user_id', $debounce->getKey());
        $this->assertSame('10m', $debounce->getTimeout());
    }

    public function testConstructorWithKeyOnly(): void
    {
        $debounce = new Debounce(
            period: '1h',
            key: 'event.data.customer_id'
        );

        $this->assertSame('1h', $debounce->getPeriod());
        $this->assertSame('event.data.customer_id', $debounce->getKey());
        $this->assertNull($debounce->getTimeout());
    }

    public function testConstructorWithTimeoutOnly(): void
    {
        $debounce = new Debounce(
            period: '30s',
            timeout: '5m'
        );

        $this->assertSame('30s', $debounce->getPeriod());
        $this->assertNull($debounce->getKey());
        $this->assertSame('5m', $debounce->getTimeout());
    }

    public function testValidPeriodFormats(): void
    {
        $valid_periods = ['1s', '30s', '5m', '60m', '2h', '24h', '7d'];

        foreach ($valid_periods as $period) {
            $debounce = new Debounce(period: $period);
            $this->assertSame($period, $debounce->getPeriod());
        }
    }

    public function testEmptyPeriodThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce period cannot be empty');

        new Debounce(period: '');
    }

    public function testWhitespaceOnlyPeriodThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce period cannot be empty');

        new Debounce(period: '   ');
    }

    public function testInvalidPeriodFormatThrowsException(): void
    {
        $invalid_formats = [
            '30',
            's30',
            '30sec',
            '30 s',
            '30ss',
            'thirty',
            '30x',
        ];

        foreach ($invalid_formats as $format) {
            try {
                new Debounce(period: $format);
                $this->fail("Expected exception for format: {$format}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(
                    'Debounce period must be in format',
                    $e->getMessage()
                );
            }
        }
    }

    public function testPeriodBelowMinimumThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce period must be at least 1 second');

        new Debounce(period: '0s');
    }

    public function testPeriodAboveMaximumThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce period must not exceed 7 days (168 hours)');

        new Debounce(period: '8d');
    }

    public function testPeriodAtMaximumAllowed(): void
    {
        $valid_max_periods = ['7d', '168h', '10080m'];

        foreach ($valid_max_periods as $period) {
            $debounce = new Debounce(period: $period);
            $this->assertSame($period, $debounce->getPeriod());
        }
    }

    public function testPeriodAtMinimumAllowed(): void
    {
        $debounce = new Debounce(period: '1s');
        $this->assertSame('1s', $debounce->getPeriod());
    }

    public function testEmptyTimeoutThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce timeout cannot be empty');

        new Debounce(period: '30s', timeout: '');
    }

    public function testInvalidTimeoutFormatThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce timeout must be in format');

        new Debounce(period: '30s', timeout: 'invalid');
    }

    public function testTimeoutBelowMinimumThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce timeout must be at least 1 second');

        new Debounce(period: '30s', timeout: '0s');
    }

    public function testTimeoutAboveMaximumThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce timeout must not exceed 7 days (168 hours)');

        new Debounce(period: '30s', timeout: '8d');
    }

    public function testValidTimeoutFormats(): void
    {
        $valid_timeouts = ['1s', '30s', '5m', '2h', '7d'];

        foreach ($valid_timeouts as $timeout) {
            $debounce = new Debounce(period: '30s', timeout: $timeout);
            $this->assertSame($timeout, $debounce->getTimeout());
        }
    }

    public function testToArrayWithPeriodOnly(): void
    {
        $debounce = new Debounce(period: '30s');
        $array = $debounce->toArray();

        $this->assertSame(['period' => '30s'], $array);
    }

    public function testToArrayWithKey(): void
    {
        $debounce = new Debounce(
            period: '5m',
            key: 'event.data.user_id'
        );
        $array = $debounce->toArray();

        $this->assertSame([
            'period' => '5m',
            'key'    => 'event.data.user_id',
        ], $array);
    }

    public function testToArrayWithTimeout(): void
    {
        $debounce = new Debounce(
            period: '30s',
            timeout: '10m'
        );
        $array = $debounce->toArray();

        $this->assertSame([
            'period'  => '30s',
            'timeout' => '10m',
        ], $array);
    }

    public function testToArrayWithAllParameters(): void
    {
        $debounce = new Debounce(
            period: '1m',
            key: 'event.data.customer_id + "-" + event.data.region',
            timeout: '15m'
        );
        $array = $debounce->toArray();

        $this->assertSame([
            'period'  => '1m',
            'key'     => 'event.data.customer_id + "-" + event.data.region',
            'timeout' => '15m',
        ], $array);
    }

    public function testComplexKeyExpression(): void
    {
        $key = 'event.data.plan == "enterprise" ? event.data.user_id : "default"';
        $debounce = new Debounce(
            period: '30s',
            key: $key
        );

        $this->assertSame($key, $debounce->getKey());

        $array = $debounce->toArray();
        $this->assertSame($key, $array['key']);
    }

    public function testMultipleKeyExpressions(): void
    {
        $keys = [
            'event.data.user_id',
            'event.data.customer_id + "-" + event.data.account_id',
            'event.user.email',
            'event.data.region',
        ];

        foreach ($keys as $key) {
            $debounce = new Debounce(period: '30s', key: $key);
            $this->assertSame($key, $debounce->getKey());

            $array = $debounce->toArray();
            $this->assertSame($key, $array['key']);
        }
    }

    public function testPeriodConversionValidation(): void
    {
        $test_cases = [
            ['period' => '60s', 'valid' => true],
            ['period' => '1m', 'valid' => true],
            ['period' => '3600s', 'valid' => true],
            ['period' => '60m', 'valid' => true],
            ['period' => '1h', 'valid' => true],
            ['period' => '86400s', 'valid' => true],
            ['period' => '1440m', 'valid' => true],
            ['period' => '24h', 'valid' => true],
            ['period' => '1d', 'valid' => true],
        ];

        foreach ($test_cases as $test_case) {
            if ($test_case['valid']) {
                $debounce = new Debounce(period: $test_case['period']);
                $this->assertSame($test_case['period'], $debounce->getPeriod());
            }
        }
    }

    public function testLargeNumberPeriods(): void
    {
        $debounce = new Debounce(period: '604800s');
        $this->assertSame('604800s', $debounce->getPeriod());

        $debounce = new Debounce(period: '10080m');
        $this->assertSame('10080m', $debounce->getPeriod());

        $debounce = new Debounce(period: '168h');
        $this->assertSame('168h', $debounce->getPeriod());
    }

    public function testPeriodExceedsMaxInSeconds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce period must not exceed 7 days (168 hours)');

        new Debounce(period: '604801s');
    }

    public function testPeriodExceedsMaxInMinutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce period must not exceed 7 days (168 hours)');

        new Debounce(period: '10081m');
    }

    public function testPeriodExceedsMaxInHours(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debounce period must not exceed 7 days (168 hours)');

        new Debounce(period: '169h');
    }
}
