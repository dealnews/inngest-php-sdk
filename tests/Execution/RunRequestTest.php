<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Api\ApiException;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\Mode;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Execution\ExecutionException;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\FixedVersion;
use DealNews\Inngest\Tests\mock\MockHttp;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for RunRequest (ports parseFnData / fetchAllFnData behavior)
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(RunRequest::class)]
#[UsesClass(ApiClient::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(Event::class)]
#[UsesClass(ExecutionException::class)]
#[UsesClass(Headers::class)]
#[UsesClass(Signature::class)]
#[UsesClass(StepState::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(Version::class)]
class RunRequestTest extends TestCase {

    /**
     * Encodes a body, merging fields over a minimal valid one
     *
     * @param array<string, mixed> $fields Fields
     *
     * @return string
     */
    protected static function body(array $fields = []): string {
        return (string)json_encode(array_merge(
            ['event' => ['name' => 'demo/event', 'data' => ['n' => 1]]],
            $fields
        ));
    }

    /**
     * Every field is read
     *
     * @return void
     */
    public function testParse(): void {
        $run = RunRequest::parse(static::body([
            'events'  => [['name' => 'a'], ['name' => 'b']],
            'steps'   => ['h1' => ['data' => 1], 'h2' => ['input' => [2]]],
            'version' => 1,
            'ctx'     => [
                'run_id'                      => 'run-1',
                'fn_id'                       => 'fn-uuid',
                'attempt'                     => 2,
                'max_attempts'                => 4,
                'disable_immediate_execution' => true,
                'use_api'                     => false,
                'qi_id'                       => 'qi',
                'stack'                       => [
                    'stack'   => ['h2', 'h1'],
                    'current' => 1,
                ],
                'extra' => 'ignored',
            ],
        ]), null);

        $this->assertSame('demo/event', $run->event->name);
        $this->assertSame(['n' => 1], $run->event->data);
        $this->assertSame(['a', 'b'], array_map(
            static fn (Event $event) => $event->name,
            $run->events
        ));
        $this->assertSame(1, $run->steps['h1']->data);
        $this->assertSame([2], $run->steps['h2']->input);
        $this->assertSame(1, $run->version);
        $this->assertFalse($run->sdk_decided);
        $this->assertSame('run-1', $run->run_id);
        $this->assertSame('fn-uuid', $run->fn_id);
        $this->assertSame(2, $run->attempt);
        $this->assertSame(4, $run->max_attempts);
        $this->assertTrue($run->disable_immediate_execution);
        $this->assertFalse($run->use_api);
        $this->assertSame('qi', $run->qi_id);
        $this->assertSame(['h2', 'h1'], $run->stack);
        $this->assertSame(['h1', 'h2'], $run->stepIds());
    }

    /**
     * Defaults for a minimal body
     *
     * @return void
     */
    public function testDefaults(): void {
        $run = RunRequest::parse(static::body(), null);

        $this->assertSame([], $run->events);
        $this->assertSame([], $run->steps);
        $this->assertSame(2, $run->version);
        $this->assertTrue($run->sdk_decided);
        $this->assertSame('', $run->run_id);
        $this->assertNull($run->fn_id);
        $this->assertSame(0, $run->attempt);
        $this->assertNull($run->max_attempts);
        $this->assertFalse($run->disable_immediate_execution);
        $this->assertFalse($run->use_api);
        $this->assertNull($run->qi_id);
        $this->assertSame([], $run->stack);
    }

    /**
     * Without a stack, the stack is the step IDs in state order
     *
     * @param array<string, mixed>|null $stack `ctx.stack`
     *
     * @return void
     */
    #[DataProvider('emptyStacks')]
    public function testDefaultStack(?array $stack): void {
        $run = RunRequest::parse(static::body([
            'steps' => ['b' => 1, 'a' => 2],
            'ctx'   => ['run_id' => 'r', 'stack' => $stack],
        ]), null);

        $this->assertSame(['b', 'a'], $run->stack);
    }

    /**
     * Stacks that count as empty
     *
     * @return array<string, array{array<string, mixed>|null}>
     */
    public static function emptyStacks(): array {
        return [
            'null'       => [null],
            'null list'  => [['stack' => null, 'current' => 0]],
            'empty list' => [['stack' => [], 'current' => 0]],
        ];
    }

