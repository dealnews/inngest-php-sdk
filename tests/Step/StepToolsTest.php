<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Step;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Api\SendSignalResult;
use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Event\EventMeta;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepIdResolver;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\FunctionReference;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\SignalConflict;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\FixedVersion;
use DealNews\Inngest\Tests\mock\MockHttp;
use DealNews\Inngest\Tests\mock\RecordingEngine;
use DealNews\Inngest\Tests\mock\RecordingLogger;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for StepTools (ports the runtime cases of InngestStepTools.test.ts)
 *
 * The engine is replaced by one that records ops, so these check op
 * shapes and result handling. Running steps is in the engine tests; the
 * wire format is in EngineParityTest.
 *
 * @package DealNews\Inngest\Tests\Step
 */
#[CoversClass(StepTools::class)]
#[UsesClass(AiTools::class)]
#[UsesClass(ApiClient::class)]
#[UsesClass(Client::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(Context::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Engine::class)]
#[UsesClass(Env::class)]
#[UsesClass(ErrorSerializer::class)]
#[UsesClass(Event::class)]
#[UsesClass(EventMeta::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(FunctionReference::class)]
#[UsesClass(Headers::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(Retry::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(SendEventResult::class)]
#[UsesClass(SendSignalResult::class)]
#[UsesClass(Signature::class)]
#[UsesClass(StepIdResolver::class)]
#[UsesClass(StepOp::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepState::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(Version::class)]
class StepToolsTest extends TestCase {

    /**
     * The recording engine behind the last tools built
     *
     * @var RecordingEngine
     */
    protected RecordingEngine $engine;

    /**
     * Mocked HTTP behind the last client built
     *
     * @var MockHttp
     */
    protected MockHttp $http;

    /**
     * Logger given to the last tools built
     *
     * @var RecordingLogger
     */
    protected RecordingLogger $logger;

    /**
     * Builds tools on a recording engine, with a client on mock HTTP
     *
     * @param list<mixed> $responses Queued HTTP responses
     * @param bool        $client    Whether to give the tools a client
     *
     * @return StepTools
     */
    protected function tools(
        array $responses = [],
        bool $client = true
    ): StepTools {
        $this->engine = RecordingEngine::create();
        $this->http   = new MockHttp($responses);
        $this->logger = new RecordingLogger();

        return new StepTools(
            $this->engine,
            $client ?
                new Client(
                    'test-app',
                    new ClientOptions(is_dev: true),
                    http_client: $this->http->client,
                    env: new Env([]),
                    version: new FixedVersion(),
                    retry: new Retry(static function (float $ms): void {
                    })
                ) :
                null,
            $this->logger
        );
    }

    /**
     * Asserts the ID, op code, name, and display name of the last op
     *
     * @param OpCode      $op           Op code
     * @param string|null $name         Name
     * @param string      $display_name Display name
     * @param string      $id           Step ID
     *
     * @return void
     */
    protected function assertOp(
        OpCode $op,
        ?string $name,
        string $display_name = 'id',
        string $id = 'id'
    ): void {
        $last = $this->engine->lastOp();
        $this->assertSame($id, $last->id);
        $this->assertSame($op, $last->op);
        $this->assertSame($name, $last->name);
        $this->assertSame($display_name, $last->display_name);
    }

    /**
     * `run` plans a step named after its ID
     *
     * @return void
     */
    public function testRun(): void {
        $tools = $this->tools();

        $this->assertSame('ran', $tools->run('id', static fn () => 'ran'));
        $this->assertOp(OpCode::StepPlanned, 'id');
        $this->assertSame([], $this->engine->lastOp()->opts);
        $this->assertSame([], $this->engine->calls[0]['args']);
    }

