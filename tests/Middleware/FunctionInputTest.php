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
use DealNews\Inngest\Middleware\FunctionInput;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Tests\mock\RecordingEngine;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for FunctionInput
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(FunctionInput::class)]
#[UsesClass(AiTools::class)]
#[UsesClass(Context::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Event::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(MiddlewareStack::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(ValueObject::class)]
class FunctionInputTest extends TestCase {

    /**
     * Holds the context, function, and memoized steps
     *
     * @return void
     */
    public function testValues(): void {
        $engine   = RecordingEngine::create();
        $ctx      = $engine->getContext();
        $function = $engine->getFunction();
        $steps    = ['hash' => new StepState(StepState::TYPE_DATA, 1)];

        $input = new FunctionInput($ctx, $function, $steps);

        $this->assertSame($ctx, $input->ctx);
        $this->assertSame($function, $input->function);
        $this->assertSame($steps, $input->steps);
    }
}
