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

        $result = $step->run('test-step', fn() => 'result-value');

        $this->assertSame('result-value', $result);
        $this->assertCount(1, $step->getPlannedSteps());
        
        $planned = $step->getPlannedSteps()[0];
        $this->assertSame('StepPlanned', $planned['op']);
        $this->assertSame('test-step', $planned['displayName']);
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
        $this->assertCount(0, $step->getPlannedSteps());
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

        $result = $step->sleep('wait-step', '5m');

        $this->assertNull($result);
        $this->assertCount(1, $step->getPlannedSteps());
        
        $planned = $step->getPlannedSteps()[0];
        $this->assertSame('Sleep', $planned['op']);
        $this->assertSame('wait-step', $planned['displayName']);
        $this->assertSame('5m', $planned['opts']['duration']);
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

        $step->sleep('wait-step', 300);

        $planned = $step->getPlannedSteps()[0];
        $this->assertSame('300s', $planned['opts']['duration']);
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

        $result = $step->waitForEvent(
            id: 'wait-payment',
            event: 'payment/completed',
            timeout: '1h',
            if: 'event.data.order_id == async.data.order_id'
        );

        $this->assertNull($result);
        $this->assertCount(1, $step->getPlannedSteps());
        
        $planned = $step->getPlannedSteps()[0];
        $this->assertSame('WaitForEvent', $planned['op']);
        $this->assertSame('payment/completed', $planned['opts']['event']);
        $this->assertSame('1h', $planned['opts']['timeout']);
        $this->assertSame('event.data.order_id == async.data.order_id', $planned['opts']['if']);
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

        $result = $step->invoke(
            id: 'call-other',
            function_id: 'myapp-other-func',
            payload: ['data' => ['foo' => 'bar']]
        );

        $this->assertNull($result);
        $this->assertCount(1, $step->getPlannedSteps());
        
        $planned = $step->getPlannedSteps()[0];
        $this->assertSame('InvokeFunction', $planned['op']);
        $this->assertSame('myapp-other-func', $planned['opts']['function_id']);
        $this->assertSame(['data' => ['foo' => 'bar']], $planned['opts']['payload']);
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

        // Run the same step ID multiple times
        $step->run('repeated-step', fn() => 'first');
        $step->run('repeated-step', fn() => 'second');
        $step->run('repeated-step', fn() => 'third');

        $planned = $step->getPlannedSteps();
        $this->assertCount(3, $planned);

        // IDs should be different due to incrementing
        $this->assertNotSame($planned[0]['id'], $planned[1]['id']);
        $this->assertNotSame($planned[1]['id'], $planned[2]['id']);
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

        $step->run('my-step', fn() => 'first');
        $step->run('my-step', fn() => 'second');
        $step->run('my-step', fn() => 'third');

        $planned = $step->getPlannedSteps();
        $this->assertCount(3, $planned);

        // First occurrence: sha1('my-step')
        $this->assertSame(sha1('my-step'), $planned[0]['id']);
        // Second occurrence: sha1('my-step:1')
        $this->assertSame(sha1('my-step:1'), $planned[1]['id']);
        // Third occurrence: sha1('my-step:2')
        $this->assertSame(sha1('my-step:2'), $planned[2]['id']);
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

        // Non-matching steps return null silently
        $result1 = $step->run('other-step', fn() => 'ignored');
        $this->assertNull($result1);
        $this->assertEmpty($step->getPlannedSteps());

        // Matching step throws StepCompletedException
        $this->expectException(\DealNews\Inngest\Error\StepCompletedException::class);
        $step->run('target-step', fn() => 'executed-result');
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

        try {
            $step->run('my-step', fn() => 'result');
        } catch (\DealNews\Inngest\Error\StepCompletedException $e) {
            // expected
        }

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

        $event = new \DealNews\Inngest\Event\Event('test/event', ['key' => 'value']);
        $result = $step->sendEvent('send-notification', $event);

        $this->assertNull($result);
        $this->assertCount(1, $step->getPlannedSteps());

        $planned = $step->getPlannedSteps()[0];
        $this->assertSame('SendEvent', $planned['op']);
        $this->assertSame('send-notification', $planned['displayName']);
        $this->assertArrayHasKey('payload', $planned['opts']);
        $this->assertCount(1, $planned['opts']['payload']);
        $this->assertSame('test/event', $planned['opts']['payload'][0]['name']);
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

        $result = $step->fetch('get-data', 'https://example.com/api', 'GET');

        $this->assertNull($result);
        $this->assertCount(1, $step->getPlannedSteps());

        $planned = $step->getPlannedSteps()[0];
        $this->assertSame('Gateway', $planned['op']);
        $this->assertSame('get-data', $planned['displayName']);
        $this->assertSame('https://example.com/api', $planned['opts']['url']);
        $this->assertSame('GET', $planned['opts']['method']);
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

        $result = $step->ai()->infer(
            'get-completion',
            'https://api.openai.com/v1/chat/completions',
            ['model' => 'gpt-4', 'messages' => [['role' => 'user', 'content' => 'Hello']]]
        );

        $this->assertNull($result);
        $this->assertCount(1, $step->getPlannedSteps());

        $planned = $step->getPlannedSteps()[0];
        $this->assertSame('AiGateway', $planned['op']);
        $this->assertSame('get-completion', $planned['displayName']);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $planned['opts']['url']);
        $this->assertArrayHasKey('body', $planned['opts']);
    }
}