    /**
     * `run` with options and input
     *
     * @return void
     */
    public function testRunWithOptionsAndInput(): void {
        $tools = $this->tools();

        $result = $tools->run(
            new StepOptions('id', 'Add'),
            static fn (int $a, int $b) => $a + $b,
            ...['a' => 1, 'b' => 2]
        );

        $this->assertSame(3, $result);
        $this->assertOp(OpCode::StepPlanned, 'id', 'Add');
        $this->assertSame(['input' => [1, 2]], $this->engine->lastOp()->opts);
        $this->assertSame([1, 2], $this->engine->calls[0]['args']);
    }

    /**
     * Any callable works
     *
     * @return void
     */
    public function testRunCallable(): void {
        $this->assertSame(
            'ABC',
            $this->tools()->run('A', 'strtoupper', 'abc')
        );
    }

    /**
     * `sleep` names the op with the time string
     *
     * @param int|float|string|\DateInterval $duration Duration
     * @param string                         $expected Time string
     *
     * @return void
     */
    #[DataProvider('sleepProvider')]
    public function testSleep(
        int|float|string|\DateInterval $duration,
        string $expected
    ): void {
        $tools = $this->tools();

        $tools->sleep('id', $duration);

        $this->assertOp(OpCode::Sleep, $expected);
        $this->assertSame([], $this->engine->lastOp()->opts);
        $this->assertNull($this->engine->calls[0]['fn']);
    }

    /**
     * Sleep durations (JS: ms numbers, ms strings, Temporal.Duration)
     *
     * @return array<string, array{int|float|string|\DateInterval, string}>
     */
    public static function sleepProvider(): array {
        return [
            'milliseconds'      => [60000, '1m'],
            'sub-second'        => [500, '1s'],
            'ms string'         => ['1m', '1m'],
            'compound string'   => ['1h30m', '1h30m'],
            'interval'          => [new \DateInterval('PT1M'), '1m'],
        ];
    }

    /**
     * Sub-second sleeps warn through the given logger
     *
     * @return void
     */
    public function testSleepWarnsThroughLogger(): void {
        $this->tools()->sleep('id', 500);

        $this->assertNotEmpty($this->logger->records);
    }

    /**
     * `sleep` takes a display name
     *
     * @return void
     */
    public function testSleepName(): void {
        $this->tools()->sleep(new StepOptions('id', 'name'), '1m');

        $this->assertOp(OpCode::Sleep, '1m', 'name');
    }

    /**
     * `sleepUntil` names the op with a UTC ISO date
     *
     * @param \DateTimeInterface|string $time     Time
     * @param string                    $expected ISO string
     *
     * @return void
     */
    #[DataProvider('sleepUntilProvider')]
    public function testSleepUntil(
        \DateTimeInterface|string $time,
        string $expected
    ): void {
        $tools = $this->tools();

        $tools->sleepUntil(new StepOptions('id', 'name'), $time);

        $this->assertOp(OpCode::Sleep, $expected, 'name');
    }

    /**
     * Dates and date strings
     *
     * @return array<string, array{\DateTimeInterface|string, string}>
     */
    public static function sleepUntilProvider(): array {
        return [
            'date' => [
                new \DateTimeImmutable('2030-01-02T03:04:05.678Z'),
                '2030-01-02T03:04:05.678Z',
            ],
            'other zone' => [
                new \DateTime('2030-01-02 03:04:05', new \DateTimeZone(
                    'America/Chicago'
                )),
                '2030-01-02T09:04:05.000Z',
            ],
            'ISO string' => [
                '2030-01-02T03:04:05.678Z',
                '2030-01-02T03:04:05.678Z',
            ],
            'offset string' => [
                '2030-01-02T03:04:05+02:00',
                '2030-01-02T01:04:05.000Z',
            ],
        ];
    }

    /**
     * Bad date strings throw
     *
     * @param string $time Time
     *
     * @return void
     */
    #[DataProvider('badDateProvider')]
    public function testSleepUntilInvalid(string $time): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(7302);
        $this->expectExceptionMessage(
            'Invalid date or date string passed to sleepUntil'
        );

