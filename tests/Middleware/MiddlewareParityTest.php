<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Middleware;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\NonRetriableError;
use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Execution\CheckpointConfig;
use DealNews\Inngest\Execution\Checkpointer;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\ExecutionResult;
use DealNews\Inngest\Execution\FoundStep;
use DealNews\Inngest\Execution\OutgoingOp;
use DealNews\Inngest\Execution\ParallelGroup;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepIdResolver;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Execution\StepResult;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Functions\Trigger\EventTrigger;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Http\Introspector;
use DealNews\Inngest\Http\Registrar;
use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Http\Response;
use DealNews\Inngest\Http\ServeHandler;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Http\SignatureResult;
use DealNews\Inngest\Middleware\FunctionInput;
use DealNews\Inngest\Middleware\LoggerMiddleware;
use DealNews\Inngest\Middleware\MiddlewareStack;
use DealNews\Inngest\Middleware\ProxyLogger;
use DealNews\Inngest\Middleware\RunArgs;
use DealNews\Inngest\Middleware\RunCompleteArgs;
use DealNews\Inngest\Middleware\RunErrorArgs;
use DealNews\Inngest\Middleware\SendEventInput;
use DealNews\Inngest\Middleware\StepArgs;
use DealNews\Inngest\Middleware\StepCompleteArgs;
use DealNews\Inngest\Middleware\StepErrorArgs;
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
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\MockHttp;
use DealNews\Inngest\Tests\mock\RecordingMiddleware;
use DealNews\Inngest\Tests\mock\ServeHarness;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Compares middleware hook calls with the real JS SDK
 *
 * tests/fixtures/middleware/js-hooks.json comes from sending POST requests
 * to the JS `InngestCommHandler` with recording middleware (see
 * generate.mts there). Each case sends the same request to the PHP serve
 * handler, with the same functions and RecordingMiddleware, and compares
 * the response, every hook call in order, what `$ctx->logger` and the
 * built-in logger middleware logged, and the events sent.
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(Engine::class)]
#[CoversClass(LoggerMiddleware::class)]
#[CoversClass(MiddlewareStack::class)]
#[CoversClass(ProxyLogger::class)]
#[UsesClass(AiTools::class)]
#[UsesClass(ApiClient::class)]
#[UsesClass(CheckpointConfig::class)]
#[UsesClass(Checkpointer::class)]
#[UsesClass(Client::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(Context::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Env::class)]
#[UsesClass(ErrorSerializer::class)]
#[UsesClass(Event::class)]
#[UsesClass(EventTrigger::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(FoundStep::class)]
#[UsesClass(FunctionInput::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(Headers::class)]
#[UsesClass(InngestFunction::class)]
#[UsesClass(Introspector::class)]
#[UsesClass(NonRetriableError::class)]
#[UsesClass(OutgoingOp::class)]
#[UsesClass(ParallelGroup::class)]
#[UsesClass(Registrar::class)]
#[UsesClass(RemoteError::class)]
#[UsesClass(Request::class)]
#[UsesClass(Response::class)]
#[UsesClass(Retry::class)]
#[UsesClass(RunArgs::class)]
#[UsesClass(RunCompleteArgs::class)]
#[UsesClass(RunErrorArgs::class)]
#[UsesClass(RunRequest::class)]
#[UsesClass(SendEventInput::class)]
#[UsesClass(SendEventResult::class)]
#[UsesClass(ServeHandler::class)]
#[UsesClass(Signature::class)]
#[UsesClass(SignatureResult::class)]
#[UsesClass(StepArgs::class)]
#[UsesClass(StepCompleteArgs::class)]
#[UsesClass(StepError::class)]
#[UsesClass(StepErrorArgs::class)]
#[UsesClass(StepIdResolver::class)]
#[UsesClass(StepInfo::class)]
#[UsesClass(StepInput::class)]
#[UsesClass(StepOp::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepResult::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(StepType::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(Version::class)]
#[UsesClass(WrapFunctionHandlerArgs::class)]
#[UsesClass(WrapRequestArgs::class)]
#[UsesClass(WrapSendEventArgs::class)]
#[UsesClass(WrapStepArgs::class)]
class MiddlewareParityTest extends TestCase {

    /**
     * Fixture file
     *
     * @var string
     */
    public const FIXTURE = 'middleware/js-hooks.json';

