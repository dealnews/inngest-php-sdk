<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Function\FunctionContext;
use DealNews\Inngest\Middleware\AbstractMiddleware;
use PHPUnit\Framework\TestCase;

class MiddlewareTest extends TestCase
{
    public function testMiddlewareLifecycleOrderingViaConcreteClass(): void
    {
        $log = [];

        $middleware = new class ($log) extends AbstractMiddleware {
            public function __construct(private array &$log)
            {
            }

            public function transformInput(FunctionContext $ctx, array &$steps): void
            {
                $this->log[] = 'transformInput';
            }

            public function afterMemoization(FunctionContext $ctx): void
            {
                $this->log[] = 'afterMemoization';
            }

            public function beforeExecution(FunctionContext $ctx): void
            {
                $this->log[] = 'beforeExecution';
            }

            public function afterExecution(FunctionContext $ctx): void
            {
                $this->log[] = 'afterExecution';
            }

            public function transformOutput(
                FunctionContext $ctx,
                mixed &$result,
                ?\Throwable &$error,
                ?array &$step_data
            ): void {
                $this->log[] = 'transformOutput';
            }

            public function beforeResponse(array &$response): void
            {
                $this->log[] = 'beforeResponse';
            }

            public function beforeSendEvents(array &$events): void
            {
                $this->log[] = 'beforeSendEvents';
            }

            public function afterSendEvents(array $event_ids, ?\Throwable $error = null): void
            {
                $this->log[] = 'afterSendEvents';
            }
        };

        // Verify abstract class can be extended
        $this->assertInstanceOf(AbstractMiddleware::class, $middleware);

        // Call hooks in spec order and verify log
        $event = new \DealNews\Inngest\Event\Event('test', []);
        $step = new \DealNews\Inngest\Step\Step(
            new \DealNews\Inngest\Step\StepContext('run', 0, false, false, [], [])
        );
        $ctx = new FunctionContext($event, [$event], 'run', 0, $step);
        $steps = [];
        $result = null;
        $error = null;
        $step_data = null;
        $response = [];
        $events = [];

        $middleware->transformInput($ctx, $steps);
        $middleware->afterMemoization($ctx);
        $middleware->beforeExecution($ctx);
        $middleware->afterExecution($ctx);
        $middleware->transformOutput($ctx, $result, $error, $step_data);
        $middleware->beforeResponse($response);
        $middleware->beforeSendEvents($events);
        $middleware->afterSendEvents([], null);

        $this->assertSame([
            'transformInput',
            'afterMemoization',
            'beforeExecution',
            'afterExecution',
            'transformOutput',
            'beforeResponse',
            'beforeSendEvents',
            'afterSendEvents',
        ], $log);
    }

    public function testAbstractMiddlewareDefaultHooksAreNoOps(): void
    {
        $middleware = new class extends AbstractMiddleware {};

        $event = new \DealNews\Inngest\Event\Event('test', []);
        $step = new \DealNews\Inngest\Step\Step(
            new \DealNews\Inngest\Step\StepContext('run', 0, false, false, [], [])
        );
        $ctx = new FunctionContext($event, [$event], 'run', 0, $step);
        $steps = [];
        $result = null;
        $error = null;
        $step_data = null;
        $response = [];
        $events = [];

        // None of these should throw
        $middleware->transformInput($ctx, $steps);
        $middleware->afterMemoization($ctx);
        $middleware->beforeExecution($ctx);
        $middleware->afterExecution($ctx);
        $middleware->transformOutput($ctx, $result, $error, $step_data);
        $middleware->beforeResponse($response);
        $middleware->beforeSendEvents($events);
        $middleware->afterSendEvents([], null);

        $this->assertTrue(true); // Reached here without exception
    }

    public function testMiddlewareCanMutateTransformInputSteps(): void
    {
        $middleware = new class extends AbstractMiddleware {
            public function transformInput(FunctionContext $ctx, array &$steps): void
            {
                $steps['injected'] = ['data' => 'from-middleware'];
            }
        };

        $event = new \DealNews\Inngest\Event\Event('test', []);
        $step = new \DealNews\Inngest\Step\Step(
            new \DealNews\Inngest\Step\StepContext('run', 0, false, false, [], [])
        );
        $ctx = new FunctionContext($event, [$event], 'run', 0, $step);
        $steps = [];

        $middleware->transformInput($ctx, $steps);

        $this->assertArrayHasKey('injected', $steps);
        $this->assertSame(['data' => 'from-middleware'], $steps['injected']);
    }

    public function testAfterMemoizationCalledOnlyWhenUnmemoizedStepEncountered(): void
    {
        $called = false;
        $middleware = new class ($called) extends AbstractMiddleware {
            public function __construct(private bool &$called)
            {
            }

            public function afterMemoization(FunctionContext $ctx): void
            {
                $this->called = true;
            }
        };

        $event = new \DealNews\Inngest\Event\Event('test', []);
        $step = new \DealNews\Inngest\Step\Step(
            new \DealNews\Inngest\Step\StepContext('run', 0, false, false, [], [])
        );
        $ctx = new FunctionContext($event, [$event], 'run', 0, $step);

        // Set callback - simulating what ServeHandler does
        $step->setAfterMemoizationCallback(function () use ($middleware, $ctx) {
            $middleware->afterMemoization($ctx);
        });

        // afterMemoization should NOT be called yet (no step encountered)
        $this->assertFalse($called);

        // Encounter first unmemoized step
        $step->run('my-step', fn() => 'result');

        // Now it should have been called
        $this->assertTrue($called);
    }
}
