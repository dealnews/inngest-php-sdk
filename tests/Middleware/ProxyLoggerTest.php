<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Middleware;

use DealNews\Inngest\Middleware\ProxyLogger;
use DealNews\Inngest\Tests\mock\RecordingLogger;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for ProxyLogger
 *
 * Port of the ProxyLogger cases in middleware/logger.test.ts.
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(ProxyLogger::class)]
class ProxyLoggerTest extends TestCase {

    /**
     * Drops log calls until enabled, and again once disabled
     *
     * @return void
     */
    public function testEnableDisable(): void {
        $inner = new RecordingLogger();
        $proxy = new ProxyLogger($inner);

        $this->assertFalse($proxy->isEnabled());
        $proxy->info('dropped');
        $this->assertSame([], $inner->records);

        $proxy->enable();
        $this->assertTrue($proxy->isEnabled());
        $proxy->warning('kept');

        $proxy->disable();
        $this->assertFalse($proxy->isEnabled());
        $proxy->error('dropped too');

        $this->assertSame(
            [['level' => 'warning', 'message' => 'kept', 'context' => []]],
            $inner->records
        );
    }

    /**
     * Adds run context; the record's own context wins
     *
     * @return void
     */
    public function testContext(): void {
        $inner = new RecordingLogger();
        $proxy = new ProxyLogger($inner, ['runID' => 'run-1', 'a' => 1]);
        $proxy->enable();

        $proxy->info('hello', ['a' => 2, 'b' => 3]);

        $this->assertSame(
            ['runID' => 'run-1', 'a' => 2, 'b' => 3],
            $inner->records[0]['context']
        );
    }
}