    /**
     * Messages compared from the logger (the JS fixture keeps only these)
     *
     * @var list<string>
     */
    public const LOGGED = [
        'top',
        'in A',
        'after A',
        'end',
        'Inngest step error',
        'Inngest function error',
    ];

    /**
     * Cases where JS interleaves parallel branches' hooks differently
     *
     * JS awaits between `transformStepInput` and `wrapStep`, so every
     * branch's `transformStepInput` runs before any branch's `wrapStep`.
     * PHP runs one branch's hooks together. These cases compare the hooks
     * outside steps in order, and each step's hooks in order.
     *
     * @var list<string>
     */
    public const BRANCH_ORDER_ONLY = ['parallel_first'];

    /**
     * Returns the case names
     *
     * @return array<string, array{string}>
     */
    public static function cases(): array {
        $fixture = json_decode(
            (string)file_get_contents(self::FIXTURE_DIR . '/' . self::FIXTURE),
            true
        );

        $cases = [];
        foreach (array_keys($fixture) as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * Each case calls the same hooks, in the same order, as JS
     *
     * @param string $name Case name
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testMatchesJs(string $name): void {
        $fixture  = json_decode(
            (string)file_get_contents(self::FIXTURE_DIR . '/' . self::FIXTURE),
            true
        );
        $expected = $fixture[$name];
        $request  = $expected['request'];
        $log      = new \ArrayObject();

        $replies = array_map(
            static fn () => MockHttp::json(
                200,
                ['ids' => ['sent-1'], 'status' => 200]
            ),
            range(1, 10)
        );

        $harness = new ServeHarness(
            new ClientOptions(
                is_dev: true,
                middleware: [RecordingMiddleware::factory($log, 'c')]
            ),
            $replies,
            static fn (Client $client) => static::addFunctions($client, $log)
        );

        $url      = 'http://localhost:3000/api/inngest?' . http_build_query([
            'fnId'   => 'test-app-' . $request['fnId'],
            'stepId' => $request['stepId'],
        ]);
        $response = $harness->request(
            'POST',
            $url,
            ['content-type' => 'application/json'],
            $request['body']
        );

        $this->assertSame($expected['status'], $response->status, 'status');
        $this->assertEquals(
            static::normalize($expected['body']),
            static::normalize(json_decode($response->body, true)),
            'body'
        );
        if (in_array($name, self::BRANCH_ORDER_ONLY, true)) {
            $this->assertEquals(
                static::byStep($expected['hooks']),
                static::byStep($log->getArrayCopy()),
                'hooks'
            );
        } else {
            $this->assertEquals(
                $expected['hooks'],
                $log->getArrayCopy(),
                'hooks'
            );
        }

        $logs = [];
        foreach ($harness->logger->records as $record) {
            if (in_array($record['message'], self::LOGGED, true)) {
                $logs[] = [$record['level'], $record['message']];
            }
        }
        $this->assertSame($expected['logs'], $logs, 'logs');

        $sent = [];
        foreach ($harness->http->requests as $outbound) {
            if (str_starts_with($outbound->getUri()->getPath(), '/e/')) {
                $body = json_decode((string)$outbound->getBody(), true);
                foreach ($body as $event) {
                    $sent[] = [
                        'name' => $event['name'],
                        'data' => $event['data'],
                    ];
                }
            }
        }
        $this->assertSame($expected['sent'], $sent, 'sent events');
    }

    /**
     * Adds the PHP versions of the JS fixture functions
     *
     * @param Client       $client Client
     * @param \ArrayObject $log    Hook log for function middleware
     *
     * @return void
     */
    public static function addFunctions(
        Client $client,
        \ArrayObject $log
    ): void {
        $add = static fn (
            string $id,
            \Closure $handler,
            array $middleware = []
        ): InngestFunction => $client->createFunction(
            new FunctionOptions(
                id: $id,
                triggers: [new EventTrigger('demo/event.sent')],
                middleware: $middleware
            ),
            $handler
        );

        $target = $add('invoke-target', static fn () => 'target');

        $add('hello', static fn () => 'hi');
        $add('two-steps', static fn (Context $ctx) => [
            $ctx->step->run('A', static fn () => 'a'),
            $ctx->step->run('B', static fn () => 'b'),
        ]);
        $add('with-input', static fn (Context $ctx) => $ctx->step->run(
            'A',
            static fn (int $x) => $x * 10,
            1
        ));
        $add('sleeps', static function (Context $ctx): string {
            $ctx->step->run('A', static fn () => 'a');
            $ctx->step->sleep('S', '1h');

            return 'done';
        });
        $add('step-fails', static function (Context $ctx): void {
            $ctx->step->run('A', static function (): void {
                throw new \Error('A broke');
            });
        });
        $add('catches', static function (Context $ctx): ?string {
            $result = null;
            try {
                $ctx->step->run('A', static fn () => 'x');
            } catch (\Throwable $e) {
                $result = 'caught ' . $e->getMessage();
            }

            return $result;
        });
        $add('throws', static function (): void {
            throw new \Error('boom');
        });
        $add('parallel', static fn (Context $ctx) => $ctx->step->parallel(
            static fn () => $ctx->step->run('A', static fn () => 'a'),
            static fn () => $ctx->step->run('B', static fn () => 'b'),
        ));
        $add(
            'fn-mw',
            static function (Context $ctx): string {
                $ctx->step->run('A', static fn () => 'a');
                $ctx->step->sendEvent(
                    'send',
                    ['name' => 'x/y', 'data' => ['a' => 1]]
                );

                return 'ok';
            },
            [RecordingMiddleware::factory($log, 'f')]
        );
        $add('tsi-run', static fn (Context $ctx) => $ctx->step->run(
            'A',
            static fn (int $n) => $n * 10,
            1
        ));
        $add('tsi-id', static fn (Context $ctx) => [
            $ctx->step->run('A', static fn () => 'a'),
            $ctx->step->run('B', static fn () => 'b'),
        ]);
        $add('tsi-kinds', static function (Context $ctx) use ($target): string {
            $ctx->step->sleep('S', '60s');
            $ctx->step->waitForEvent('W', event: 'x/never', timeout: '60s');
            $ctx->step->invoke('I', $target, data: ['value' => 1]);

            return 'ok';
        });
        $add('prepend', static fn (Context $ctx) => $ctx->step->run(
            'main',
            static fn () => 'main'
        ));
        $add('tfi', static fn (Context $ctx) => [
            'a'  => $ctx->step->run('A', static fn () => 'a'),
            'n'  => $ctx->event->data['n'],
            'db' => $ctx->extras['db'],
        ]);
        $add('wfh-out', static function (Context $ctx): string {
            $ctx->step->run('A', static fn () => 'a');

            return 'out';
        });
        $add('wfh-err', static function (): void {
            throw new \Error('raw');
        });
        $add('serde', static fn (Context $ctx) => $ctx->step->run(
            'A',
            static fn () => 'v'
        ));
        $add('ws-change-error', static function (Context $ctx): string {
            $result = '';
            try {
                $ctx->step->run('A', static function (): void {
                    throw new \Error('A broke');
                });
            } catch (\Throwable $e) {
                $result = 'caught ' . $e->getMessage();
            }

            return $result;
        });
        $add('ws-throws', static fn (Context $ctx) => $ctx->step->run(
            'A',
            static fn () => 'a'
        ));
        $add('ws-bookend', static function (Context $ctx): string {
            $ctx->step->sleep('S', '1h');

            return 'done';
        });
        $add('wfh-throws', static fn (Context $ctx) => $ctx->step->run(
            'A',
            static fn () => 'a'
        ));
        $add('logs', static function (Context $ctx): string {
            $logger = $ctx->logger;
            $logger->info('top');
            $ctx->step->run('A', static function () use ($logger): string {
                $logger->info('in A');

                return 'a';
            });
            $logger->info('after A');
            $ctx->step->run('B', static fn () => 'b');
            $logger->info('end');

            return 'ok';
        });
    }

    /**
     * Groups hook entries by the step they're about (`""` for none),
     * keeping their order within each group
     *
     * @param list<array<string, mixed>> $hooks Hook entries
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected static function byStep(array $hooks): array {
        $result = [];
        foreach ($hooks as $entry) {
            $step            = $entry['step']['id'] ?? $entry['id'] ?? '';
            $result[$step][] = $entry;
        }
        ksort($result);

        return $result;
    }

    /**
     * Drops values that legitimately differ (timing, stacks, PHP codes)
     *
     * @param mixed $value Decoded JSON
     *
     * @return mixed
     */
    protected static function normalize(mixed $value): mixed {
        $result = $value;

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if ($key === 'timing' || $key === 'stack' || $key === 'code') {
                    continue;
                }
                $result[$key] = static::normalize($item);
            }
        }

        return $result;
    }
}
