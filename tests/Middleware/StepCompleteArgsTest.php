<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Middleware;

use DealNews\Inngest\Context;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Middleware\StepCompleteArgs;
use DealNews\Inngest\Middleware\StepInfo;
use DealNews\Inngest\Middleware\StepType;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Tests\mock\RecordingEngine;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for StepCompleteArgs
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(StepCompleteArgs::class)]
#[UsesClass(AiTools::class)]
#[UsesClass(Context::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Event::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(MiddlewareStack::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(StepInfo::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(ValueObject::class)]
class StepCompleteArgsTest extends TestCase {

    /**
     * Holds the step and output
     *
     * @return void
     */
    public function testValues(): void {
        $engine   = RecordingEngine::create();
        $ctx      = $engine->getContext();
        $function = $engine->getFunction();
        $info     = new StepInfo(
            'hash',
            StepType::Run,
            new StepOptions('A'),
            false
        );

        $args = new StepCompleteArgs($ctx, $function, $info, 'out');

        $this->assertSame($info, $args->step_info);
        $this->assertSame('out', $args->output);
    }
}
