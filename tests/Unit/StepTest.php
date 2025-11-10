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
}
