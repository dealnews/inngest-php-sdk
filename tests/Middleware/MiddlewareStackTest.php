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
use DealNews\Inngest\Middleware\SendEventInput;
use DealNews\Inngest\Middleware\StepArgs;
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
use DealNews\Inngest\Tests\mock\RecordingLogger;
use DealNews\Inngest\Tests\mock\RegisterMiddleware;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for MiddlewareStack
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(MiddlewareStack::class)]
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
#[UsesClass(Middleware::class)]
#[UsesClass(Request::class)]
#[UsesClass(Response::class)]
#[UsesClass(Retry::class)]
#[UsesClass(RunArgs::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(SendEventInput::class)]
#[UsesClass(SendEventResult::class)]
#[UsesClass(StepArgs::class)]
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
class MiddlewareStackTest extends TestCase {

    /**
     * Client for middleware instances
     *
     * @var Client
     */
    protected Client $client;

    /**
     * Shared log written by the test middleware
     *
     * @var \ArrayObject<int, string>
     */
    protected \ArrayObject $log;

    /**
     * Sets up a client and an empty log
     *
     * @return void
     */
    protected function setUp(): void {
        $this->client               = new Client(
            'app',
            null,
            new RecordingLogger(),
            null,
            new Env([])
        );
        $this->log                      = new \ArrayObject();
        RegisterMiddleware::$registered = [];
    }