        $this->tools()->sleepUntil('id', $time);
    }

    /**
     * Unparseable dates
     *
     * @return array<string, array{string}>
     */
    public static function badDateProvider(): array {
        return [
            'garbage' => ['bad'],
            'empty'   => [''],
            'spaces'  => ['  '],
        ];
    }

    /**
     * `waitForEvent` op shape
     *
     * @return void
     */
    public function testWaitForEvent(): void {
        $tools = $this->tools();

        $tools->waitForEvent('id', 'event', '2h');

        $this->assertOp(OpCode::WaitForEvent, 'event');
        $this->assertSame(['timeout' => '2h'], $this->engine->lastOp()->opts);
        $this->assertNull($this->engine->calls[0]['fn']);
    }

    /**
     * `waitForEvent` options
     *
     * @param array<string, mixed> $args     Named arguments after the ID
     * @param array<string, mixed> $expected Expected opts
     *
     * @return void
     */
    #[DataProvider('waitForEventProvider')]
    public function testWaitForEventOptions(
        array $args,
        array $expected
    ): void {
        $tools = $this->tools();

        $tools->waitForEvent(new StepOptions('id', 'name'), ...$args);

        $this->assertOp(OpCode::WaitForEvent, 'event', 'name');
        $this->assertSame($expected, $this->engine->lastOp()->opts);
    }

    /**
     * Timeouts and match/if
     *
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function waitForEventProvider(): array {
        return [
            'string timeout' => [
                ['event' => 'event', 'timeout' => '1m'],
                ['timeout' => '1m'],
            ],
            'sub-second timeout' => [
                ['event' => 'event', 'timeout' => 500],
                ['timeout' => '1s'],
            ],
            'date timeout' => [
                [
                    'event'   => 'event',
                    'timeout' => new \DateTimeImmutable(
                        '2030-01-02T03:04:05.678Z'
                    ),
                ],
                ['timeout' => '2030-01-02T03:04:05.678Z'],
            ],
            'interval timeout' => [
                [
                    'event'   => 'event',
                    'timeout' => new \DateInterval('PT2H'),
                ],
                ['timeout' => '2h'],
            ],
            'match' => [
                ['event' => 'event', 'timeout' => '2h', 'match' => 'name'],
                ['timeout' => '2h', 'if' => 'event.name == async.name'],
            ],
            'if' => [
                ['event' => 'event', 'timeout' => '2h', 'if' => 'name == 123'],
                ['timeout' => '2h', 'if' => 'name == 123'],
            ],
            'empty match' => [
                ['event' => 'event', 'timeout' => '2h', 'match' => ''],
                ['timeout' => '2h'],
            ],
            'empty if' => [
                ['event' => 'event', 'timeout' => '2h', 'if' => ''],
                ['timeout' => '2h'],
            ],
        ];
    }

    /**
     * `match` and `if` can't be combined (a type error in JS)
     *
     * @return void
     */
    public function testWaitForEventMatchAndIf(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(7301);

        $this->tools()->waitForEvent(
            'id',
            'event',
            '2h',
            match: 'a',
            if: 'b'
        );
    }

    /**
     * `waitForEvent` returns the event, or null on timeout
     *
     * @return void
     */
    public function testWaitForEventResult(): void {
        $tools = $this->tools();

        $this->engine->result = ['name' => 'event', 'data' => ['a' => 1]];
        $this->assertSame(
            ['name' => 'event', 'data' => ['a' => 1]],
            $tools->waitForEvent('id', 'event', '2h')
        );

        $this->engine->result = null;
        $this->assertNull($tools->waitForEvent('id', 'event', '2h'));
    }

    /**
     * `invoke` op shape with a function instance
     *
     * @return void
     */
    public function testInvokeFunction(): void {
        $tools = $this->tools();
        $fn    = new InngestFunction(
            'test-client',
            new FunctionOptions(id: 'test-fn'),
            static fn () => 'test-return'
        );

        $this->engine->result = 'test-return';

        $result = $tools->invoke('id', $fn, data: ['foo' => 'foo']);

        $this->assertSame('test-return', $result);
        $this->assertOp(OpCode::InvokeFunction, null);
        $this->assertEquals(
            [
                'payload'     => ['data' => ['foo' => 'foo']],
                'function_id' => 'test-client-test-fn',
            ],
            $this->engine->lastOp()->opts
        );
    }

    /**
     * `invoke` function IDs for references
     *
     * @return void
     */
    public function testInvokeReference(): void {
        $tools = $this->tools();

        $tools->invoke('id', new FunctionReference('test-fn'));
        $this->assertSame(
            'test-app-test-fn',
            $this->engine->lastOp()->opts['function_id']
        );

        $tools->invoke(
            new StepOptions('id', 'name'),
            new FunctionReference('some-fn', 'some-client')
        );
        $this->assertSame(
            'some-client-some-fn',
            $this->engine->lastOp()->opts['function_id']
        );
        $this->assertOp(OpCode::InvokeFunction, null, 'name');
    }

    /**
     * `invoke` timeouts
     *
     * @param int|float|string|\DateInterval|\DateTimeInterface $timeout
     *        Timeout
     * @param string                                            $expected
     *        Time string
     *
     * @return void
     */
    #[DataProvider('invokeTimeoutProvider')]
    public function testInvokeTimeout(
        int|float|string|\DateInterval|\DateTimeInterface $timeout,
        string $expected
    ): void {
        $tools = $this->tools();

        $tools->invoke('id', new FunctionReference('fn'), timeout: $timeout);

        $this->assertSame(
            $expected,
            $this->engine->lastOp()->opts['timeout']
        );
    }

    /**
     * Timeouts (JS: string, date, number, Temporal)
     *
     * @return array<string, array{mixed, string}>
     */
    public static function invokeTimeoutProvider(): array {
        return [
            'string'   => ['1m', '1m'],
            'number'   => [60000, '1m'],
            'interval' => [new \DateInterval('PT1M'), '1m'],
            'date'     => [
                new \DateTimeImmutable('2030-01-02T03:04:05.678Z'),
                '2030-01-02T03:04:05.678Z',
            ],
        ];
    }

    /**
     * `invoke` payload: data, version, and normalized sessions; empty
     * pieces are left out
     *
     * @return void
     */
    public function testInvokePayload(): void {
        $tools = $this->tools();
        $ref   = new FunctionReference('fn');

        $tools->invoke('id', $ref);
        $this->assertSame(
            '{"payload":{},"function_id":"test-app-fn"}',
            json_encode($this->engine->lastOp()->opts)
        );

        $tools->invoke('id', $ref, data: [], meta: new EventMeta([]));
        $this->assertSame(
            '{"payload":{"data":{}},"function_id":"test-app-fn"}',
            json_encode($this->engine->lastOp()->opts)
        );

        $tools->invoke(
            'id',
            $ref,
            data: ['foo' => 'foo'],
            v: '2',
            meta: new EventMeta([
                'conversation_id' => 'conversation_1234',
                'priority'        => 1,
            ])
        );
        $this->assertSame(
            '{"payload":{"data":{"foo":"foo"},"v":"2","meta":{"sessions":' .
            '{"conversation_id":"conversation_1234","priority":"1"}}},' .
            '"function_id":"test-app-fn"}',
            json_encode($this->engine->lastOp()->opts)
        );
    }

    /**
     * Invalid session values are rejected
     *
     * @return void
     */
    public function testInvokeInvalidSessions(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Event session "conversation_id" must be a finite number'
        );

        $this->tools()->invoke(
            'id',
            new FunctionReference('fn'),
            meta: new EventMeta(['conversation_id' => NAN])
        );
    }

    /**
     * A reference without an app ID needs the client
     *
     * @return void
     */
    public function testInvokeReferenceNeedsClient(): void {
        $tools = $this->tools([], false);

        $tools->invoke('id', new FunctionReference('fn', 'app'));
        $this->assertSame(
            'app-fn',
            $this->engine->lastOp()->opts['function_id']
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(7304);
        $tools->invoke('id', new FunctionReference('fn'));
    }

    /**
     * `sendEvent` op shape, and its code sends through the client
     *
     * @return void
     */
    public function testSendEvent(): void {
        $tools = $this->tools([
            MockHttp::json(200, ['ids' => ['a', 'b'], 'status' => 200]),
        ]);

        $result = $tools->sendEvent(new StepOptions('id', 'name'), [
            new Event('step', ['foo' => 'foo']),
            ['name' => 'step', 'data' => ['bar' => 1]],
        ]);

        $this->assertSame(['a', 'b'], $result->ids);
        $this->assertOp(OpCode::StepPlanned, 'sendEvent', 'name');
        $this->assertSame(
            ['type' => 'step.sendEvent'],
            $this->engine->lastOp()->opts
        );
        $this->assertSame(
            '/e/NO_EVENT_KEY_SET',
            $this->http->requests[0]->getUri()->getPath()
        );
        $sent = json_decode(
            (string)$this->http->requests[0]->getBody(),
            true
        );
        $this->assertSame('step', $sent[0]['name']);
        $this->assertSame(['bar' => 1], $sent[1]['data']);
    }

    /**
     * Memoized `sendEvent` data becomes a result object
     *
     * @return void
     */
    public function testSendEventMemoized(): void {
        $tools = $this->tools();

        $this->engine->memoized = true;
        $this->engine->result   = ['ids' => ['x']];
        $this->assertSame(['x'], $tools->sendEvent('id', [])->ids);

        $this->engine->result = 'garbage';
        $this->assertSame([], $tools->sendEvent('id', [])->ids);
        $this->assertSame([], $this->http->requests);
    }

    /**
     * `sendEvent` needs the client
     *
     * @return void
     */
    public function testSendEventNeedsClient(): void {
        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(7304);

        $this->tools([], false)->sendEvent('id', new Event('a'));
    }

    /**
     * `sendSignal` op shape, and its code sends through the client
     *
     * @return void
     */
    public function testSendSignal(): void {
        $tools = $this->tools([
            MockHttp::json(200, ['data' => ['run_id' => 'run-2']]),
        ]);

        $result = $tools->sendSignal('id', 'sig', ['ok' => true]);

        $this->assertSame('run-2', $result->run_id);
        $this->assertOp(OpCode::StepPlanned, 'sendSignal');
        $this->assertSame(
            ['type' => 'step.sendSignal', 'signal' => 'sig'],
            $this->engine->lastOp()->opts
        );
        $this->assertSame(
            '{"signal":"sig","data":{"ok":true}}',
            (string)$this->http->requests[0]->getBody()
        );
    }

    /**
     * Memoized `sendSignal` data becomes a result object
     *
     * @return void
     */
    public function testSendSignalMemoized(): void {
        $tools = $this->tools();

        $this->engine->memoized = true;
        $this->engine->result   = ['runId' => 'run-3'];
        $this->assertSame('run-3', $tools->sendSignal('id', 's')->run_id);

        $this->engine->result = null;
        $this->assertNull($tools->sendSignal('id', 's')->run_id);
    }

    /**
     * `waitForSignal` op shape and result
     *
     * @return void
     */
    public function testWaitForSignal(): void {
        $tools = $this->tools();

        $this->assertNull(
            $tools->waitForSignal('id', 'sig', '2h', SignalConflict::Fail)
        );
        $this->assertOp(OpCode::WaitForSignal, 'sig');
        $this->assertSame(
            ['signal' => 'sig', 'timeout' => '2h', 'conflict' => 'fail'],
            $this->engine->lastOp()->opts
        );

        $this->engine->result = ['signal' => 'sig', 'data' => 1];
        $this->assertSame(
            ['signal' => 'sig', 'data' => 1],
            $tools->waitForSignal(
                new StepOptions('id', 'name'),
                'sig',
                60000,
                SignalConflict::Replace
            )
        );
        $this->assertOp(OpCode::WaitForSignal, 'sig', 'name');
        $this->assertSame(
            ['signal' => 'sig', 'timeout' => '1m', 'conflict' => 'replace'],
            $this->engine->lastOp()->opts
        );
    }

    /**
     * `fetch` reports a Gateway op named after the host
     *
     * @return void
     */
    public function testFetch(): void {
        $tools = $this->tools();

        $this->engine->result = [
            'status_code' => 201,
            'headers'     => ['x-r' => 'yes'],
            'body'        => 'hi',
        ];
        $response = $tools->fetch(
            'https://Example.com/path?q=1',
            'POST',
            ['X-Token' => 'abc'],
            '{"a":1}'
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('x-r'));
        $this->assertSame('hi', (string)$response->getBody());
        $this->assertOp(
            OpCode::Gateway,
            null,
            'step.fetch: example.com',
            'step.fetch: example.com'
        );
        $this->assertSame(
            [
                'url'     => 'https://Example.com/path?q=1',
                'method'  => 'POST',
                'headers' => ['x-token' => 'abc'],
                'body'    => '{"a":1}',
            ],
            $this->engine->lastOp()->opts
        );
        $this->assertNull($this->engine->calls[0]['fn']);
    }

    /**
     * `fetch` defaults: GET, `{}` headers, no body
     *
     * @return void
     */
    public function testFetchDefaults(): void {
        $tools = $this->tools();

        $response = $tools->fetch('https://example.com/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string)$response->getBody());
        $this->assertSame(
            '{"url":"https://example.com/","method":"GET","headers":{}}',
            json_encode(
                $this->engine->lastOp()->opts,
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    /**
     * `fetch` needs an absolute URL
     *
     * @return void
     */
    public function testFetchInvalidUrl(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(7303);

        $this->tools()->fetch('/relative');
    }

    /**
     * Inside a running step, `fetch` makes the request directly
     *
     * @return void
     */
    public function testFetchInsideStep(): void {
        $tools = $this->tools([
            new \GuzzleHttp\Psr7\Response(404, [], 'nope'),
            new \GuzzleHttp\Psr7\Response(200, [], 'ok'),
        ]);
        $this->engine->executing = true;

        $response = $tools->fetch(
            'https://example.com/a',
            'PUT',
            ['x-a' => '1'],
            'body'
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->engine->calls);
        $request = $this->http->requests[0];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('1', $request->getHeaderLine('x-a'));
        $this->assertSame('body', (string)$request->getBody());

        $response = $tools->fetch('https://example.com/b');
        $this->assertSame('ok', (string)$response->getBody());
        $this->assertSame('GET', $this->http->requests[1]->getMethod());
        $this->assertSame('', (string)$this->http->requests[1]->getBody());
    }

    /**
     * The direct request inside a step needs the client
     *
     * @return void
     */
    public function testFetchInsideStepNeedsClient(): void {
        $tools                   = $this->tools([], false);
        $this->engine->executing = true;

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(7304);
        $tools->fetch('https://example.com/');
    }

    /**
     * The AI tools are exposed as `ai`
     *
     * @return void
     */
    public function testAi(): void {
        $tools = $this->tools();

        $this->assertSame('X', $tools->ai->wrap('id', 'strtoupper', 'x'));
        $this->assertSame(
            ['input' => ['x'], 'type' => 'step.ai.wrap'],
            $this->engine->lastOp()->opts
        );
    }

    /**
     * `runOp` builds the step.run op
     *
     * @return void
     */
    public function testRunOp(): void {
        $op = StepTools::runOp(new StepOptions('id'), [], 'kind');

        $this->assertSame(['type' => 'kind'], $op->opts);
        $this->assertSame('id', $op->display_name);
    }
}
