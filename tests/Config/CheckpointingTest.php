<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Config;

use DealNews\Inngest\Config\Checkpointing;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for Checkpointing
 *
 * @package DealNews\Inngest\Tests\Config
 */
#[CoversClass(Checkpointing::class)]
class CheckpointingTest extends TestCase {

    /**
     * Holds the settings; unset ones are null
     *
     * @return void
     */
    public function testValues(): void {
        $options = new Checkpointing('5s', 3, 250);
        $this->assertSame('5s', $options->max_runtime);
        $this->assertSame(3, $options->buffered_steps);
        $this->assertSame(250, $options->max_interval);

        $defaults = new Checkpointing();
        $this->assertNull($defaults->max_runtime);
        $this->assertNull($defaults->buffered_steps);
        $this->assertNull($defaults->max_interval);
    }
}