    /**
     * Version selection
     *
     * @param string|null $header      Header value
     * @param mixed       $body        Body `version` (absent when 'none')
     * @param int         $version     Expected version
     * @param bool        $sdk_decided Expected flag
     *
     * @return void
     */
    #[DataProvider('versions')]
    public function testVersion(
        ?string $header,
        mixed $body,
        int $version,
        bool $sdk_decided
    ): void {
        $fields = $body === 'none' ? [] : ['version' => $body];
        $run    = RunRequest::parse(static::body($fields), $header);

        $this->assertSame($version, $run->version);
        $this->assertSame($sdk_decided, $run->sdk_decided);
    }

    /**
     * Header and body versions and the result
     *
     * @return array<string, array<int, mixed>>
     */
    public static function versions(): array {
        return [
            'header 1'          => ['1', 2, 1, false],
            'header 2'          => ['2', 1, 2, false],
            'header 2.0'        => ['2.0', 1, 2, false],
            'header 0 ignored'  => ['0', 1, 1, false],
            'header -1 ignored' => ['-1', 1, 1, false],
            'header 3 ignored'  => ['3', 1, 1, false],
            'header text'       => ['abc', 1, 1, false],
            'body missing'      => [null, 'none', 2, true],
            'body null'         => [null, null, 2, true],
            'body -1'           => [null, -1, 2, true],
            'body 1'            => [null, 1, 1, false],
            'body 2'            => [null, 2, 2, false],
        ];
    }

