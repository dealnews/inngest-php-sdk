<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Function\Concurrency;
use DealNews\Inngest\Function\Debounce;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\Priority;
use DealNews\Inngest\Function\Singleton;
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
        $this->expectExceptionMessage('Priority expression is too long (max 1000 characters)');

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

    public function testFunctionWithoutDebounce(): void
    {
        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')]
        );

        $this->assertNull($function->getDebounce());

        $array = $function->toArray();
        $this->assertArrayNotHasKey('debounce', $array);
    }

    public function testFunctionWithBasicDebounce(): void
    {
        $debounce = new Debounce(period: '30s');

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            debounce: $debounce
        );

        $this->assertNotNull($function->getDebounce());
        $this->assertSame($debounce, $function->getDebounce());

        $array = $function->toArray();
        $this->assertArrayHasKey('debounce', $array);
        $this->assertSame(['period' => '30s'], $array['debounce']);
    }

    public function testFunctionWithDebounceWithKey(): void
    {
        $debounce = new Debounce(
            period: '5m',
            key: 'event.data.user_id'
        );

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            debounce: $debounce
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('debounce', $array);
        $this->assertSame([
            'period' => '5m',
            'key'    => 'event.data.user_id',
        ], $array['debounce']);
    }

    public function testFunctionWithDebounceWithTimeout(): void
    {
        $debounce = new Debounce(
            period: '1m',
            timeout: '10m'
        );

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            debounce: $debounce
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('debounce', $array);
        $this->assertSame([
            'period'  => '1m',
            'timeout' => '10m',
        ], $array['debounce']);
    }

    public function testFunctionWithFullDebounceConfiguration(): void
    {
        $debounce = new Debounce(
            period: '30s',
            key: 'event.data.customer_id + "-" + event.data.region',
            timeout: '5m'
        );

        $function = new InngestFunction(
            id: 'process-webhook',
            handler: fn($ctx) => ['status' => 'complete'],
            triggers: [new TriggerEvent('webhook/received')],
            name: 'Process Webhook',
            debounce: $debounce
        );

        $array = $function->toArray();
        $this->assertSame('process-webhook', $array['id']);
        $this->assertSame('Process Webhook', $array['name']);
        $this->assertArrayHasKey('debounce', $array);
        $this->assertSame([
            'period'  => '30s',
            'key'     => 'event.data.customer_id + "-" + event.data.region',
            'timeout' => '5m',
        ], $array['debounce']);
    }

    public function testFunctionWithDebounceAndConcurrency(): void
    {
        $debounce = new Debounce(
            period: '1m',
            key: 'event.data.user_id'
        );
        $concurrency = new Concurrency(
            limit: 10,
            key: 'event.data.user_id'
        );

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            debounce: $debounce,
            concurrency: [$concurrency]
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('debounce', $array);
        $this->assertArrayHasKey('concurrency', $array);
        $this->assertSame([
            'period' => '1m',
            'key'    => 'event.data.user_id',
        ], $array['debounce']);
        $this->assertSame([
            'limit' => 10,
            'key'   => 'event.data.user_id',
        ], $array['concurrency'][0]);
    }

    public function testFunctionWithDebounceAndPriority(): void
    {
        $debounce = new Debounce(period: '30s');
        $priority = new Priority(run: 'event.data.priority');

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            debounce: $debounce,
            priority: $priority
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('debounce', $array);
        $this->assertArrayHasKey('priority', $array);
        $this->assertSame(['period' => '30s'], $array['debounce']);
        $this->assertSame(['run' => 'event.data.priority'], $array['priority']);
    }

    public function testFunctionWithAllFeatures(): void
    {
        $debounce = new Debounce(
            period: '1m',
            key: 'event.data.user_id',
            timeout: '10m'
        );
        $concurrency = new Concurrency(
            limit: 5,
            key: 'event.data.user_id',
            scope: 'fn'
        );
        $priority = new Priority(
            run: 'event.data.plan == "enterprise" ? 120 : 0'
        );

        $function = new InngestFunction(
            id: 'complex-function',
            handler: fn($ctx) => ['status' => 'complete'],
            triggers: [new TriggerEvent('user/action')],
            name: 'Complex Function',
            retries: 5,
            debounce: $debounce,
            concurrency: [$concurrency],
            priority: $priority,
            description: 'A function with all features enabled'
        );

        $array = $function->toArray();
        $this->assertSame('complex-function', $array['id']);
        $this->assertSame('Complex Function', $array['name']);
        $this->assertSame('A function with all features enabled', $array['description']);
        $this->assertSame(6, $array['steps']['step']['retries']['attempts']);

        $this->assertArrayHasKey('debounce', $array);
        $this->assertSame([
            'period'  => '1m',
            'key'     => 'event.data.user_id',
            'timeout' => '10m',
        ], $array['debounce']);

        $this->assertArrayHasKey('concurrency', $array);
        $this->assertSame([
            'limit' => 5,
            'key'   => 'event.data.user_id',
            'scope' => 'fn',
        ], $array['concurrency'][0]);

        $this->assertArrayHasKey('priority', $array);
        $this->assertSame([
            'run' => 'event.data.plan == "enterprise" ? 120 : 0',
        ], $array['priority']);
    }

    public function testFunctionWithoutSingleton(): void
    {
        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')]
        );

        $this->assertNull($function->getSingleton());

        $array = $function->toArray();
        $this->assertArrayNotHasKey('singleton', $array);
    }

    public function testFunctionWithBasicSingleton(): void
    {
        $singleton = new Singleton(mode: 'skip');

        $function = new InngestFunction(
            id: 'test-function',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('test/event')],
            singleton: $singleton
        );

        $this->assertNotNull($function->getSingleton());
        $this->assertSame($singleton, $function->getSingleton());

        $array = $function->toArray();
        $this->assertArrayHasKey('singleton', $array);
        $this->assertSame(['mode' => 'skip'], $array['singleton']);
    }

    public function testFunctionWithSingletonSkipMode(): void
    {
        $singleton = new Singleton(mode: 'skip');

        $function = new InngestFunction(
            id: 'data-sync',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('sync/start')],
            singleton: $singleton
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('singleton', $array);
        $this->assertSame('skip', $array['singleton']['mode']);
    }

    public function testFunctionWithSingletonCancelMode(): void
    {
        $singleton = new Singleton(mode: 'cancel');

        $function = new InngestFunction(
            id: 'process-latest',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('data/updated')],
            singleton: $singleton
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('singleton', $array);
        $this->assertSame('cancel', $array['singleton']['mode']);
    }

    public function testFunctionWithSingletonWithKey(): void
    {
        $singleton = new Singleton(
            mode: 'skip',
            key: 'event.data.user_id'
        );

        $function = new InngestFunction(
            id: 'user-task',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('task/start')],
            singleton: $singleton
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('singleton', $array);
        $this->assertSame([
            'mode' => 'skip',
            'key'  => 'event.data.user_id',
        ], $array['singleton']);
    }

    public function testFunctionWithSingletonComplexKey(): void
    {
        $singleton = new Singleton(
            mode: 'cancel',
            key: 'event.data.customer_id + "-" + event.data.region'
        );

        $function = new InngestFunction(
            id: 'sync-customer-data',
            handler: fn($ctx) => ['status' => 'synced'],
            triggers: [new TriggerEvent('customer/updated')],
            name: 'Sync Customer Data',
            singleton: $singleton
        );

        $array = $function->toArray();
        $this->assertSame('sync-customer-data', $array['id']);
        $this->assertSame('Sync Customer Data', $array['name']);
        $this->assertArrayHasKey('singleton', $array);
        $this->assertSame([
            'mode' => 'cancel',
            'key'  => 'event.data.customer_id + "-" + event.data.region',
        ], $array['singleton']);
    }

    public function testFunctionWithSingletonAndDebounce(): void
    {
        $singleton = new Singleton(
            mode: 'skip',
            key: 'event.data.user_id'
        );
        $debounce = new Debounce(
            period: '1m',
            key: 'event.data.user_id'
        );

        $function = new InngestFunction(
            id: 'process-user-events',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('user/event')],
            singleton: $singleton,
            debounce: $debounce
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('singleton', $array);
        $this->assertArrayHasKey('debounce', $array);
        $this->assertSame([
            'mode' => 'skip',
            'key'  => 'event.data.user_id',
        ], $array['singleton']);
        $this->assertSame([
            'period' => '1m',
            'key'    => 'event.data.user_id',
        ], $array['debounce']);
    }

    public function testFunctionWithSingletonAndPriority(): void
    {
        $singleton = new Singleton(mode: 'cancel');
        $priority = new Priority(run: 'event.data.priority');

        $function = new InngestFunction(
            id: 'priority-task',
            handler: fn() => 'result',
            triggers: [new TriggerEvent('task/execute')],
            singleton: $singleton,
            priority: $priority
        );

        $array = $function->toArray();
        $this->assertArrayHasKey('singleton', $array);
        $this->assertArrayHasKey('priority', $array);
        $this->assertSame(['mode' => 'cancel'], $array['singleton']);
        $this->assertSame(['run' => 'event.data.priority'], $array['priority']);
    }

    public function testFunctionWithAllFeaturesIncludingSingleton(): void
    {
        $singleton = new Singleton(
            mode: 'skip',
            key: 'event.data.user_id'
        );
        $debounce = new Debounce(
            period: '1m',
            key: 'event.data.user_id',
            timeout: '10m'
        );
        $concurrency = new Concurrency(
            limit: 5,
            key: 'event.data.user_id',
            scope: 'fn'
        );
        $priority = new Priority(
            run: 'event.data.plan == "enterprise" ? 120 : 0'
        );

        $function = new InngestFunction(
            id: 'complex-workflow',
            handler: fn($ctx) => ['status' => 'complete'],
            triggers: [new TriggerEvent('workflow/start')],
            name: 'Complex Workflow',
            retries: 5,
            singleton: $singleton,
            debounce: $debounce,
            concurrency: [$concurrency],
            priority: $priority,
            description: 'A workflow with all features enabled'
        );

        $array = $function->toArray();
        $this->assertSame('complex-workflow', $array['id']);
        $this->assertSame('Complex Workflow', $array['name']);
        $this->assertSame(
            'A workflow with all features enabled',
            $array['description']
        );
        $this->assertSame(6, $array['steps']['step']['retries']['attempts']);

        $this->assertArrayHasKey('singleton', $array);
        $this->assertSame([
            'mode' => 'skip',
            'key'  => 'event.data.user_id',
        ], $array['singleton']);

        $this->assertArrayHasKey('debounce', $array);
        $this->assertSame([
            'period'  => '1m',
            'key'     => 'event.data.user_id',
            'timeout' => '10m',
        ], $array['debounce']);

        $this->assertArrayHasKey('concurrency', $array);
        $this->assertSame([
            'limit' => 5,
            'key'   => 'event.data.user_id',
            'scope' => 'fn',
        ], $array['concurrency'][0]);

        $this->assertArrayHasKey('priority', $array);
        $this->assertSame([
            'run' => 'event.data.plan == "enterprise" ? 120 : 0',
        ], $array['priority']);
    }
}
