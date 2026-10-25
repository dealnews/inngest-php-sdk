<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests;

use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepIdResolver;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Tests\mock\RecordingLogger;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Context
 *
 * @package DealNews\Inngest\Tests
 */
#[CoversClass(Context::class)]
#[UsesClass(AiTools::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Engine::class)]
#[UsesClass(ErrorSerializer::class)]
#[UsesClass(Event::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(StepIdResolver::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(ValueObject::class)]
class ContextTest extends TestCase {

    /**
     * Holds the run details
     *
     * @return void
     */
    public function testValues(): void {
        $engine = new Engine(
            new InngestFunction('app', new FunctionOptions(id: 'fn'), 'time'),
            false,
            RunRequest::parse('{"event":{}}', null),
            null,
            new RecordingLogger()
        );
        $event  = new Event('a');
        $logger = new RecordingLogger();
        $tools  = new StepTools($engine);
        $error  = new \RuntimeException('x');

        $context = new Context(
            $event,
            [$event],
            'run',
            1,
            3,
            $tools,
            $logger,
            $error
        );

        $this->assertSame($event, $context->event);
        $this->assertSame([$event], $context->events);
        $this->assertSame('run', $context->run_id);
        $this->assertSame(1, $context->attempt);
        $this->assertSame(3, $context->max_attempts);
        $this->assertSame($tools, $context->step);
        $this->assertSame($logger, $context->logger);
        $this->assertSame($error, $context->error);
    }
}