    /**
     * Invalid bodies fail with a parse error
     *
     * @param string $body Raw body
     * @param string $why  Expected reason in the message
     *
     * @return void
     */
    #[DataProvider('invalidBodies')]
    public function testInvalid(string $body, string $why): void {
        try {
            RunRequest::parse($body, null);
            $this->fail('Expected an exception');
        } catch (ExecutionException $e) {
            $this->assertSame(7001, $e->getCode());
            $this->assertStringStartsWith(
                'Failed to parse data from executor',
                $e->getMessage()
            );
            $this->assertStringContainsString($why, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    /**
     * Invalid bodies and why
     *
     * @return array<string, array{string, string}>
     */
    public static function invalidBodies(): array {
        $event = '"event":{}';

        return [
            'not json'      => ['{', 'Syntax error'],
            'not an object' => ['[]', 'must be a JSON object'],
            'no event'      => ['{}', '"event" must be an object'],
            'event list'    => ['{"event":[1]}', '"event" must be an object'],
            'event string'  => ['{"event":"x"}', '"event" must be an object'],
            'bad event'     => ['{"event":{"data":5}}', 'Invalid value'],
            'version 0'     => [
                "{{$event},\"version\":0}",
                'V0 execution version is no longer supported',
            ],
            'version 3' => [
                "{{$event},\"version\":3}",
                'Invalid execution version',
            ],
            'version text' => [
                "{{$event},\"version\":\"2\"}",
                'Invalid execution version',
            ],
            'events object' => [
                "{{$event},\"events\":{}}",
                '"events" must be a list',
            ],
            'events item' => [
                "{{$event},\"events\":[1]}",
                '"events" must be an object',
            ],
            'steps list' => [
                "{{$event},\"steps\":[]}",
                '"steps" must be an object',
            ],
            'steps null' => [
                "{{$event},\"steps\":null}",
                '"steps" must be an object',
            ],
            'ctx list' => [
                "{{$event},\"ctx\":[]}",
                '"ctx" must be an object',
            ],
            'no run id' => [
                "{{$event},\"ctx\":{}}",
                '"ctx.run_id" must be a string',
            ],
            'attempt text' => [
                "{{$event},\"ctx\":{\"run_id\":\"r\",\"attempt\":\"1\"}}",
                '"ctx.attempt" must be a number',
            ],
            'fn id number' => [
                "{{$event},\"ctx\":{\"run_id\":\"r\",\"fn_id\":1}}",
                '"ctx.fn_id" must be a string',
            ],
            'use api text' => [
                "{{$event},\"ctx\":{\"run_id\":\"r\",\"use_api\":\"y\"}}",
                '"ctx.use_api" must be a boolean',
            ],
            'stack not object' => [
                "{{$event},\"ctx\":{\"run_id\":\"r\",\"stack\":[]}}",
                '"ctx.stack" must be {stack, current}',
            ],
            'stack no current' => [
                "{{$event},\"ctx\":{\"run_id\":\"r\",\"stack\":" .
                '{"stack":[]}}}',
                '"ctx.stack" must be {stack, current}',
            ],
            'stack no list' => [
                "{{$event},\"ctx\":{\"run_id\":\"r\",\"stack\":" .
                '{"current":0}}}',
                '"ctx.stack" must be {stack, current}',
            ],
            'stack ids not strings' => [
                "{{$event},\"ctx\":{\"run_id\":\"r\",\"stack\":" .
                '{"stack":[1],"current":0}}}',
                '"ctx.stack.stack" must be a list of strings',
            ],
            'stack ids object' => [
                "{{$event},\"ctx\":{\"run_id\":\"r\",\"stack\":" .
                '{"stack":{"a":"b"},"current":0}}}',
                '"ctx.stack.stack" must be a list of strings',
            ],
        ];
    }

    /**
     * Float numbers are accepted and huge limits are capped
     *
     * @return void
     */
    public function testNumbers(): void {
        $run = RunRequest::parse(
            '{"event":{},"ctx":{"run_id":"r","attempt":1.0,' .
            '"max_attempts":1e300,"stack":{"stack":["a"],"current":0.0}}}',
            null
        );

        $this->assertSame(1, $run->attempt);
        $this->assertSame(PHP_INT_MAX, $run->max_attempts);
        $this->assertSame(['a'], $run->stack);
    }

    /**
     * Without `use_api`, the API isn't called
     *
     * @return void
     */
    public function testWithApiDataUnused(): void {
        $http = new MockHttp();
        $run  = RunRequest::parse(static::body(), null);

        $this->assertSame($run, $run->withApiData(static::api($http)));
        $this->assertSame([], $http->requests);
    }

    /**
     * With `use_api`, events and steps come from the API
     *
     * @return void
     */
    public function testWithApiData(): void {
        $http = new MockHttp([
            MockHttp::json(200, [['name' => 'x'], []]),
            MockHttp::json(200, ['h1' => ['data' => 1], 'h2' => 'raw']),
        ]);
        $run = RunRequest::parse(static::body([
            'ctx' => ['run_id' => 'run 1', 'use_api' => true, 'attempt' => 3],
        ]), null)->withApiData(static::api($http));

        $this->assertSame(['x', ''], array_map(
            static fn (Event $event) => $event->name,
            $run->events
        ));
        $this->assertSame(1, $run->steps['h1']->data);
        $this->assertSame('raw', $run->steps['h2']->data);
        $this->assertSame(['h1', 'h2'], $run->stack);
        $this->assertSame(3, $run->attempt);
        $this->assertSame('demo/event', $run->event->name);
        $this->assertSame(
            '/v0/runs/run%201/batch',
            $http->requests[0]->getUri()->getPath()
        );
        $this->assertSame(
            '/v0/runs/run%201/actions',
            $http->requests[1]->getUri()->getPath()
        );
    }

    /**
     * API failures and a missing run ID fail with code 7002
     *
     * @param list<mixed> $responses API responses
     * @param string      $run_id    Run ID
     * @param string      $message   Expected message start
     *
     * @return void
     */
    #[DataProvider('apiFailures')]
    public function testWithApiDataFailure(
        array $responses,
        string $run_id,
        string $message
    ): void {
        $run = RunRequest::parse(static::body([
            'ctx' => ['run_id' => $run_id, 'use_api' => true],
        ]), null);

        try {
            $run->withApiData(static::api(new MockHttp($responses)));
            $this->fail('Expected an exception');
        } catch (ExecutionException $e) {
            $this->assertSame(7002, $e->getCode());
            $this->assertStringStartsWith($message, $e->getMessage());
        }
    }

    /**
     * Failing API setups
     *
     * @return array<string, array<int, mixed>>
     */
    public static function apiFailures(): array {
        return [
            'no run id' => [
                [],
                '',
                'Failed to attempt retrieving data from API',
            ],
            'batch fails' => [
                [MockHttp::json(500, ['error' => 'down'])],
                'r',
                'Failed to retrieve list of events; Function execution ' .
                "can't continue. down",
            ],
            'steps fail' => [
                [MockHttp::json(200, []), MockHttp::json(404, [])],
                'r',
                'Failed to retrieve steps for function run',
            ],
        ];
    }

    /**
     * Builds an API client on a mock
     *
     * @param MockHttp $http Mock HTTP
     *
     * @return ApiClient
     */
    protected static function api(MockHttp $http): ApiClient {
        return new ApiClient(
            new ClientConfig(
                app_id: 'app',
                mode: Mode::Dev,
                api_base_url: 'http://localhost:8288/',
                event_api_base_url: 'http://localhost:8288/'
            ),
            $http->client,
            new Headers(new FixedVersion())
        );
    }
}
