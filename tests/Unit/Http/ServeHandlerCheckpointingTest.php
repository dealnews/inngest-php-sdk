<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit\Http;

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Config\Config;
use DealNews\Inngest\Function\Checkpoint;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;
use DealNews\Inngest\Http\ServeHandler;
use DealNews\Inngest\Tests\Support\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

class ServeHandlerCheckpointingTest extends TestCase
{
    private function makeClient(): Inngest
    {
        $config = new Config(
            signing_key: 'signkey-test-abc123',
            is_dev: true
        );

        return new Inngest('test-app', $config);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function callFunction(
        Inngest $client,
        FakeHttpTransport $transport,
        InngestFunction $function,
        string $run_id = 'run-1'
    ): array {
        $handler = new ServeHandler($client, '/api/inngest', $transport);

        $payload = [
            'event' => ['name' => 'test/event', 'data' => []],
            'events' => [['name' => 'test/event', 'data' => []]],
            'steps' => [],
            'ctx' => [
                'run_id' => $run_id,
                'attempt' => 0,
                'use_api' => false,
                'stack' => ['stack' => [], 'current' => 0],
                'qi_id' => 'qi-1',
            ],
        ];

        return $handler->handle(
            'POST',
            '/api/inngest',
            [],
            json_encode($payload),
            ['fnId' => 'test-app-' . $function->getId()]
        );
    }

    public function testSafePresetCheckpointsAfterEveryStep(): void
    {
        $client = $this->makeClient();
        $transport = new FakeHttpTransport();

        $function = new InngestFunction(
            id: 'two-steps',
            handler: function ($ctx) {
                $a = $ctx->getStep()->run('step-a', fn () => 'A');
                $b = $ctx->getStep()->run('step-b', fn () => $a . 'B');
                return ['result' => $b];
            },
            triggers: [new TriggerEvent('test/event')],
            checkpointing: Checkpoint::safe()
        );
        $client->registerFunction($function);

        $response = $this->callFunction($client, $transport, $function);

        $this->assertSame(200, $response['status']);
        $this->assertSame(['result' => 'AB'], json_decode($response['body'], true));
        $this->assertCount(2, $transport->checkpointCalls());

        foreach ($transport->checkpointCalls() as $call) {
            $body = json_decode($call['body'], true);
            $this->assertCount(1, $body['steps']);
        }
    }

    public function testPerformantPresetBatchesAllStepsIntoOneFlush(): void
    {
        $client = $this->makeClient();
        $transport = new FakeHttpTransport();

        $function = new InngestFunction(
            id: 'three-steps',
            handler: function ($ctx) {
                $a = $ctx->getStep()->run('step-a', fn () => 'A');
                $b = $ctx->getStep()->run('step-b', fn () => $a . 'B');
                $c = $ctx->getStep()->run('step-c', fn () => $b . 'C');
                return ['result' => $c];
            },
            triggers: [new TriggerEvent('test/event')],
            checkpointing: Checkpoint::performant()
        );
        $client->registerFunction($function);

        $response = $this->callFunction($client, $transport, $function);

        $this->assertSame(200, $response['status']);
        $this->assertSame(['result' => 'ABC'], json_decode($response['body'], true));

        $checkpoint_calls = $transport->checkpointCalls();
        $this->assertCount(1, $checkpoint_calls);

        $body = json_decode($checkpoint_calls[0]['body'], true);
        $this->assertCount(3, $body['steps']);
    }

    public function testShortMaxIntervalFlushesBeforeCountThreshold(): void
    {
        $client = $this->makeClient();
        $transport = new FakeHttpTransport();

        $function = new InngestFunction(
            id: 'interval-flush',
            handler: function ($ctx) {
                $a = $ctx->getStep()->run('step-a', fn () => 'A');
                usleep(20_000); // 20ms, exceeds the 10ms maxInterval below
                $b = $ctx->getStep()->run('step-b', fn () => $a . 'B');
                return ['result' => $b];
            },
            triggers: [new TriggerEvent('test/event')],
            checkpointing: new Checkpoint(bufferedSteps: 3, maxInterval: '10ms')
        );
        $client->registerFunction($function);

        $response = $this->callFunction($client, $transport, $function);

        $this->assertSame(200, $response['status']);
        $this->assertSame(['result' => 'AB'], json_decode($response['body'], true));

        $checkpoint_calls = $transport->checkpointCalls();
        $this->assertCount(1, $checkpoint_calls);

        $body = json_decode($checkpoint_calls[0]['body'], true);
        $this->assertCount(2, $body['steps']);
    }

    public function testAsyncOpcodeFlushesBufferBeforeYielding(): void
    {
        $client = $this->makeClient();
        $transport = new FakeHttpTransport();

        $function = new InngestFunction(
            id: 'sync-then-async',
            handler: function ($ctx) {
                $a = $ctx->getStep()->run('step-a', fn () => 'A');
                $b = $ctx->getStep()->run('step-b', fn () => $a . 'B');
                $ctx->getStep()->waitForEvent('wait-1', 'some/event', '1h');
                return ['result' => $b];
            },
            triggers: [new TriggerEvent('test/event')],
            checkpointing: Checkpoint::performant()
        );
        $client->registerFunction($function);

        $response = $this->callFunction($client, $transport, $function);

        $this->assertSame(206, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertCount(1, $body);
        $this->assertSame('WaitForEvent', $body[0]['op']);

        $checkpoint_calls = $transport->checkpointCalls();
        $this->assertCount(1, $checkpoint_calls);
        $flushed = json_decode($checkpoint_calls[0]['body'], true);
        $this->assertCount(2, $flushed['steps']);
    }

    public function testFailedFlushBeforeAsyncOpcodeIncludesBufferedStepsInResponse(): void
    {
        $client = $this->makeClient();
        $transport = new FakeHttpTransport();
        // checkpointSteps() retries up to 4 times total before giving up.
        for ($i = 0; $i < 4; $i++) {
            $transport->queueResponse(500);
        }

        $function = new InngestFunction(
            id: 'sync-then-async-fail',
            handler: function ($ctx) {
                $a = $ctx->getStep()->run('step-a', fn () => 'A');
                $b = $ctx->getStep()->run('step-b', fn () => $a . 'B');
                $ctx->getStep()->waitForEvent('wait-1', 'some/event', '1h');
                return ['result' => $b];
            },
            triggers: [new TriggerEvent('test/event')],
            checkpointing: Checkpoint::performant()
        );
        $client->registerFunction($function);

        $response = $this->callFunction($client, $transport, $function);

        $this->assertSame(206, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertCount(3, $body);
        $this->assertSame('StepRun', $body[0]['op']);
        $this->assertSame('StepRun', $body[1]['op']);
        $this->assertSame('WaitForEvent', $body[2]['op']);
    }

    public function testFailedCountTriggeredFlushIncludesOnlyBufferedStepsInResponse(): void
    {
        $client = $this->makeClient();
        $transport = new FakeHttpTransport();
        // checkpointSteps() retries up to 4 times total before giving up.
        for ($i = 0; $i < 4; $i++) {
            $transport->queueResponse(500);
        }

        $function = new InngestFunction(
            id: 'five-steps',
            handler: function ($ctx) {
                $a = $ctx->getStep()->run('step-a', fn () => 'A');
                $b = $ctx->getStep()->run('step-b', fn () => $a . 'B');
                $c = $ctx->getStep()->run('step-c', fn () => $b . 'C');
                $d = $ctx->getStep()->run('step-d', fn () => $c . 'D');
                $e = $ctx->getStep()->run('step-e', fn () => $d . 'E');
                return ['result' => $e];
            },
            triggers: [new TriggerEvent('test/event')],
            checkpointing: new Checkpoint(bufferedSteps: 3)
        );
        $client->registerFunction($function);

        $response = $this->callFunction($client, $transport, $function);

        $this->assertSame(206, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertCount(3, $body);
        $this->assertSame('step-a', $body[0]['displayName']);
        $this->assertSame('step-b', $body[1]['displayName']);
        $this->assertSame('step-c', $body[2]['displayName']);
    }

    public function testCheckpointingDisabledBehavesLikeToday(): void
    {
        $client = $this->makeClient();
        $transport = new FakeHttpTransport();

        $function = new InngestFunction(
            id: 'no-checkpointing',
            handler: function ($ctx) {
                $a = $ctx->getStep()->run('step-a', fn () => 'A');
                $b = $ctx->getStep()->run('step-b', fn () => $a . 'B');
                return ['result' => $b];
            },
            triggers: [new TriggerEvent('test/event')],
            checkpointing: null
        );
        $client->registerFunction($function);

        $response = $this->callFunction($client, $transport, $function);

        $this->assertSame(206, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertCount(1, $body);
        $this->assertSame('step-a', $body[0]['displayName']);
        $this->assertCount(0, $transport->checkpointCalls());
        $this->assertCount(0, $transport->calls);
    }
}
