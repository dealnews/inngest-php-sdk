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
use DealNews\Inngest\Middleware\WrapFunctionHandlerArgs;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Tests\mock\RecordingEngine;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for WrapFunctionHandlerArgs
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(WrapFunctionHandlerArgs::class)]
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
class WrapFunctionHandlerArgsTest extends TestCase {

    /**
     * next() runs the rest of the chain
     *
     * @return void
     */
    public function testNext(): void {
        $engine   = RecordingEngine::create();
        $ctx      = $engine->getContext();
        $function = $engine->getFunction();

        $args = new WrapFunctionHandlerArgs(
            $ctx,
            $function,
            static fn () => 'handled'
        );

        $this->assertSame($ctx, $args->ctx);
        $this->assertSame('handled', $args->next());
    }
}
