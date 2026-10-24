<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Support;

use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Retry (port of JS `retryWithBackoff`)
 *
 * @package DealNews\Inngest\Tests\Support
 */
#[CoversClass(Retry::class)]
class RetryTest extends TestCase {

    /**
     * Delays requested from the fake sleep, in milliseconds
     *
     * @var list<float>
     */
    protected array $sleeps = [];

    /**
     * A successful first attempt returns immediately
     *
     * @return void
     */
    public function testSuccessFirstTry(): void {
        $result = $this->retry()->run(fn () => 'ok');

        $this->assertSame('ok', $result);
        $this->assertSame([], $this->sleeps);
    }

    /**
     * Failures are retried with exponential backoff plus jitter
     *
     * @return void
     */
    public function testRetriesWithBackoff(): void {
        $calls  = 0;
        $result = $this->retry()->run(function () use (&$calls) {
            $calls++;
            if ($calls < 4) {
                throw new \RuntimeException("fail $calls");
            }

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(4, $calls);
        $this->assertCount(3, $this->sleeps);

        foreach ([100, 200, 400] as $index => $base) {
            $this->assertGreaterThanOrEqual($base, $this->sleeps[$index]);
            $this->assertLessThanOrEqual($base + 100, $this->sleeps[$index]);
        }
    }

    /**
     * The last error is thrown once attempts run out
     *
     * @return void
     */
    public function testThrowsAfterMaxAttempts(): void {
        $calls = 0;

        try {
            $this->retry()->run(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException("fail $calls");
            }, 3, 10);
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('fail 3', $e->getMessage());
        }

        $this->assertSame(3, $calls);
        $this->assertCount(2, $this->sleeps);
    }

    /**
     * should_retry returning false stops immediately
     *
     * @return void
     */
    public function testShouldRetryStops(): void {
        $calls = 0;

        $this->expectException(\LogicException::class);

        try {
            $this->retry()->run(
                function () use (&$calls) {
                    $calls++;
                    throw new \LogicException('stop');
                },
                5,
                100,
                fn (\Throwable $e) => !$e instanceof \LogicException
            );
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    /**
     * Attempts below one still run once
     *
     * @return void
     */
    public function testMinimumOneAttempt(): void {
        $this->assertSame(1, $this->retry()->run(fn () => 1, 0));
    }

    /**
     * The default sleep really waits
     *
     * @return void
     */
    public function testDefaultSleep(): void {
        $calls = 0;
        $start = microtime(true);

        (new Retry())->run(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('once');
            }

            return true;
        }, 2, 1);

        $this->assertGreaterThanOrEqual(0.001, microtime(true) - $start);
    }

    /**
     * Builds a Retry that records delays instead of sleeping
     *
     * @return Retry
     */
    protected function retry(): Retry {
        return new Retry(function (float $milliseconds): void {
            $this->sleeps[] = $milliseconds;
        });
    }
}
