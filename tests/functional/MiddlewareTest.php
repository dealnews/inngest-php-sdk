<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Middleware and the built-in logger across real requests
 *
 * Ports `integration/middleware/lifecycle.test.ts`, the
 * dependency-injection use case, and the replay part of
 * `integration/logger.test.ts`. Hook order within a request is covered
 * byte-for-byte by MiddlewareParityTest; these check what only shows up
 * across several requests from a real executor.
 *
 * @package DealNews\Inngest\Tests\functional
 */
#[CoversNothing]
#[Group('functional')]
class MiddlewareTest extends FunctionalTestCase {

    /**
     * lifecycle.test.ts: every request gets a fresh middleware instance,
     * shared by every hook in that request
     *
     * The function runs without checkpointing, so each step gets its own
     * request.
     *
     * @return void
     */
    public function testFreshInstancePerRequest(): void {
        // Two runs, like the JS test
        foreach ([1, 2] as $attempt) {
            $run_id = $this->start('demo/mw.lifecycle', 'mw-lifecycle');
            $this->assertSame('done', $this->assertRunCompletes($run_id));

            $entries = $this->store()->read($run_id);
            $starts  = array_values(array_filter(
                $entries,
                static fn (array $e) => $e['hook'] === 'onStepStart'
            ));
            $wraps   = array_values(array_filter(
                $entries,
                static fn (array $e) => $e['hook'] === 'wrapFunctionHandler'
            ));

            $this->assertSame(
                ['step-1', 'step-2'],
                array_column($starts, 'step'),
                "Run $attempt"
            );
            // A counter that kept going would show up as 2 here
            $this->assertSame([1, 1], array_column($starts, 'counter'));
            $this->assertNotEmpty($wraps);
            foreach ($wraps as $wrap) {
                $this->assertSame('set-in-transform', $wrap['data']);
            }
        }
    }

    /**
     * Dependency injection: middleware values reach the handler
     *
     * @return void
     */
    public function testDependencyInjection(): void {
        $run_id = $this->start('demo/mw.di', 'mw-di');

        $this->assertSame(
            'hello from DI',
            $this->assertRunCompletes($run_id)
        );
    }

    /**
     * Checkpointing modes
     *
     * @return array<string, array{string}>
     */
    public static function checkpointingProvider(): array {
        return [
            'checkpointing'    => ['on'],
            'no checkpointing' => ['off'],
        ];
    }

    /**
     * `$ctx->logger` logs each line once, however many requests replay
     * the code before it
     *
     * @param string $mode `on` or `off`
     *
     * @return void
     */
    #[DataProvider('checkpointingProvider')]
    public function testLoggerIsQuietDuringReplay(string $mode): void {
        $run_id = $this->start("demo/logger.$mode", "logger-$mode");

        $this->assertSame('done', $this->assertRunCompletes($run_id));
        $this->assertSame(
            ['before', 'between', 'after'],
            $this->store()->read("log-$run_id")
        );
    }
}
