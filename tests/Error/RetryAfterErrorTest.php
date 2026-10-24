<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Error;

use DealNews\Inngest\Error\RetryAfterError;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for RetryAfterError
 *
 * @package DealNews\Inngest\Tests\Error
 */
#[CoversClass(RetryAfterError::class)]
#[UsesClass(Duration::class)]
class RetryAfterErrorTest extends TestCase {

    /**
     * Relative delays become whole seconds, rounded up (JS Math.ceil)
     *
     * @param int|float|string $input    Delay
     * @param string           $expected Retry-After value
     *
     * @return void
     */
    #[DataProvider('delayProvider')]
    public function testRelativeDelays(
        int|float|string $input,
        string $expected
    ): void {
        $error = new RetryAfterError('later', $input);

        $this->assertSame($expected, $error->getRetryAfter());
    }

    /**
     * Cases for testRelativeDelays
     *
     * @return array<string, array{int|float|string, string}>
     */
    public static function delayProvider(): array {
        return [
            'one second'     => [1000, '1'],
            'rounds up'      => [1500, '2'],
            'sub-second'     => [1, '1'],
            'zero'           => [0, '0'],
            'float'          => [2500.5, '3'],
            'ms string'      => ['30s', '30'],
            'long ms string' => ['2 minutes', '120'],
            'compound'       => ['1h30m', '5400'],
        ];
    }

    /**
     * Dates become ISO strings
     *
     * @return void
     */
    public function testDate(): void {
        $error = new RetryAfterError(
            'later',
            new \DateTimeImmutable('2026-01-02T03:04:05Z')
        );

        $this->assertSame('2026-01-02T03:04:05.000Z', $error->getRetryAfter());
    }

    /**
     * Message and cause are kept
     *
     * @return void
     */
    public function testMessageAndCause(): void {
        $cause = new \LogicException('inner');
        $error = new RetryAfterError('rate limited', 1000, $cause);

        $this->assertSame('rate limited', $error->getMessage());
        $this->assertSame($cause, $error->getPrevious());
    }

    /**
     * Non-finite numbers are rejected
     *
     * @return void
     */
    public function testInfiniteDelayThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(3001);
        new RetryAfterError('later', INF);
    }

    /**
     * Unparseable strings are rejected
     *
     * @return void
     */
    public function testInvalidStringThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(2001);
        new RetryAfterError('later', 'eventually');
    }
}
