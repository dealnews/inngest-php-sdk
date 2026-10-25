<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Middleware;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Context;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Http\Response;
use DealNews\Inngest\Middleware\FunctionInput;
use DealNews\Inngest\Middleware\Middleware;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Middleware\RunArgs;
use DealNews\Inngest\Middleware\RunCompleteArgs;
use DealNews\Inngest\Middleware\RunErrorArgs;
use DealNews\Inngest\Middleware\SendEventInput;
use DealNews\Inngest\Middleware\StepArgs;
use DealNews\Inngest\Middleware\StepCompleteArgs;
use DealNews\Inngest\Middleware\StepErrorArgs;
use DealNews\Inngest\Middleware\StepInfo;
use DealNews\Inngest\Middleware\StepInput;
use DealNews\Inngest\Middleware\StepType;
use DealNews\Inngest\Middleware\WrapFunctionHandlerArgs;
use DealNews\Inngest\Middleware\WrapRequestArgs;
use DealNews\Inngest\Middleware\WrapSendEventArgs;
use DealNews\Inngest\Middleware\WrapStepArgs;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Tests\mock\RecordingEngine;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Middleware
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(Middleware::class)]
#[UsesClass(AiTools::class)]
#[UsesClass(ApiClient::class)]
#[UsesClass(Client::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(Context::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Env::class)]
#[UsesClass(Event::class)]
#[UsesClass(FunctionInput::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(Headers::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(MiddlewareStack::class)]
#[UsesClass(Request::class)]
#[UsesClass(Response::class)]
#[UsesClass(Retry::class)]
#[UsesClass(RunArgs::class)]
#[UsesClass(RunCompleteArgs::class)]
#[UsesClass(RunErrorArgs::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(SendEventInput::class)]
#[UsesClass(SendEventResult::class)]
#[UsesClass(StepArgs::class)]
#[UsesClass(StepCompleteArgs::class)]
#[UsesClass(StepErrorArgs::class)]
#[UsesClass(StepInfo::class)]
#[UsesClass(StepInput::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(WrapFunctionHandlerArgs::class)]
#[UsesClass(WrapRequestArgs::class)]
#[UsesClass(WrapSendEventArgs::class)]
#[UsesClass(WrapStepArgs::class)]
class MiddlewareTest extends TestCase {

    /**
     * Hooks a subclass doesn't override change nothing
     *
     * @return void
     */
    public function testDefaults(): void {
        $client     = new Client('app', null, null, null, new Env([]));
        $middleware = new class($client) extends Middleware {
        };
        $engine     = RecordingEngine::create();
        $ctx        = $engine->getContext();
        $function   = $engine->getFunction();
        $info       = new StepInfo(
            'h',
            StepType::Run,
            new StepOptions('A'),
            false
        );
        $error      = new \RuntimeException('boom');

        $this->assertSame($middleware::class, $middleware->getId());

        $middleware::onRegister($client, null);

        $function_input = new FunctionInput($ctx, $function, []);
        $this->assertSame(
            $function_input,
            $middleware->transformFunctionInput($function_input)
        );

        $step_input = new StepInput(
            $function,
            'h',
            StepType::Run,
            false,
            new StepOptions('A'),
            []
        );
        $this->assertSame(
            $step_input,
            $middleware->transformStepInput($step_input)
        );

        $send_input = new SendEventInput([new Event('a')]);
        $this->assertSame(
            $send_input,
            $middleware->transformSendEvent($send_input)
        );

        $middleware->onMemoizationEnd(new RunArgs($ctx, $function));
        $middleware->onRunStart(new RunArgs($ctx, $function));
        $middleware->onRunComplete(
            new RunCompleteArgs($ctx, $function, null)
        );
        $middleware->onRunError(
            new RunErrorArgs($ctx, $function, $error, true)
        );
        $middleware->onStepStart(new StepArgs($ctx, $function, $info));
        $middleware->onStepComplete(
            new StepCompleteArgs($ctx, $function, $info, 1)
        );
        $middleware->onStepError(
            new StepErrorArgs($ctx, $function, $info, $error, false)
        );

        $this->assertSame('run', $middleware->wrapFunctionHandler(
            new WrapFunctionHandlerArgs($ctx, $function, static fn () => 'run')
        ));
        $this->assertSame('step', $middleware->wrapStep(
            new WrapStepArgs($ctx, $function, $info, static fn () => 'step')
        ));
        $this->assertSame('code', $middleware->wrapStepHandler(
            new WrapStepArgs($ctx, $function, $info, static fn () => 'code')
        ));

        $sent = new SendEventResult(['id']);
        $this->assertSame($sent, $middleware->wrapSendEvent(
            new WrapSendEventArgs([], null, static fn () => $sent)
        ));

        $response = new Response(200);
        $this->assertSame($response, $middleware->wrapRequest(
            new WrapRequestArgs(
                new Request('POST', 'https://example.com/'),
                null,
                '',
                static fn () => $response
            )
        ));
    }
}
