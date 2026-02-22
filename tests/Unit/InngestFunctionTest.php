<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Function\Concurrency;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\Priority;
use DealNews\Inngest\Function\TriggerEvent;
use PHPUnit\Framework\TestCase;

class InngestFunctionTest extends TestCase
{
    public function testFunctionWithoutConcurrency(): void
    {
        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')]
        );

        $this->assertNull($function->getConcurrency());

        $array = $function->toArray();
        $this->assertArrayNotHasKey('concurrency', $array);
    }

    public function testFunctionWithSingleConcurrency(): void
    {
        $concurrency = new Concurrency(limit: 10);

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            concurrency: [$concurrency]
        );

        $this->assertNotNull($function->getConcurrency());
        $this->assertCount(1, $function->getConcurrency());
        $this->assertSame($concurrency, $function->getConcurrency()[0]);

        $array = $function->toArray();
        $this->assertArrayHasKey('concurrency', $array);
        $this->assertCount(1, $array['concurrency']);
        $this->assertSame(['limit' => 10], $array['concurrency'][0]);
    }

    public function testFunctionWithMultipleConcurrency(): void
    {
        $concurrency1 = new Concurrency(
            limit: 10,
            key: 'event.data.user_id'
        );
        $concurrency2 = new Concurrency(
            limit: 100,
            scope: 'account'
        );

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            concurrency: [$concurrency1, $concurrency2]
        );

        $this->assertCount(2, $function->getConcurrency());

        $array = $function->toArray();
        $this->assertArrayHasKey('concurrency', $array);
        $this->assertCount(2, $array['concurrency']);
        $this->assertSame([
            'limit' => 10,
            'key' => 'event.data.user_id',
        ], $array['concurrency'][0]);
        $this->assertSame([
            'limit' => 100,
            'scope' => 'account',
        ], $array['concurrency'][1]);
    }

    public function testFunctionWithEmptyConcurrencyArray(): void
    {
        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            concurrency: []
        );

        $this->assertNotNull($function->getConcurrency());
        $this->assertCount(0, $function->getConcurrency());

        $array = $function->toArray();
        $this->assertArrayNotHasKey('concurrency', $array);
    }

    public function testFunctionWithMoreThanTwoConcurrencyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum of 2 concurrency options allowed');

        new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            concurrency: [
                new Concurrency(limit: 10),
                new Concurrency(limit: 20),
                new Concurrency(limit: 30),
            ]
        );
    }

    public function testFunctionWithInvalidConcurrencyTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Concurrency array must contain only Concurrency instances');

        new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            concurrency: ['invalid']
        );
    }

    public function testComplexConcurrencyScenario(): void
    {
        $function = new InngestFunction(
            id: 'process-order',
            handler: fn($ctx) => ['status' => 'complete'],
            triggers: [new TriggerEvent('order/created')],
            name: 'Process Order',
            retries: 5,
            concurrency: [
                new Concurrency(
                    limit: 5,
                    key: 'event.data.user_id',
                    scope: 'fn'
                ),
                new Concurrency(
                    limit: 100,
                    key: 'event.data.region',
                    scope: 'env'
                ),
            ]
        );

        $array = $function->toArray();

        $this->assertSame('process-order', $array['id']);
        $this->assertSame('Process Order', $array['name']);
        $this->assertSame(6, $array['steps']['step']['retries']['attempts']);
        $this->assertArrayHasKey('concurrency', $array);
        $this->assertCount(2, $array['concurrency']);

        $this->assertSame([
            'limit' => 5,
            'key' => 'event.data.user_id',
            'scope' => 'fn',
        ], $array['concurrency'][0]);

        $this->assertSame([
            'limit' => 100,
            'key' => 'event.data.region',
            'scope' => 'env',
        ], $array['concurrency'][1]);
    }

    public function testFunctionConcurrencyWithZeroLimit(): void
    {
        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            concurrency: [new Concurrency(limit: 0)]
        );

        $array = $function->toArray();
        $this->assertSame(['limit' => 0], $array['concurrency'][0]);
    }

    public function testFunctionWithoutPriority(): void
    {
        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')]
        );

        $this->assertNull($function->getPriority());

        $array = $function->toArray();
        $this->assertArrayNotHasKey('priority', $array);
    }

    public function testFunctionWithPriority(): void
    {
        $priority = new Priority(run: 'event.data.priority');

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            priority: $priority
        );

        $this->assertNotNull($function->getPriority());
        $this->assertSame($priority, $function->getPriority());

        $array = $function->toArray();
        $this->assertArrayHasKey('priority', $array);
        $this->assertSame(['run' => 'event.data.priority'], $array['priority']);
    }

    public function testFunctionWithConditionalPriority(): void
    {
        $priority = new Priority(
            run: 'event.data.account_type == "enterprise" ? 120 : 0'
        );

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            priority: $priority
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('priority', $array);
        $this->assertSame([
            'run' => 'event.data.account_type == "enterprise" ? 120 : 0',
        ], $array['priority']);
    }

    public function testFunctionWithNegativePriority(): void
    {
        $priority = new Priority(run: 'event.data.plan == "free" ? -60 : 0');

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            priority: $priority
        );

        $array = $function->toArray();
        $this->assertSame([
            'run' => 'event.data.plan == "free" ? -60 : 0',
        ], $array['priority']);
    }

    public function testPriorityWithEmptyExpressionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority expression cannot be empty');

        new Priority(run: '');
    }

    public function testPriorityWithWhitespaceOnlyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority expression cannot be empty');

        new Priority(run: '   ');
    }

    public function testPriorityWithInvalidCharactersThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority expression contains invalid characters');

        new Priority(run: 'event.data.test; DROP TABLE users;');
    }

    public function testPriorityWithTooLongExpressionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority expression is too long');

        new Priority(run: str_repeat('a', 1001));
    }

    public function testPriorityWithComplexExpression(): void
    {
        $expressions = [
            'event.data.priority',
            'event.data.user.subscription == "pro" ? 180 : 0',
            'event.data.critical == true ? 300 : 0',
            '(event.data.tier == "gold" && event.data.region == "us") ? 240 : 60',
            'event.data.score * 10',
            'event.data.delay_seconds * -1',
        ];

        foreach ($expressions as $expr) {
            $priority = new Priority(run: $expr);
            $this->assertSame($expr, $priority->getRun());
            $this->assertSame(['run' => $expr], $priority->toArray());
        }
    }

    public function testFunctionWithBothConcurrencyAndPriority(): void
    {
        $concurrency = new Concurrency(
            limit: 10,
            key: 'event.data.user_id'
        );
        $priority = new Priority(
            run: 'event.data.plan == "enterprise" ? 120 : 0'
        );

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            concurrency: [$concurrency],
            priority: $priority
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('concurrency', $array);
        $this->assertArrayHasKey('priority', $array);
        $this->assertSame([
            'limit' => 10,
            'key' => 'event.data.user_id',
        ], $array['concurrency'][0]);
        $this->assertSame([
            'run' => 'event.data.plan == "enterprise" ? 120 : 0',
        ], $array['priority']);
    }
}
