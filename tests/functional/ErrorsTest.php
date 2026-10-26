<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

use DealNews\Inngest\Error\RemoteError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Step errors, retries, and failure handlers on a real dev server
 *
 * Ports the JS `handling-step-errors` / `unhandled-step-errors` function
 * tests and `integration/retry.test.ts` / `onFailure.test.ts`.
 *
 * The JS tests look for a span named after the step with `attempts: 1`;
 * this dev server version puts the total (2 with `retries: 1`) on the step
 * span and one child span per attempt, so that's what is checked.
 *
 * @package DealNews\Inngest\Tests\functional
 */
#[CoversNothing]
#[Group('functional')]
class ErrorsTest extends FunctionalTestCase {

    /**
     * handling-step-errors: caught step errors (with a cause), and a
     * caught error inside a parallel branch
     *
     * @return void
     */
    public function testHandledStepErrors(): void {
        $runs = $this->startTwins(
            'demo/handling.step.errors',
            'handling-step-errors'
        );

        foreach ($runs as $run_id) {
            $this->assertNull($this->assertRunCompletes($run_id));
        }

        // Failed steps aren't checkpointed, so both runs have these spans
        foreach ($runs as $run_id) {
            $a = $this->assertFailedSpan($run_id, 'a', 'Oh no!');
            $this->assertSame('Error', $a->error['cause']['name']);
            $this->assertSame(
                'This is the cause',
                $a->error['cause']['message']
            );

            $d = $this->assertFailedSpan($run_id, 'd fails', 'D failed!');
            $this->assertNull($d->error['cause']);

            $this->assertSame(
                'c succeeds',
                $this->assertSpan($run_id, 'c succeeds')->data
            );
        }

        $this->assertSame(
            'err was: "Oh no!" and the cause was: "This is the cause"',
            $this->assertSpan($runs['nocp'], 'b')->data
        );
        $this->assertSame(
            ['errMessage' => 'D failed!'],
            $this->assertSpan($runs['nocp'], 'e succeeds')->data
        );
    }

    /**
     * unhandled-step-errors: the step fails twice, the run fails, and the
     * next step never runs
     *
     * @return void
     */
    public function testUnhandledStepErrors(): void {
        $runs = $this->startTwins(
            'demo/unhandled.step.errors',
            'unhandled-step-errors'
        );

        foreach ($runs as $run_id) {
            $error = $this->assertRunFails($run_id);
            $this->assertSame('A failed!', $error['message']);

            $a = $this->assertFailedSpan($run_id, 'a fails', 'A failed!');
            $this->assertNull($a->error['cause']);

            $this->assertNull(
                $this->dev()->findSpan($run_id, 'b never runs', null, 0)
            );
        }
    }

    /**
     * Checkpointing modes for the retry test
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
     * retry.test.ts: a step that succeeds on retry doesn't use up the next
     * step's retries
     *
     * @param string $mode `on` or `off`
     *
     * @return void
     */
    #[DataProvider('checkpointingProvider')]
    public function testRetryResetsBetweenSteps(string $mode): void {
        $run_id = $this->start(
            "demo/retry.reset.$mode",
            "retry-reset-$mode"
        );

        $this->assertRunFails($run_id);

        $attempts = ['a' => [], 'b' => []];
        foreach ($this->store()->read($run_id) as $entry) {
            $attempts[$entry['step']][] = $entry['attempt'];
        }
        $this->assertSame(['a' => [0, 1], 'b' => [0, 1]], $attempts);
    }

    /**
     * onFailure.test.ts: the failure handler runs with the error and the
     * original event
     *
     * @return void
     */
    public function testOnFailure(): void {
        $marker = uniqid('failure-', true);
        $run_id = $this->start(
            'demo/on.failure',
            'on-failure',
            ['marker' => $marker]
        );

        $error = $this->assertRunFails($run_id);
        $this->assertSame('RuntimeException', $error['name']);
        $this->assertSame('test failure', $error['message']);

        $entry = $this->dev()->waitFor(
            fn () => $this->store()->read($marker)[0] ?? null,
            DevServer::DEFAULT_TIMEOUT,
            'The failure handler never ran'
        );
        $this->assertSame(
            [
                'event'          => 'inngest/function.failed',
                'error_class'    => RemoteError::class,
                'error_name'     => 'RuntimeException',
                'error_message'  => 'test failure',
                'original_event' => 'demo/on.failure',
                'run_id'         => $run_id,
            ],
            $entry
        );
    }

    /**
     * Asserts a step failed after two attempts with a message
     *
     * @param string $run_id  Run ID
     * @param string $name    Step name
     * @param string $message Error message
     *
     * @return SpanOutput
     */
    protected function assertFailedSpan(
        string $run_id,
        string $name,
        string $message
    ): SpanOutput {
        $span = $this->dev()->findSpan($run_id, $name, 'FAILED');
        $this->assertNotNull($span, "No failed span \"$name\"");
        $this->assertSame(2, $span->attempts);
        $this->assertSame(
            ['Attempt 0', 'Attempt 1'],
            $span->childNames()
        );

        $output = $this->dev()->output((string)$span->output_id);
        $this->assertSame('Error', $output->error['name']);
        $this->assertSame($message, $output->error['message']);
        $this->assertIsString($output->error['stack']);

        return $output;
    }
}
