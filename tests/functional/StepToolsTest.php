<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Step tools other than `run` on a real dev server: `sendEvent`,
 * `invoke`, `sleep`, `sleepUntil`, and `waitForEvent`
 *
 * Ports the JS `send-event`, `step-invoke`, and `step-invoke-not-found`
 * function tests and `integration/subSecondDurations.test.ts`; the
 * sleep/wait scenario is new (the JS suite only checks the polling
 * function's registration).
 *
 * @package DealNews\Inngest\Tests\functional
 */
#[CoversNothing]
#[Group('functional')]
class StepToolsTest extends FunctionalTestCase {

    /**
     * send-event: one step sends one event, another sends two
     *
     * @return void
     */
    public function testSendEvent(): void {
        $marker = uniqid('send-', true);
        $run_id = $this->start(
            'demo/send.event',
            'send-event',
            ['marker' => $marker]
        );

        $this->assertNull($this->assertRunCompletes($run_id));

        $names = [
            'app/my.event.happened',
            'app/my.event.happened.multiple.1',
            'app/my.event.happened.multiple.2',
        ];
        foreach ($names as $name) {
            $payload = $this->dev()->waitFor(
                function () use ($name, $marker): ?array {
                    $found = null;
                    foreach ($this->dev()->events($name) as $event) {
                        $data = json_decode($event['payload'], true);
                        if (($data['marker'] ?? null) === $marker) {
                            $found = $data;
                        }
                    }

                    return $found;
                },
                DevServer::DEFAULT_TIMEOUT,
                "Event $name never arrived"
            );
            $this->assertSame('bar', $payload['foo']);
        }
    }

    /**
     * step-invoke: invokes an event function and a cron function in
     * parallel and gets both results back
     *
     * @return void
     */
    public function testInvoke(): void {
        $run_id = $this->start('demo/step.invoke', 'step-invoke');

        $this->assertSame(
            [['eventInvokeDone' => true], ['cronInvokeDone' => true]],
            $this->assertRunCompletes($run_id)
        );
        $this->assertSpan($run_id, 'event-fn');
        $this->assertSpan($run_id, 'cron-fn');
    }

    /**
     * step-invoke-not-found: the invoke step fails with the server's
     * error, and so does the run
     *
     * @return void
     */
    public function testInvokeNotFound(): void {
        $run_id = $this->start(
            'demo/step.invoke.not-found',
            'step-invoke-not-found'
        );

        $output = $this->assertSpan(
            $run_id,
            'invoke-non-existent-fn',
            'FAILED'
        );
        $this->assertSame('Error', $output->error['name']);
        $this->assertStringContainsString(
            'could not find function',
            $output->error['message']
        );

        $error = $this->assertRunFails($run_id);
        $this->assertStringContainsString(
            'could not find function',
            $error['message']
        );
    }

    /**
     * sleep-wait: sleep, sleepUntil, a waitForEvent that's resumed by a
     * matching event, and one that times out
     *
     * @return void
     */
    public function testSleepAndWaitForEvent(): void {
        $id     = uniqid('wait-', true);
        $run_id = $this->start('demo/sleep.wait', 'sleep-wait', ['id' => $id]);

        // Only events sent after the wait starts can match it, so keep
        // sending until the run ends
        $result = $this->dev()->waitFor(
            function () use ($run_id, $id): ?RunResult {
                $this->send('demo/sleep.wait.resume', [
                    'id'  => $id,
                    'msg' => 'resumed',
                ]);
                try {
                    $result = $this->dev()->waitForRunEnd($run_id, 1);
                } catch (\RuntimeException) {
                    $result = null;
                }

                return $result;
            },
            DevServer::DEFAULT_TIMEOUT,
            "Run $run_id never ended"
        );

        $this->assertSame('COMPLETED', $result->status);
        $this->assertSame(
            [
                'resumed'   => ['id' => $id, 'msg' => 'resumed'],
                'timed_out' => true,
            ],
            $result->output->data
        );
        foreach (['nap', 'nap-until', 'wait-resume', 'wait-timeout'] as $name) {
            $this->assertSpan($run_id, $name);
        }
    }

    /**
     * Sleep durations in milliseconds
     *
     * @return array<string, array{string}>
     */
    public static function sleepProvider(): array {
        return [
            // Rounds up to 1s instead of becoming "" (no sleep at all)
            '500ms'  => ['500'],
            // Floors to 1s
            '1500ms' => ['1500'],
        ];
    }

    /**
     * subSecondDurations.test.ts: integer sleeps are milliseconds and
     * never shorter than a second
     *
     * @param string $ms Function suffix
     *
     * @return void
     */
    #[DataProvider('sleepProvider')]
    public function testSubSecondSleep(string $ms): void {
        $run_id = $this->start("demo/sleep.ms.$ms", "sleep-ms-$ms");

        $this->assertSame('done', $this->assertRunCompletes($run_id));

        $before = null;
        $after  = null;
        foreach ($this->store()->read($run_id) as $entry) {
            $before ??= $entry['before'] ?? null;
            $after  ??= $entry['after']  ?? null;
        }
        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertGreaterThanOrEqual(0.9, $after - $before);
    }

    /**
     * subSecondDurations.test.ts: a 500ms waitForEvent timeout times out
     * with null
     *
     * @return void
     */
    public function testSubSecondWaitTimesOut(): void {
        $run_id = $this->start('demo/wait.ms.500', 'wait-ms-500');

        $this->assertSame(
            ['result' => null],
            $this->assertRunCompletes($run_id)
        );
    }
}
