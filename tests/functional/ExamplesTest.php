<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ports of the JS SDK's `src/test/functions/*` tests that only use
 * `step.run`, `Promise.all`, and `Promise.race`
 *
 * Each function runs twice per event (see `DevFunctions::twin()`): with
 * checkpointing (the default) and without. Both must return the same
 * output; step spans are checked on the copy without checkpointing,
 * because this dev server leaves checkpointed steps out of traces (the
 * JS SDK gets the same traces). The JS tests also match
 * `stepType: "RUN"`, which this server leaves empty.
 *
 * @package DealNews\Inngest\Tests\functional
 */
#[CoversNothing]
#[Group('functional')]
class ExamplesTest extends FunctionalTestCase {

    /**
     * hello-world: returns 'Hello, Inngest!'
     *
     * @return void
     */
    public function testHelloWorld(): void {
        $runs = $this->startTwins('demo/hello.world', 'hello-world');

        $this->assertOutputs('Hello, Inngest!', $runs);
    }

    /**
     * Events for multiple-triggers
     *
     * @return array<string, array{string}>
     */
    public static function triggerProvider(): array {
        return [
            'first'  => ['demo/multiple-triggers.1'],
            'second' => ['demo/multiple-triggers.2'],
        ];
    }

    /**
     * multiple-triggers: either event runs it
     *
     * @param string $event Event name
     *
     * @return void
     */
    #[DataProvider('triggerProvider')]
    public function testMultipleTriggers(string $event): void {
        $runs = $this->startTwins($event, 'multiple-triggers');

        $this->assertOutputs("Hello, $event!", $runs);
    }

    /**
     * sequential-reduce: one step per team, total 150
     *
     * @return void
     */
    public function testSequentialReduce(): void {
        $runs = $this->startTwins(
            'demo/sequential.reduce',
            'sequential-reduce'
        );

        $this->assertOutputs(150, $runs);
        $this->assertTeamSpans($runs['nocp']);
    }

    /**
     * parallel-reduce: team steps in parallel, total 150
     *
     * @return void
     */
    public function testParallelReduce(): void {
        $runs = $this->startTwins('demo/parallel.reduce', 'parallel-reduce');

        $this->assertOutputs(150, $runs);
        $this->assertTeamSpans($runs['nocp']);
    }

    /**
     * parallel-work: a sequential chain next to a parallel group
     *
     * @return void
     */
    public function testParallelWork(): void {
        $runs = $this->startTwins('demo/parallel.work', 'parallel-work');

        $this->assertOutputs([6, 'Apple, Banana, Orange'], $runs);

        foreach (['First', 'Second', 'Third'] as $i => $name) {
            $output = $this->assertSpan($runs['nocp'], "$name score");
            $this->assertSame($i + 1, $output->data);
        }
        foreach (['Apple', 'Banana', 'Orange'] as $fruit) {
            $output = $this->assertSpan(
                $runs['nocp'],
                'Get ' . strtolower($fruit)
            );
            $this->assertSame($fruit, $output->data);
        }
    }

    /**
     * promise-all: two parallel steps, then one that adds them
     *
     * @return void
     */
    public function testPromiseAll(): void {
        $runs = $this->startTwins('demo/promise.all', 'promise-all');

        $this->assertOutputs(3, $runs);
        foreach (['Step 1' => 1, 'Step 2' => 2, 'Step 3' => 3] as $name => $n) {
            $this->assertSame(
                $n,
                $this->assertSpan($runs['nocp'], $name)->data
            );
        }
    }

    /**
     * promise-race: both race steps run; the winner is A or B
     *
     * @return void
     */
    public function testPromiseRace(): void {
        $runs = $this->startTwins('demo/promise.race', 'promise-race');

        $this->assertOutputs(null, $runs);

        $nocp = $runs['nocp'];
        $this->assertSame('A', $this->assertSpan($nocp, 'Step A')->data);
        $this->assertSame('B', $this->assertSpan($nocp, 'Step B')->data);
        $this->assertContains(
            $this->assertSpan($nocp, 'Step C')->data,
            ['A is the winner!', 'B is the winner!']
        );
    }

    /**
     * undefined-data: void steps store null
     *
     * @return void
     */
    public function testUndefinedData(): void {
        $runs = $this->startTwins('demo/undefined.data', 'undefined-data');

        $this->assertOutputs(null, $runs);

        $expected = [
            'step1res'   => 'step1res',
            'step1'      => null,
            'step2res'   => 'step2res',
            'step2nores' => null,
            'step2res2'  => 'step2res2',
            'step2'      => null,
            'step3'      => null,
        ];
        foreach ($expected as $name => $data) {
            $this->assertSame(
                $data,
                $this->assertSpan($runs['nocp'], $name)->data
            );
        }
    }

    /**
     * Asserts every run completes with the same output
     *
     * @param mixed                $expected Output
     * @param array<string,string> $runs     Run IDs
     *
     * @return void
     */
    protected function assertOutputs(mixed $expected, array $runs): void {
        foreach ($runs as $mode => $run_id) {
            $this->assertSame(
                $expected,
                $this->assertRunCompletes($run_id),
                "Output of the $mode run"
            );
        }
    }

    /**
     * Asserts the three team-score spans exist with the right numbers
     *
     * @param string $run_id Run ID
     *
     * @return void
     */
    protected function assertTeamSpans(string $run_id): void {
        foreach (['blue' => 50, 'red' => 25, 'green' => 75] as $team => $n) {
            $this->assertSame(
                $n,
                $this->assertSpan($run_id, "Get $team team score")->data
            );
        }
    }
}
