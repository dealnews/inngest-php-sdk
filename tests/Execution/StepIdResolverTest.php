<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Execution\StepIdResolver;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for StepIdResolver (ports `resolveStepIdCollision`)
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(StepIdResolver::class)]
class StepIdResolverTest extends TestCase {

    /**
     * IDs hash with SHA-1
     *
     * @return void
     */
    public function testHash(): void {
        $this->assertSame(
            '6dcd4ce23d88e2ee9568ba546c007c63d9131c1b',
            StepIdResolver::hash('A')
        );
    }

    /**
     * Repeated IDs get `:1`, `:2`, ...
     *
     * @return void
     */
    public function testRepeatedIds(): void {
        $ids   = new StepIdResolver();
        $taken = [];

        foreach ([['A', null], ['A:1', 1], ['A:2', 2]] as [$id, $index]) {
            $this->assertSame([$id, $index], $ids->resolve('A', $taken));
            $taken[StepIdResolver::hash($id)] = true;
        }

        $this->assertSame(['B', null], $ids->resolve('B', $taken));
    }

    /**
     * A claimed ID collides even before it's in the taken list
     *
     * @return void
     */
    public function testClaimedIdCollides(): void {
        $ids = new StepIdResolver();

        $this->assertSame(['A', null], $ids->resolve('A', []));
        $this->assertSame(['A:1', 1], $ids->resolve('A', []));
        $this->assertSame(['A:2', 2], $ids->resolve('A', []));
    }

    /**
     * Taken indexes are skipped
     *
     * @return void
     */
    public function testSkipsTakenIndexes(): void {
        $ids   = new StepIdResolver();
        $taken = [
            StepIdResolver::hash('A')   => true,
            StepIdResolver::hash('A:1') => true,
        ];

        $this->assertSame(['A:2', 2], $ids->resolve('A', $taken));
        $this->assertSame(['A:3', 3], $ids->resolve('A', $taken));
    }

    /**
     * Running out of indexes is a bug, reported as a LogicException
     *
     * @return void
     */
    public function testNoFreeIndex(): void {
        $ids = new class() extends StepIdResolver {
            /**
             * Every ID looks taken
             *
             * @param string $id ID
             *
             * @return string
             */
            public static function hash(string $id): string {
                return 'same';
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(7201);

        $ids->resolve('A', ['same' => true]);
    }
}
