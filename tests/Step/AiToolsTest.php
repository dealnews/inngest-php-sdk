<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Step;

use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Step\AiFormat;
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
 * Tests for AiTools (ports the `ai` cases of InngestStepTools.test.ts)
 *
 * @package DealNews\Inngest\Tests\Step
 */
#[CoversClass(AiTools::class)]
#[UsesClass(Context::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Engine::class)]
#[UsesClass(ErrorSerializer::class)]
#[UsesClass(Event::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(MiddlewareStack::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(StepOp::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(ValueObject::class)]
class AiToolsTest extends TestCase {

    /**
     * `infer` builds an AIGateway op with the request in its opts
     *
     * @return void
     */
    public function testInfer(): void {
        $engine         = RecordingEngine::create();
        $engine->result = ['choices' => []];
        $tools          = new AiTools($engine);

        $result = $tools->infer(
            new StepOptions('id', 'name'),
            url: 'https://ai.example.com/v1/chat',
            format: AiFormat::OpenAiChat,
            body: ['model' => 'm', 'messages' => []],
            auth_key: 'sk-1',
            headers: ['x-a' => '1']
        );

        $op = $engine->lastOp();
        $this->assertSame(['choices' => []], $result);
        $this->assertSame('id', $op->id);
        $this->assertSame(OpCode::AiGateway, $op->op);
        $this->assertNull($op->name);
        $this->assertSame('name', $op->display_name);
        $this->assertSame(
            [
                'type'     => 'step.ai.infer',
                'url'      => 'https://ai.example.com/v1/chat',
                'headers'  => ['x-a' => '1'],
                'auth_key' => 'sk-1',
                'format'   => 'openai-chat',
                'body'     => ['model' => 'm', 'messages' => []],
            ],
            $op->opts
        );
        $this->assertNull($engine->calls[0]['fn']);
    }

    /**
     * Optional pieces are left out; empty objects stay objects
     *
     * @return void
     */
    public function testInferMinimal(): void {
        $engine = RecordingEngine::create();
        $tools  = new AiTools($engine);

        $tools->infer('id', 'https://x.test/', 'custom-format', []);
        $this->assertSame(
            '{"type":"step.ai.infer","url":"https://x.test/",' .
            '"format":"custom-format","body":{}}',
            json_encode($engine->lastOp()->opts, JSON_UNESCAPED_SLASHES)
        );
        $this->assertSame('id', $engine->lastOp()->display_name);

        $tools->infer('id', 'https://x.test/', 'f', ['a' => 1], null, []);
        $this->assertSame(
            '{"type":"step.ai.infer","url":"https://x.test/","headers":{},' .
            '"format":"f","body":{"a":1}}',
            json_encode($engine->lastOp()->opts, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * `wrap` is a step.run with `type: step.ai.wrap`
     *
     * @return void
     */
    public function testWrap(): void {
        $engine = RecordingEngine::create();
        $tools  = new AiTools($engine);

        $this->assertSame(
            'HI',
            $tools->wrap('id', static fn (string $s) => strtoupper($s), 'hi')
        );
        $op = $engine->lastOp();
        $this->assertSame(OpCode::StepPlanned, $op->op);
        $this->assertSame('id', $op->name);
        $this->assertSame(
            ['input' => ['hi'], 'type' => 'step.ai.wrap'],
            $op->opts
        );
        $this->assertSame(['hi'], $engine->calls[0]['args']);

        $tools->wrap(new StepOptions('id', 'name'), static fn () => null);
        $this->assertSame(['type' => 'step.ai.wrap'], $engine->lastOp()->opts);
        $this->assertSame('name', $engine->lastOp()->display_name);
    }
}
