<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Execution\ParallelGroup;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for ParallelGroup (Promise.all / Promise.race settling)
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(ParallelGroup::class)]
class ParallelGroupTest extends TestCase {

    /**
     * "All" waits for every branch and orders results by branch key
     *
     * @return void
     */
    public function testAll(): void {
        $group = new ParallelGroup(['a', 'b', 2], false);

        $this->assertFalse($group->settle(2, 'two', null));
        $this->assertFalse($group->settle('a', 'A', null));
        $this->assertFalse($group->settled);
        $this->assertTrue($group->settle('b', null, null));
        $this->assertTrue($group->settled);

        $this->assertSame(
            ['a' => 'A', 'b' => null, 2 => 'two'],
            $group->result()
        );
    }

    /**
     * "All" with no branches is done right away
     *
     * @return void
     */
    public function testAllEmpty(): void {
        $group = new ParallelGroup([], false);

        $this->assertTrue($group->settled);
        $this->assertSame([], $group->result());
    }

    /**
     * The first error decides; later results are ignored
     *
     * @return void
     */
    public function testError(): void {
        $error = new \RuntimeException('first');
        $group = new ParallelGroup([0, 1], false);

        $this->assertTrue($group->settle(1, null, $error));
        $this->assertFalse(
            $group->settle(0, null, new \RuntimeException('second'))
        );
        $this->assertFalse($group->settle(0, 'late', null));

        try {
            $group->result();
            $this->fail('Expected the branch error');
        } catch (\RuntimeException $e) {
            $this->assertSame($error, $e);
        }
    }

    /**
     * Race settles on the first branch to finish
     *
     * @return void
     */
    public function testRace(): void {
        $group = new ParallelGroup([0, 1], true);

        $this->assertFalse($group->settled);
        $this->assertTrue($group->settle(1, 'B', null));
        $this->assertFalse($group->settle(0, 'A', null));
        $this->assertSame('B', $group->result());
    }

    /**
     * A race with no branches never settles on its own
     *
     * @return void
     */
    public function testRaceEmpty(): void {
        $this->assertFalse((new ParallelGroup([], true))->settled);
    }
}
