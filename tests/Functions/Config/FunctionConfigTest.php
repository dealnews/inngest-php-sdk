<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Config;

use DealNews\Inngest\Functions\Config\FunctionConfig;
use DealNews\Inngest\Functions\Config\StepConfig;
use DealNews\Inngest\Functions\Config\StepRuntime;
use DealNews\Inngest\Functions\Options\BatchEvents;
use DealNews\Inngest\Functions\Options\Concurrency;
use DealNews\Inngest\Functions\Options\RateLimit;
use DealNews\Inngest\Functions\Trigger\EventTrigger;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for FunctionConfig
 *
 * Full parity with JS is tested in InngestFunctionTest.
 *
 * @package DealNews\Inngest\Tests\Functions\Config
 */
#[CoversClass(FunctionConfig::class)]
#[UsesClass(BatchEvents::class)]
#[UsesClass(Concurrency::class)]
#[UsesClass(EventTrigger::class)]
#[UsesClass(RateLimit::class)]
#[UsesClass(StepConfig::class)]
#[UsesClass(StepRuntime::class)]
#[UsesClass(ValueObject::class)]
class FunctionConfigTest extends TestCase {

    /**
     * Empty triggers stay a list; empty steps become an object
     *
     * @return void
     */
    public function testEmptyCollections(): void {
        $this->assertSame(
            '{"id":"app-fn","triggers":[],"steps":{}}',
            (new FunctionConfig(id: 'app-fn'))->toJson()
        );
    }

    /**
     * Wire keys are camelCase where Inngest expects it
     *
     * @return void
     */
    public function testWireKeys(): void {
        $config = new FunctionConfig(
            id: 'app-fn',
            name: 'fn',
            triggers: [new EventTrigger('e')],
            steps: ['step' => new StepConfig()],
            batch_events: new BatchEvents(1, '1s'),
            rate_limit: new RateLimit(1, '1s'),
            concurrency: [new Concurrency(1)]
        );

        $data = json_decode($config->toJson(), true);

        $this->assertSame(
            ['id', 'name', 'triggers', 'steps', 'batchEvents', 'rateLimit',
             'concurrency'],
            array_keys($data)
        );
        $this->assertSame([['limit' => 1]], $data['concurrency']);
    }
}
