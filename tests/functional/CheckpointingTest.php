<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Async checkpointing on a real dev server
 *
 * Ports `integration/checkpointing/*.test.ts`. Functions count their
 * requests in the state store (JS keeps the count in memory).
 *
 * @package DealNews\Inngest\Tests\functional
 */
#[CoversNothing]
#[Group('functional')]
class CheckpointingTest extends FunctionalTestCase {

    /**
     * maxRuntime cases: function, expected handler entries
     *
     * Same counts as JS: the limit doesn't apply before the request's
     * first step has run (D52), so "before" always runs inline.
     *
     * @return array<string, array{string, int}>
     */
    public static function maxRuntimeProvider(): array {
        return [
            'stepless function' => ['stepless', 1],
            'between steps'     => ['between', 3],
            'after step'        => ['after', 1],
        ];
    }

    /**
     * maxRuntime.test.ts: hitting the max runtime returns once, without
     * the SDK carrying on and causing duplicate requests
     *
     * @param string $case     Function suffix
     * @param int    $expected How many times the handler starts
     *
     * @return void
     */
    #[DataProvider('maxRuntimeProvider')]
    public function testMaxRuntime(string $case, int $expected): void {
        $run_id = $this->start(
            "demo/cp.runtime.$case",
            "cp-runtime-$case"
        );

        $this->assertSame('done', $this->assertRunCompletes($run_id));
        $this->assertCount($expected, $this->store()->read($run_id));

        // The bug the JS test guards against: a step run twice because the
        // SDK kept going after it answered
        $ran = array_filter(
            $this->store()->read("ops-$run_id"),
            static fn (array $op) => $op['op'] === 'StepRun'
        );
        $names = array_column($ran, 'name');
        $this->assertSame(array_unique($names), $names);
    }

    /**
     * resumeAfterParallelism.test.ts: after parallel steps the executor
     * hands the run back to checkpointing; no StepPlanned round trip for
     * the steps after them
     *
     * @return void
     */
    public function testResumesCheckpointingAfterParallelism(): void {
        $run_id = $this->start(
            'demo/cp.resume.parallel',
            'cp-resume-parallel'
        );

        $this->assertSame('done', $this->assertRunCompletes($run_id));

        $counts = array_count_values($this->store()->read($run_id));
        ksort($counts);
        $this->assertSame(
            [
                'after-1' => 1,
                'after-2' => 1,
                'after-3' => 1,
                'par-1'   => 1,
                'par-2'   => 1,
                'seq-1'   => 1,
                'seq-2'   => 1,
            ],
            $counts
        );

        $ops = $this->store()->read("ops-$run_id");
        $this->assertContains(
            'RunComplete',
            array_column($ops, 'op'),
            'No RunComplete op recorded'
        );
        $trailing = array_filter(
            $ops,
            static fn (array $op) => $op['op'] === 'StepPlanned' &&
                str_starts_with(
                    (string)($op['displayName'] ?? $op['name']),
                    'after-'
                )
        );
        $this->assertSame([], array_values($trailing));
    }

    /**
     * stepRunSerialization.test.ts: a checkpointed step's output reaches
     * the handler as JSON data, in one request
     *
     * @return void
     */
    public function testCheckpointedOutputIsJson(): void {
        $run_id = $this->start('demo/cp.serialization', 'cp-serialization');

        $this->assertRunCompletes($run_id);

        $entries = $this->store()->read($run_id);
        $this->assertSame(
            [
                'request',
                [
                    'output' => [
                        'date'   => '2026-02-03T00:00:00.000Z',
                        'list'   => ['2026-02-03T00:00:00.000Z'],
                        'nested' => ['date' => '2026-02-03T00:00:00.000Z'],
                    ],
                ],
            ],
            $entries
        );
    }
}
