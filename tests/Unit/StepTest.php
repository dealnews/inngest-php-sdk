<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Step\Step;
use DealNews\Inngest\Step\StepContext;
use PHPUnit\Framework\TestCase;

class StepTest extends TestCase
{
    public function testRunStepWithoutMemoization(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);

        $fiber = new \Fiber(fn() => $step->run('test-step', fn() => 'result-value'));
        $executed = $fiber->start();

        $this->assertTrue($fiber->isSuspended());
        $this->assertSame('StepRun', $executed['op']);
        $this->assertSame('test-step', $executed['displayName']);
        $this->assertSame('result-value', $executed['data']);
        $this->assertSame(sha1('test-step'), $executed['id']);
    }

    public function testRunStepWithMemoizedData(): void
    {
        $hashed_id = sha1('memoized-step');

        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: [
                $hashed_id => ['data' => 'memoized-result']
            ]
        );

        $step = new Step($context);

        $result = $step->run('memoized-step', fn() => 'new-result');

        $this->assertSame('memoized-result', $result);
    }

    public function testRunStepWithMemoizedError(): void
    {
        $hashed_id = sha1('failed-step');

        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: [
                $hashed_id => [
                    'error' => [
                        'name' => 'TestError',
                        'message' => 'Step failed',
                        'stack' => 'trace...'
                    ]
                ]
            ]
        );

        $step = new Step($context);

        $this->expectException(StepError::class);
        $this->expectExceptionMessage('Step failed');

        $step->run('failed-step', fn() => 'result');
    }

    public function testSleepStep(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);

        $fiber = new \Fiber(fn() => $step->sleep('wait-step', '5m'));
        $executed = $fiber->start();

        $this->assertTrue($fiber->isSuspended());
        $this->assertSame('Sleep', $executed['op']);
        $this->assertSame('wait-step', $executed['displayName']);
        $this->assertSame('5m', $executed['opts']['duration']);
    }

    public function testSleepWithSeconds(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);

        $fiber = new \Fiber(fn() => $step->sleep('wait-step', 300));
        $executed = $fiber->start();

        $this->assertTrue($fiber->isSuspended());
        $this->assertSame('300s', $executed['opts']['duration']);
    }

    public function testWaitForEvent(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);

        $fiber = new \Fiber(fn() => $step->waitForEvent(
            id: 'wait-payment',
            event: 'payment/completed',
            timeout: '1h',
            if: 'event.data.order_id == async.data.order_id'
        ));
        $executed = $fiber->start();

        $this->assertTrue($fiber->isSuspended());
        $this->assertSame('WaitForEvent', $executed['op']);
        $this->assertSame('payment/completed', $executed['opts']['event']);
        $this->assertSame('1h', $executed['opts']['timeout']);
        $this->assertSame('event.data.order_id == async.data.order_id', $executed['opts']['if']);
    }

    public function testInvokeFunction(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);

        $fiber = new \Fiber(fn() => $step->invoke(
            id: 'call-other',
            function_id: 'myapp-other-func',
            payload: ['data' => ['foo' => 'bar']]
        ));
        $executed = $fiber->start();

        $this->assertTrue($fiber->isSuspended());
        $this->assertSame('InvokeFunction', $executed['op']);
        $this->assertSame('myapp-other-func', $executed['opts']['function_id']);
        $this->assertSame(['data' => ['foo' => 'bar']], $executed['opts']['payload']);
    }

    public function testStepIdHashing(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);

        $fiber = new \Fiber(function () use ($step): void {
            $step->run('repeated-step', fn() => 'value');
            $step->run('repeated-step', fn() => 'value');
            $step->run('repeated-step', fn() => 'value');
        });

        $ids = [
            $fiber->start()['id'],
            $fiber->resume()['id'],
            $fiber->resume()['id'],
        ];

        $this->assertCount(3, $ids);
        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertNotSame($ids[1], $ids[2]);
    }

    public function testStepIdHashingUsesColonOneForFirstRepeat(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);

        $fiber = new \Fiber(function () use ($step): void {
            $step->run('my-step', fn() => 'value');
            $step->run('my-step', fn() => 'value');
            $step->run('my-step', fn() => 'value');
        });

        $ids = [
            $fiber->start()['id'],
            $fiber->resume()['id'],
            $fiber->resume()['id'],
        ];

        $this->assertSame(sha1('my-step'), $ids[0]);
        $this->assertSame(sha1('my-step:1'), $ids[1]);
        $this->assertSame(sha1('my-step:2'), $ids[2]);
    }

    public function testTargetStepExecutesOnlyMatchingStep(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);
        $target_id = sha1('target-step');
        $step->setTargetStepId($target_id);

        $other_result = 'sentinel';
        $fiber = new \Fiber(function () use ($step, &$other_result): void {
            $other_result = $step->run('other-step', fn() => 'ignored');
            $step->run('target-step', fn() => 'executed-result');
        });

        $executed = $fiber->start();

        // Non-matching step returned null without suspending
        $this->assertNull($other_result);

        // Matching step suspended with its result
        $this->assertTrue($fiber->isSuspended());
        $this->assertSame('StepRun', $executed['op']);
        $this->assertSame('executed-result', $executed['data']);
    }

    public function testWasTargetStepFoundReturnsTrueAfterExecution(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);
        $target_id = sha1('my-step');
        $step->setTargetStepId($target_id);

        $this->assertFalse($step->wasTargetStepFound());

        $fiber = new \Fiber(fn() => $step->run('my-step', fn() => 'result'));
        $fiber->start();

        $this->assertTrue($step->wasTargetStepFound());
        $executed = $step->getExecutedStep();
        $this->assertNotNull($executed);
        $this->assertSame('StepRun', $executed['op']);
        $this->assertSame('result', $executed['data']);
    }

    public function testSendEvent(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);

        $received_events = null;
        $step->setSendCallback(function (array $events) use (&$received_events) {
            $received_events = $events;
            return ['ids' => ['event-id-1']];
        });

        $event = new \DealNews\Inngest\Event\Event('test/event', ['key' => 'value']);

        $fiber = new \Fiber(fn() => $step->sendEvent('send-notification', $event));
        $executed = $fiber->start();

        $this->assertTrue($fiber->isSuspended());
        $this->assertSame('StepRun', $executed['op']);
        $this->assertSame('send-notification', $executed['displayName']);
        $this->assertArrayHasKey('data', $executed);
        $this->assertSame(['ids' => ['event-id-1']], $executed['data']);
        $this->assertCount(1, $received_events);
        $this->assertSame('test/event', $received_events[0]->getName());
    }

    public function testFetch(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);

        $fiber = new \Fiber(fn() => $step->fetch('get-data', 'https://example.com/api', 'GET'));
        $executed = $fiber->start();

        $this->assertTrue($fiber->isSuspended());
        $this->assertSame('Gateway', $executed['op']);
        $this->assertSame('get-data', $executed['displayName']);
        $this->assertSame('https://example.com/api', $executed['opts']['url']);
        $this->assertSame('GET', $executed['opts']['method']);
    }

    public function testAiInfer(): void
    {
        $context = new StepContext(
            run_id: 'test-run',
            attempt: 0,
            disable_immediate_execution: false,
            use_api: false,
            stack: [],
            steps: []
        );

        $step = new Step($context);

        $fiber = new \Fiber(fn() => $step->ai()->infer(
            'get-completion',
            'https://api.openai.com/v1/chat/completions',
            ['model' => 'gpt-4', 'messages' => [['role' => 'user', 'content' => 'Hello']]]
        ));
        $executed = $fiber->start();

        $this->assertTrue($fiber->isSuspended());
        $this->assertSame('AiGateway', $executed['op']);
        $this->assertSame('get-completion', $executed['displayName']);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $executed['opts']['url']);
        $this->assertArrayHasKey('body', $executed['opts']);
    }
}
