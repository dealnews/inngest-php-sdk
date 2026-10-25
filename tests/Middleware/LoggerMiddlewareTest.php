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
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Middleware\FunctionInput;
use DealNews\Inngest\Middleware\LoggerMiddleware;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Middleware\ProxyLogger;
use DealNews\Inngest\Middleware\RunArgs;
use DealNews\Inngest\Middleware\StepArgs;
use DealNews\Inngest\Middleware\StepErrorArgs;
use DealNews\Inngest\Middleware\StepInfo;
use DealNews\Inngest\Middleware\StepType;
use DealNews\Inngest\Middleware\WrapFunctionHandlerArgs;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Tests\mock\RecordingEngine;
use DealNews\Inngest\Tests\mock\RecordingLogger;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for LoggerMiddleware
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(LoggerMiddleware::class)]
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
#[UsesClass(ProxyLogger::class)]
#[UsesClass(Retry::class)]
#[UsesClass(RunArgs::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(StepArgs::class)]
#[UsesClass(StepErrorArgs::class)]
#[UsesClass(StepInfo::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(WrapFunctionHandlerArgs::class)]
class LoggerMiddlewareTest extends TestCase {

    /**
     * The handler gets a ProxyLogger that stays quiet until replay ends
     *
     * @return void
     */
    public function testProxyLogger(): void {
        $logger     = new RecordingLogger();
        $middleware = new LoggerMiddleware(
            new Client('app', null, $logger, null, new Env([]))
        );
        $engine     = RecordingEngine::create();
        $input      = new FunctionInput(
            $engine->getContext(),
            $engine->getFunction(),
            []
        );

        $this->assertSame(LoggerMiddleware::ID, $middleware->getId());

        $result = $middleware->transformFunctionInput($input);
        $this->assertNotSame($input, $result);
        $this->assertSame($engine->getContext(), $input->ctx);

        $proxy = $result->ctx->logger;
        $this->assertInstanceOf(ProxyLogger::class, $proxy);

        $proxy->info('replayed');
        $middleware->onMemoizationEnd(
            new RunArgs($result->ctx, $engine->getFunction())
        );
        $proxy->info('fresh');

        $this->assertSame(['fresh'], array_column($logger->records, 'message'));
        $this->assertSame(
            ['runID' => '', 'eventName' => ''],
            $logger->records[0]['context']
        );
    }

    /**
     * Step and function errors are logged (once replay is over)
     *
     * @return void
     */
    public function testErrors(): void {
        $logger     = new RecordingLogger();
        $middleware = new LoggerMiddleware(
            new Client('app', null, $logger, null, new Env([]))
        );
        $engine     = RecordingEngine::create();
        $ctx        = $engine->getContext();
        $function   = $engine->getFunction();
        $error      = new \RuntimeException('boom');

        // Before transformFunctionInput there's no proxy; nothing breaks.
        $middleware->onMemoizationEnd(new RunArgs($ctx, $function));

        $ctx = $middleware->transformFunctionInput(
            new FunctionInput($ctx, $function, [])
        )->ctx;
        $middleware->onMemoizationEnd(new RunArgs($ctx, $function));

        $middleware->onStepError(new StepErrorArgs(
            $ctx,
            $function,
            new StepInfo('h', StepType::Run, new StepOptions('A'), false),
            $error,
            false
        ));

        $this->assertSame('ok', $middleware->wrapFunctionHandler(
            new WrapFunctionHandlerArgs($ctx, $function, static fn () => 'ok')
        ));

        try {
            $middleware->wrapFunctionHandler(new WrapFunctionHandlerArgs(
                $ctx,
                $function,
                static fn () => throw $error
            ));
            $this->fail('Expected the error to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($error, $e);
        }

        $this->assertSame(
            [
                ['error', 'Inngest step error', $error],
                ['error', 'Inngest function error', $error],
            ],
            array_map(
                static fn (array $r) => [
                    $r['level'],
                    $r['message'],
                    $r['context']['exception'],
                ],
                $logger->records
            )
        );
    }
}
