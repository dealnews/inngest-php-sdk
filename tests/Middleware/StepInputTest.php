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
use DealNews\Inngest\Middleware\StepInput;
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
 * Tests for StepInput
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(StepInput::class)]
#[UsesClass(AiTools::class)]
#[UsesClass(Context::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Event::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(MiddlewareStack::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(ValueObject::class)]
class StepInputTest extends TestCase {

    /**
     * Holds the step's options and input
     *
     * @return void
     */
    public function testValues(): void {
        $function = RecordingEngine::create()->getFunction();
        $options  = new StepOptions('A');

        $input = new StepInput(
            $function,
            'hash',
            StepType::Sleep,
            true,
            $options,
            ['1s']
        );

        $this->assertSame($function, $input->function);
        $this->assertSame('hash', $input->hashed_id);
        $this->assertSame(StepType::Sleep, $input->step_type);
        $this->assertTrue($input->memoized);
        $this->assertSame($options, $input->step_options);
        $this->assertSame(['1s'], $input->input);
    }
}
