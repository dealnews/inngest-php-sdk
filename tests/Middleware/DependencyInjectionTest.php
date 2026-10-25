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
use DealNews\Inngest\Middleware\DependencyInjection;
use DealNews\Inngest\Middleware\FunctionInput;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Tests\mock\RecordingEngine;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for DependencyInjection
 *
 * Port of middleware/dependencyInjection.test.ts (runtime cases).
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(DependencyInjection::class)]
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
#[UsesClass(Retry::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(ValueObject::class)]
class DependencyInjectionTest extends TestCase {

    /**
     * Values are added to the context's extras
     *
     * @return void
     */
    public function testInjects(): void {
        $client     = new Client('app', null, null, null, new Env([]));
        $middleware = new DependencyInjection($client, ['db' => 'conn']);
        $engine     = RecordingEngine::create();
        $ctx        = $engine->getContext()->with(extras: ['kept' => 1]);
        $input      = new FunctionInput($ctx, $engine->getFunction(), []);

        $result = $middleware->transformFunctionInput($input);

        $this->assertSame(DependencyInjection::ID, $middleware->getId());
        $this->assertSame(['kept' => 1], $input->ctx->extras);
        $this->assertSame(
            ['kept' => 1, 'db' => 'conn'],
            $result->ctx->extras
        );
    }
}
