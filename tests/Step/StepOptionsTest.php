<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Step;

use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for StepOptions
 *
 * @package DealNews\Inngest\Tests\Step
 */
#[CoversClass(StepOptions::class)]
class StepOptionsTest extends TestCase {

    /**
     * Strings become options; options pass through
     *
     * @return void
     */
    public function testFrom(): void {
        $from_string = StepOptions::from('fetch');
        $this->assertSame('fetch', $from_string->id);
        $this->assertNull($from_string->name);

        $options = new StepOptions('fetch', 'Fetch it');
        $this->assertSame($options, StepOptions::from($options));
    }
}