    /**
     * Accepts Middleware class names and Closures only
     *
     * @return void
     */
    public function testValidate(): void {
        $factory = static fn (Client $c) => new RegisterMiddleware($c);

        $this->assertSame(
            [RegisterMiddleware::class, $factory],
            MiddlewareStack::validate(
                [RegisterMiddleware::class, $factory],
                123
            )
        );

        foreach ([[\stdClass::class], [42], ['nope']] as $bad) {
            try {
                MiddlewareStack::validate($bad, 123);
                $this->fail('Expected invalid middleware to be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(123, $e->getCode());
            }
        }
    }

    /**
     * Creates fresh instances from class names and factories, in order
     *
     * @return void
     */
    public function testCreate(): void {
        $made  = new RegisterMiddleware($this->client);
        $stack = MiddlewareStack::create($this->client, [
            RegisterMiddleware::class,
            static fn () => $made,
        ]);

        $middleware = $stack->getMiddleware();
        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(RegisterMiddleware::class, $middleware[0]);
        $this->assertNotSame($made, $middleware[0]);
        $this->assertSame($made, $middleware[1]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionCode(10003);
        MiddlewareStack::create($this->client, [static fn () => 'nope']);
    }

    /**
     * onRegister() runs once per entry; factories are called to find
     * the class
     *
     * @return void
     */
    public function testRegister(): void {
        $function = RecordingEngine::create()->getFunction();

        MiddlewareStack::register(
            [
                RegisterMiddleware::class,
                static fn (Client $c) => new RegisterMiddleware($c),
            ],
            $this->client,
            $function
        );

        $this->assertSame(
            [[$this->client, $function], [$this->client, $function]],
            RegisterMiddleware::$registered
        );
    }

    /**
     * Only overridden hooks count
     *
     * @return void
     */
    public function testHas(): void {
        $stack = new MiddlewareStack(
            [new RegisterMiddleware($this->client)],
            new RecordingLogger()
        );

        $this->assertTrue($stack->has('onRunStart'));
        $this->assertFalse($stack->has('wrapStep'));
        $this->assertFalse($stack->has('notAHook'));
    }

    /**
     * Transform hooks run in order, each getting the last one's result
     *
     * @return void
     */
    public function testTransforms(): void {
        $engine   = RecordingEngine::create();
        $ctx      = $engine->getContext();
        $function = $engine->getFunction();
        $stack    = $this->stack('a', 'b');

        $this->assertSame(
            ['a', 'b'],
            $stack->transformFunctionInput(
                new FunctionInput($ctx, $function, [])
            )->ctx->extras['seen']
        );
        $this->assertSame(
            ['a', 'b'],
            $stack->transformStepInput(new StepInput(
                $function,
                'h',
                StepType::Run,
                false,
                new StepOptions('A'),
                []
            ))->input
        );
        $this->assertSame(
            ['x', 'a', 'b'],
            array_map(
                static fn (Event $e) => $e->name,
                $stack->transformSendEvent(
                    new SendEventInput([new Event('x')])
                )->events
            )
        );
    }

    /**
     * Errors from `on*` hooks are logged, and later middleware still run
     *
     * @return void
     */
    public function testNotifyLogsErrors(): void {
        $engine = RecordingEngine::create();
        $logger = new RecordingLogger();
        $stack  = new MiddlewareStack(
            [
                new RegisterMiddleware($this->client),
                $this->middleware('b'),
            ],
            $logger
        );

        $stack->onRunStart(
            new RunArgs($engine->getContext(), $engine->getFunction())
        );

        $this->assertSame(['b:onRunStart'], $this->log->getArrayCopy());
        $this->assertSame('middleware error', $logger->records[0]['message']);
        $this->assertSame(
            'onRunStart',
            $logger->records[0]['context']['hook']
        );
        $this->assertSame(
            RegisterMiddleware::class,
            $logger->records[0]['context']['mw']
        );
        $this->assertSame(
            'hook broke',
            $logger->records[0]['context']['exception']->getMessage()
        );
    }

    /**
     * onMemoizationEnd() only runs once per request
     *
     * @return void
     */
    public function testMemoizationEndOnce(): void {
        $engine = RecordingEngine::create();
        $stack  = $this->stack('a');
        $args   = new RunArgs($engine->getContext(), $engine->getFunction());

        $stack->onMemoizationEnd($args);
        $stack->onMemoizationEnd($args);

        $this->assertSame(['a:onMemoizationEnd'], $this->log->getArrayCopy());
    }

    /**
     * Wrap hooks nest: the first middleware is the outermost
     *
     * @return void
     */
    public function testWrapOrder(): void {
        $engine   = RecordingEngine::create();
        $ctx      = $engine->getContext();
        $function = $engine->getFunction();
        $stack    = $this->stack('a', 'b');
        $info     = $this->info();

        $chain = $stack->wrapFunctionHandler(
            $ctx,
            $function,
            fn () => $this->inner('handler')
        );
        $this->assertSame('handler', $chain());
        $this->assertSame('step', $stack->wrapStep(
            $ctx,
            $function,
            $info,
            fn () => $this->inner('step')
        ));
        $this->assertSame('code', $stack->wrapStepHandler(
            $ctx,
            $function,
            $info,
            fn () => $this->inner('code')
        ));

        $sent = new SendEventResult([]);
        $this->assertSame($sent, $stack->wrapSendEvent(
            [],
            null,
            function () use ($sent) {
                $this->inner('send');

                return $sent;
            }
        ));

        $response = new Response(200);
        $this->assertSame($response, $stack->wrapRequest(
            new Request('POST', 'https://example.com/'),
            null,
            '',
            function () use ($response) {
                $this->inner('request');

                return $response;
            }
        ));

        $expected = [];
        foreach (['handler', 'step', 'code', 'send', 'request'] as $inner) {
            array_push(
                $expected,
                'a:before',
                'b:before',
                $inner,
                'b:after',
                'a:after'
            );
        }
        $this->assertSame($expected, $this->log->getArrayCopy());
    }

    /**
     * A middleware's own steps (made before next()) skip its wrapStep;
     * steps made inside next() don't
     *
     * @return void
     */
    public function testWrapStepGuard(): void {
        $engine   = RecordingEngine::create();
        $ctx      = $engine->getContext();
        $function = $engine->getFunction();
        $log      = $this->log;

        $prepend = new class($this->client, $log) extends Middleware {
            /**
             * Runs a nested step before and inside next()
             *
             * @var \Closure|null
             */
            public ?\Closure $nested = null;

            /**
             * Creates the middleware
             *
             * @param Client       $client Client
             * @param \ArrayObject $log    Log
             */
            public function __construct(
                Client $client,
                protected \ArrayObject $log
            ) {
                parent::__construct($client);
            }

            /**
             * Logs, then runs a nested step around next()
             *
             * @param WrapStepArgs $args Arguments
             *
             * @return mixed
             */
            public function wrapStep(WrapStepArgs $args): mixed {
                $id          = $args->step_info->options->id;
                $this->log[] = "p:$id";
                if ($id === 'outer') {
                    ($this->nested)('before');
                }

                return $args->next();
            }
        };

        $stack           = new MiddlewareStack(
            [$prepend, $this->middleware('b')],
            new RecordingLogger()
        );
        $prepend->nested = function (string $id) use (
            $stack,
            $ctx,
            $function
        ): void {
            $stack->wrapStep(
                $ctx,
                $function,
                $this->info($id),
                static fn () => null
            );
        };

        $stack->wrapStep(
            $ctx,
            $function,
            $this->info('outer'),
            function () use ($prepend) {
                ($prepend->nested)('inside');

                return null;
            }
        );

        $this->assertSame(
            [
                'p:outer',
                // "before" was made by p's own wrapStep: p is skipped.
                'b:before',
                'b:after',
                'b:before',
                // "inside" was made inside next(): p sees it.
                'p:inside',
                'b:before',
                'b:after',
                'b:after',
            ],
            $log->getArrayCopy()
        );
    }

    /**
     * Fibers made inside a wrapStep get its guard; other fibers don't
     *
     * @return void
     */
    public function testInheritGuard(): void {
        $engine   = RecordingEngine::create();
        $ctx      = $engine->getContext();
        $function = $engine->getFunction();
        $log      = $this->log;
        $holder   = new \ArrayObject();
        $info     = $this->info('outer');

        $branch = function (\Fiber $parent, bool $inherit) use (
            $holder,
            $ctx,
            $function
        ): void {
            $stack = $holder['stack'];
            $child = new \Fiber(fn () => $stack->wrapStep(
                $ctx,
                $function,
                $this->info('child'),
                static fn () => null
            ));
            if ($inherit) {
                $stack->inheritGuard($parent, $child);
            }
            $child->start();
        };

        $guarded = new class($this->client, $log, $branch) extends Middleware {
            /**
             * Creates the middleware
             *
             * @param Client       $client Client
             * @param \ArrayObject $log    Log
             * @param \Closure     $branch Starts a child fiber
             */
            public function __construct(
                Client $client,
                protected \ArrayObject $log,
                protected \Closure $branch
            ) {
                parent::__construct($client);
            }

            /**
             * Logs, and starts child fibers from the outer step
             *
             * @param WrapStepArgs $args Arguments
             *
             * @return mixed
             */
            public function wrapStep(WrapStepArgs $args): mixed {
                $id          = $args->step_info->options->id;
                $this->log[] = "g:$id";
                if ($id === 'outer') {
                    $parent = \Fiber::getCurrent();
                    ($this->branch)($parent, true);
                    ($this->branch)($parent, false);
                }

                return $args->next();
            }
        };

        $stack           = new MiddlewareStack(
            [$guarded],
            new RecordingLogger()
        );
        $holder['stack'] = $stack;

        $fiber = new \Fiber(static fn () => $stack->wrapStep(
            $ctx,
            $function,
            $info,
            static fn () => null
        ));
        $fiber->start();

        // Nothing is active outside a fiber, so nothing is inherited.
        $outside = new \Fiber(static fn () => null);
        $stack->inheritGuard(null, $outside);

        $this->assertSame(['g:outer', 'g:child'], $log->getArrayCopy());
    }

    /**
     * Builds a stack of logging middleware
     *
     * @param string ...$tags Tags, in order
     *
     * @return MiddlewareStack
     */
    protected function stack(string ...$tags): MiddlewareStack {
        return new MiddlewareStack(
            array_map(fn (string $tag) => $this->middleware($tag), $tags),
            new RecordingLogger()
        );
    }

    /**
     * Records an inner handler call
     *
     * @param string $name Name to log and return
     *
     * @return string
     */
    protected function inner(string $name): string {
        $this->log[] = $name;

        return $name;
    }

    /**
     * Builds step info
     *
     * @param string $id Step ID
     *
     * @return StepInfo
     */
    protected function info(string $id = 'A'): StepInfo {
        return new StepInfo(
            sha1($id),
            StepType::Run,
            new StepOptions($id),
            false
        );
    }

    /**
     * Builds a middleware that logs every hook it overrides
     *
     * @param string $tag Tag
     *
     * @return Middleware
     */
    protected function middleware(string $tag): Middleware {
        return new class($this->client, $this->log, $tag) extends Middleware {
            /**
             * Creates the middleware
             *
             * @param Client       $client Client
             * @param \ArrayObject $log    Log
             * @param string       $tag    Tag
             */
            public function __construct(
                Client $client,
                protected \ArrayObject $log,
                protected string $tag
            ) {
                parent::__construct($client);
            }

            /**
             * Adds the tag to `extras['seen']`
             *
             * @param FunctionInput $input Input
             *
             * @return FunctionInput
             */
            public function transformFunctionInput(
                FunctionInput $input
            ): FunctionInput {
                $result      = clone $input;
                $seen        = $input->ctx->extras['seen'] ?? [];
                $seen[]      = $this->tag;
                $result->ctx = $input->ctx->with(extras: ['seen' => $seen]);

                return $result;
            }

            /**
             * Adds the tag to the input
             *
             * @param StepInput $input Input
             *
             * @return StepInput
             */
            public function transformStepInput(StepInput $input): StepInput {
                $result          = clone $input;
                $result->input[] = $this->tag;

                return $result;
            }

            /**
             * Adds an event named after the tag
             *
             * @param SendEventInput $input Input
             *
             * @return SendEventInput
             */
            public function transformSendEvent(
                SendEventInput $input
            ): SendEventInput {
                $result           = clone $input;
                $result->events[] = new Event($this->tag);

                return $result;
            }

            /**
             * Logs the call
             *
             * @param RunArgs $args Arguments
             *
             * @return void
             */
            public function onMemoizationEnd(RunArgs $args): void {
                $this->log[] = "$this->tag:onMemoizationEnd";
            }

            /**
             * Logs the call
             *
             * @param RunArgs $args Arguments
             *
             * @return void
             */
            public function onRunStart(RunArgs $args): void {
                $this->log[] = "$this->tag:onRunStart";
            }

            /**
             * Logs around next()
             *
             * @param WrapFunctionHandlerArgs $args Arguments
             *
             * @return mixed
             */
            public function wrapFunctionHandler(
                WrapFunctionHandlerArgs $args
            ): mixed {
                return $this->around(static fn () => $args->next());
            }

            /**
             * Logs around next()
             *
             * @param WrapStepArgs $args Arguments
             *
             * @return mixed
             */
            public function wrapStep(WrapStepArgs $args): mixed {
                return $this->around(static fn () => $args->next());
            }

            /**
             * Logs around next()
             *
             * @param WrapStepArgs $args Arguments
             *
             * @return mixed
             */
            public function wrapStepHandler(WrapStepArgs $args): mixed {
                return $this->around(static fn () => $args->next());
            }

            /**
             * Logs around next()
             *
             * @param WrapSendEventArgs $args Arguments
             *
             * @return SendEventResult
             */
            public function wrapSendEvent(
                WrapSendEventArgs $args
            ): SendEventResult {
                return $this->around(static fn () => $args->next());
            }

            /**
             * Logs around next()
             *
             * @param WrapRequestArgs $args Arguments
             *
             * @return Response
             */
            public function wrapRequest(WrapRequestArgs $args): Response {
                return $this->around(static fn () => $args->next());
            }

            /**
             * Logs before and after a call
             *
             * @param \Closure $next Call
             *
             * @return mixed
             */
            protected function around(\Closure $next): mixed {
                $this->log[] = "$this->tag:before";
                $result      = $next();
                $this->log[] = "$this->tag:after";

                return $result;
            }
        };
    }
}
