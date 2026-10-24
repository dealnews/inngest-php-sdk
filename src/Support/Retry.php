<?php

declare(strict_types=1);

namespace DealNews\Inngest\Support;

/**
 * Retries a callable with exponential backoff and jitter
 *
 * Port of the JS SDK's `retryWithBackoff` (helpers/promises.ts). The delay
 * before attempt `n + 1` is `base * 2^(n - 1)` plus up to `base` of random
 * jitter, in milliseconds.
 *
 * Usage:
 *
 * ```php
 * $result = (new Retry())->run(fn () => $api->call(), max_attempts: 5);
 * ```
 *
 * @package DealNews\Inngest\Support
 */
class Retry {

    /**
     * Sleeps for a number of milliseconds
     *
     * @var \Closure(float): void
     */
    protected \Closure $sleep;

    /**
     * Creates the helper
     *
     * @param callable(float): void|null $sleep Sleeps for the given
     *                                          milliseconds; defaults to
     *                                          usleep(). Tests pass a no-op.
     */
    public function __construct(?callable $sleep = null) {
        $this->sleep = $sleep !== null ?
            \Closure::fromCallable($sleep) :
            static function (float $milliseconds): void {
                usleep((int)($milliseconds * 1000));
            };
    }

    /**
     * Runs the callable until it succeeds or attempts run out
     *
     * @param callable                           $callable      Work to do
     * @param int                                $max_attempts  Total attempts
     * @param int                                $base_delay_ms Base delay
     * @param callable(\Throwable): bool|null    $should_retry  Return false to
     *                                                          stop retrying
     *
     * @throws \Throwable The last error when all attempts fail
     *
     * @return mixed The callable's return value
     */
    public function run(
        callable $callable,
        int $max_attempts = 5,
        int $base_delay_ms = 100,
        ?callable $should_retry = null
    ): mixed {
        $max_attempts = max(1, $max_attempts);
        $attempt      = 1;
        $result       = null;
        $done         = false;

        while (!$done) {
            try {
                $result = $callable();
                $done   = true;
            } catch (\Throwable $e) {
                $stop = $attempt >= $max_attempts ||
                    ($should_retry !== null && $should_retry($e) === false);
                if ($stop) {
                    throw $e;
                }

                $jitter = (mt_rand() / mt_getrandmax()) * $base_delay_ms;
                ($this->sleep)(
                    $base_delay_ms * (2 ** ($attempt - 1)) + $jitter
                );
                $attempt++;
            }
        }

        return $result;
    }
}
