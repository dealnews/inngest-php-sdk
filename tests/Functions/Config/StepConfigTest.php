<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Config;

use DealNews\Inngest\Functions\Config\StepConfig;
use DealNews\Inngest\Functions\Config\StepRetries;
use DealNews\Inngest\Functions\Config\StepRuntime;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for StepConfig
 *
 * @package DealNews\Inngest\Tests\Functions\Config
 */
#[CoversClass(StepConfig::class)]
#[UsesClass(StepRetries::class)]
#[UsesClass(StepRuntime::class)]
#[UsesClass(ValueObject::class)]
class StepConfigTest extends TestCase {

    /**
     * Defaults to the single `step` step over HTTP
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"id":"step","name":"step","runtime":{"type":"http","url":""}}',
            (new StepConfig())->toJson()
        );

        $this->assertSame(
            '{"id":"step","name":"step","runtime":{"type":"http",' .
            '"url":"https://x/"},"retries":{"attempts":2}}',
            (new StepConfig(
                runtime: new StepRuntime(url: 'https://x/'),
                retries: new StepRetries(2)
            ))->toJson()
        );
    }
}
