<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Api\SendSignalResult;
use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Context;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Error\NonRetriableError;
use DealNews\Inngest\Error\RemoteError;
use DealNews\Inngest\Error\RetryAfterError;
use DealNews\Inngest\Error\SerializedError;
use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Event\EventMeta;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\ExecutionException;
use DealNews\Inngest\Execution\ExecutionResult;
use DealNews\Inngest\Execution\FoundStep;
use DealNews\Inngest\Execution\OutgoingOp;
use DealNews\Inngest\Execution\ParallelGroup;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Execution\StepIdResolver;
use DealNews\Inngest\Execution\StepOp;
use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Functions\Config\FunctionConfig;
use DealNews\Inngest\Functions\Config\StepConfig;
use DealNews\Inngest\Functions\Config\StepRetries;
use DealNews\Inngest\Functions\Config\StepRuntime;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\FunctionReference;
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
use DealNews\Inngest\Step\AiFormat;
use DealNews\Inngest\Step\AiTools;
use DealNews\Inngest\Step\ParallelMode;
use DealNews\Inngest\Step\SignalConflict;
use DealNews\Inngest\Step\StepOptions;
use DealNews\Inngest\Step\StepTools;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\MockHttp;
use DealNews\Inngest\Tests\mock\ServeHarness;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Compares function execution with the real JS SDK
 *
 * tests/fixtures/execution/js-responses.json comes from sending POST
 * requests to the JS `InngestCommHandler` (see generate.mts there). Each
 * case sends the same request to the PHP serve handler, with the same
 * functions written in PHP, and compares status, headers, and body.
 *
 * Differences mapped before comparing: SDK identity headers, error stacks,
 * step timing values, and the parse-failure error (PHP's
 * `ExecutionException` with its own message text).
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(Engine::class)]
#[CoversClass(RunRequest::class)]
#[CoversClass(ServeHandler::class)]
#[UsesClass(AiTools::class)]
#[UsesClass(ApiClient::class)]
#[UsesClass(Client::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(Context::class)]
#[UsesClass(Duration::class)]
#[UsesClass(Env::class)]
#[UsesClass(ErrorSerializer::class)]
#[UsesClass(Event::class)]
#[UsesClass(EventMeta::class)]
#[UsesClass(EventTrigger::class)]
#[UsesClass(ExecutionException::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(FoundStep::class)]
#[UsesClass(FunctionConfig::class)]
#[UsesClass(FunctionOptions::class)]
#[UsesClass(FunctionReference::class)]
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
#[UsesClass(RetryAfterError::class)]
#[UsesClass(SendEventResult::class)]
#[UsesClass(SendSignalResult::class)]
#[UsesClass(SerializedError::class)]
#[UsesClass(Signature::class)]
#[UsesClass(SignatureResult::class)]
#[UsesClass(StepConfig::class)]
#[UsesClass(StepError::class)]
#[UsesClass(StepIdResolver::class)]
#[UsesClass(StepOp::class)]
#[UsesClass(StepOptions::class)]
#[UsesClass(StepRetries::class)]
#[UsesClass(StepRuntime::class)]
#[UsesClass(StepState::class)]
#[UsesClass(StepTools::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(Version::class)]
class EngineParityTest extends TestCase {

    /**
     * SDK identifier the harness reports
     *
     * @var string
     */
    public const SDK = 'dealnews/inngest-php-sdk:v1.2.3';

    /**
     * Fixture file
     *
     * @var string
     */
    public const FIXTURE = 'execution/js-responses.json';

    /**
     * Adds the PHP versions of the JS fixture functions
     *
     * @param Client $client Client
     *
     * @return void
     */
    public static function addFunctions(Client $client): void {
        $trigger = [new EventTrigger('demo/event.sent')];
        $add     = static function (
            string $id,
            callable $handler,
            ?\Closure $on_failure = null
        ) use ($client, $trigger): void {
            $client->createFunction(
                new FunctionOptions(
                    id: $id,
                    triggers: $trigger,
                    on_failure: $on_failure
                ),
                $handler
            );
        };

        $add('no-steps', static fn (Context $ctx) => [
            'ok'    => true,
            'n'     => $ctx->event->data['n'],
            'count' => count($ctx->events),
        ]);
        $add('returns-undefined', static fn () => null);
        $add('throws', static function (): void {
            throw new \Error('boom');
        });
        $add('throws-non-retriable', static function (): void {
            throw new NonRetriableError('nope');
        });
        $add('throws-retry-after', static function (): void {
            throw new RetryAfterError('later', 5000);
        });
        $add('a-to-b', static function (Context $ctx): void {
            $ctx->step->run('A', static fn () => 'A');
            $ctx->step->run('B', static fn () => 'B');
        });
        $add('returns-step-data', static function (Context $ctx): array {
            $a = $ctx->step->run('A', static fn () => [
                'x'      => 1,
                'nested' => ['y' => [1, 2]],
            ]);
            $b = $ctx->step->run('B', static function (): void {
            });

            return ['a' => $a, 'b' => $b];
        });
        $add('step-throws', static function (Context $ctx): void {
            $ctx->step->run('A', static function (): void {
                throw new \Error('step failed');
            });
        });
        $add('step-non-retriable', static function (Context $ctx): void {
            $ctx->step->run('A', static function (): void {
                throw new NonRetriableError('bad step');
            });
        });
        $add('step-retry-after', static function (Context $ctx): void {
            $ctx->step->run('A', static function (): void {
                throw new RetryAfterError('slow down', 2500);
            });
        });
        $add('catches-step-error', static function (Context $ctx): string {
            try {
                $ctx->step->run('A', static fn () => 'never');
            } catch (StepError $e) {
                return 'caught: ' . $e->getMessage();
            }

            return 'not caught';
        });
        $add('rethrows-other-error', static function (Context $ctx): void {
            try {
                $ctx->step->run('A', static fn () => 'never');
            } catch (StepError) {
                throw new \Error('different');
            }
        });
        $add('input', static fn (Context $ctx) => $ctx->step->run(
            'A',
            static fn (int $a, int $b) => $a + $b,
            1,
            2
        ));
        $add('duplicate-ids', static function (Context $ctx): array {
            $results = [];
            for ($i = 0; $i < 3; $i++) {
                $results[] = $ctx->step->run('A', static fn () => $i);
            }

            return $results;
        });
        $add('named-step', static fn (Context $ctx) => $ctx->step->run(
            new StepOptions('A', 'Pretty A'),
            static fn () => 'A'
        ));
        $add(
            'with-failure',
            static fn () => 'main',
            static function (Context $ctx): string {
                /** @var RemoteError $error */
                $error = $ctx->error;

                return 'handled: ' . $error->getMessage() . ' (' .
                       $error->getErrorName() . ') for ' .
                       $ctx->event->data['function_id'];
            }
        );
        static::addToolFunctions($client, $add);
        static::addParallelFunctions($add);
    }

    /**
     * Adds the step tool functions (sleep, waits, invoke, sends, fetch, AI)
     *
     * @param Client   $client Client
     * @param \Closure $add    Adds a function by ID and handler
     *
     * @return void
     */
    protected static function addToolFunctions(
        Client $client,
        \Closure $add
    ): void {
        $until  = new \DateTimeImmutable('2030-01-02T03:04:05.678Z');
        $target = $client->createFunction(
            new FunctionOptions(
                id: 'invoke-target',
                triggers: [new EventTrigger('demo/event.sent')]
            ),
            static fn () => 'target'
        );

        $add('sleeps', static function (Context $ctx) use ($until): string {
            $ctx->step->sleep('S', '1h');
            $ctx->step->sleepUntil('U', $until);

            return 'slept';
        });
        $add('sleep-variants', static function (Context $ctx): void {
            $ctx->step->sleep('A', 500);
            $ctx->step->sleep(new StepOptions('B', 'Nap'), 90 * 60000);
            $ctx->step->sleepUntil('C', '2030-01-02T03:04:05Z');
        });
        $add('waits', static fn (Context $ctx) => [
            'evt' => $ctx->step->waitForEvent(
                'W',
                event: 'demo/other',
                timeout: '1d',
                match: 'data.id'
            ),
        ]);
        $add('waits-if', static fn (Context $ctx) => $ctx->step->waitForEvent(
            new StepOptions('W', 'Wait'),
            event: 'demo/other',
            timeout: $until,
            if: 'async.data.n > 1'
        ));
        $add('invokes', static fn (Context $ctx) => $ctx->step->invoke(
            'I',
            $target,
            data: ['x' => 1]
        ));
        $add('invokes-ref', static fn (Context $ctx) => $ctx->step->invoke(
            'I',
            new FunctionReference('other'),
            v: '2',
            meta: new EventMeta(['conv' => 1]),
            timeout: '1h'
        ));
        $add(
            'invokes-ref-app',
            static fn (Context $ctx) => $ctx->step->invoke(
                new StepOptions('I', 'Call other'),
                new FunctionReference('other', 'other-app'),
                data: [],
                timeout: 60000
            )
        );
        $add('sends', static fn (Context $ctx) => $ctx->step->sendEvent(
            'E',
            new Event('demo/sent', ['a' => 1])
        ));
        $add('sends-named', static fn (Context $ctx) => $ctx->step->sendEvent(
            new StepOptions('E', 'Send it'),
            [
                ['name' => 'demo/sent', 'data' => ['a' => 1]],
                ['name' => 'demo/sent', 'data' => ['a' => 2]],
            ]
        ));
        $add('signals', static fn (Context $ctx) => $ctx->step->sendSignal(
            'SS',
            'sig-1',
            ['ok' => true]
        ));
        $add(
            'signals-missing',
            static fn (Context $ctx) => $ctx->step->sendSignal('SS', 'missing')
        );
        $add(
            'waits-signal',
            static fn (Context $ctx) => $ctx->step->waitForSignal(
                'WS',
                'sig-1',
                '2h',
                SignalConflict::Replace
            )
        );
        $add('fetches', static function (Context $ctx): array {
            $response = $ctx->step->fetch(
                'https://Example.com/path?q=1',
                'POST',
                ['X-Token' => 'abc'],
                '{"a":1}'
            );

            return [
                'status' => $response->getStatusCode(),
                'body'   => (string)$response->getBody(),
                'header' => $response->getHeaderLine('x-r'),
            ];
        });
        $add('fetches-get', static function (Context $ctx): void {
            $ctx->step->fetch('https://example.com/');
            $ctx->step->fetch('https://example.com/again');
        });
        $add('infers', static fn (Context $ctx) => $ctx->step->ai->infer(
            'AI',
            url: 'https://ai.example.com/v1/chat',
            format: AiFormat::OpenAiChat,
            body: ['model' => 'm', 'messages' => []],
            auth_key: 'sk-1',
            headers: ['x-a' => '1']
        ));
        $add('wraps', static fn (Context $ctx) => $ctx->step->ai->wrap(
            'AW',
            static fn (string $p) => strtoupper($p),
            'hi'
        ));
    }

    /**
     * Adds the parallel functions (JS `Promise.all`, `group.parallel` race)
     *
     * @param \Closure $add Adds a function by ID and handler
     *
     * @return void
     */
    protected static function addParallelFunctions(\Closure $add): void {
        $add('all', static function (Context $ctx): array {
            $ab = $ctx->step->parallel(
                static fn () => $ctx->step->run('A', static fn () => 'A'),
                static fn () => $ctx->step->run('B', static fn () => 'B'),
            );
            $c = $ctx->step->run('C', static fn () => 'C');

            return ['ab' => $ab, 'c' => $c];
        });
        $add('race', static function (Context $ctx): string {
            $winner = $ctx->step->race(
                static fn () => $ctx->step->run('A', static fn () => 'A'),
                static fn () => $ctx->step->run('B', static fn () => 'B'),
            );
            if ($winner === 'A') {
                $ctx->step->run('A wins', static fn () => 'A wins');
            } elseif ($winner === 'B') {
                $ctx->step->run('B wins', static fn () => 'B wins');
            }

            return $winner;
        });
        $add('deep-race', static function (Context $ctx): void {
            $winner = $ctx->step->race(
                static fn () => $ctx->step->run('A', static fn () => 'A'),
                static function () use ($ctx) {
                    $ctx->step->run('B', static fn () => 'B');

                    return $ctx->step->run('B2', static fn () => 'B2');
                },
            );
            if ($winner === 'A') {
                $ctx->step->run('A wins', static fn () => 'A wins');
            } elseif ($winner === 'B2') {
                $ctx->step->run('B wins', static fn () => 'B wins');
            }
        });
        $add('parallel-work', static fn (Context $ctx) => $ctx->step->parallel(
            static function () use ($ctx): int {
                $score  = $ctx->step->run('First score', static fn () => 1);
                $score += $ctx->step->run('Second score', static fn () => 2);
                $score += $ctx->step->run('Third score', static fn () => 3);

                return $score;
            },
            static fn () => implode(', ', $ctx->step->parallel(
                static fn () => $ctx->step->run(
                    'Get apple',
                    static fn () => 'Apple'
                ),
                static fn () => $ctx->step->run(
                    'Get banana',
                    static fn () => 'Banana'
                ),
                static fn () => $ctx->step->run(
                    'Get orange',
                    static fn () => 'Orange'
                ),
            )),
        ));
        $add('parallel-reduce', static function (Context $ctx): int {
            $scores   = ['blue' => 50, 'red' => 25, 'green' => 75];
            $branches = [];
            foreach ($scores as $team => $score) {
                $branches[] = static fn () => $ctx->step->run(
                    "Get $team team score",
                    static fn () => $score
                );
            }

            return array_sum($ctx->step->parallel(...$branches));
        });
        $add('indexing', static fn (Context $ctx) => $ctx->step->parallel(
            static fn () => $ctx->step->run('A', static fn () => 'A'),
            static fn () => $ctx->step->run('A', static fn () => 'B'),
            static fn () => $ctx->step->run('A', static fn () => 'C'),
        ));
        $add('indexing-separated', static function (Context $ctx): string {
            $ctx->step->parallel(
                static fn () => $ctx->step->run('A', static fn () => 'A'),
                static fn () => $ctx->step->run('A', static fn () => 'B'),
            );

            return $ctx->step->run('A', static fn () => 'C');
        });
        $add('silent-error', static fn (Context $ctx) => $ctx->step->parallel(
            static fn () => $ctx->step->run('A', static fn () => 'A'),
            static function () use ($ctx) {
                try {
                    return $ctx->step->run('B', static function (): void {
                        throw new \Error('B failed message');
                    });
                } catch (StepError) {
                    return $ctx->step->run(
                        'B failed',
                        static fn () => 'B failed'
                    );
                }
            },
        ));
        $add('explicit-mode', static fn (Context $ctx) => $ctx->step->parallel(
            static fn () => $ctx->step->run(
                new StepOptions('a', parallel_mode: ParallelMode::Race),
                static fn () => 'a'
            ),
            static fn () => $ctx->step->run(
                new StepOptions('b', parallel_mode: ParallelMode::Race),
                static fn () => 'b'
            ),
        ));
        $add('race-mixed', static fn (Context $ctx) => $ctx->step->race(
            static fn () => $ctx->step->run('work', static fn () => 'done'),
            static fn () => $ctx->step->sleep('timeout', '10s'),
            static fn () => $ctx->step->waitForEvent(
                'external',
                event: 'external/event',
                timeout: '1h'
            ),
        ));
        $add('outside-group', static function (Context $ctx): void {
            $ctx->step->parallel(
                static fn () => $ctx->step->run(
                    'outside',
                    static fn () => 'outside'
                ),
                static fn () => $ctx->step->race(
                    static fn () => $ctx->step->run(
                        'inside-a',
                        static fn () => 'a'
                    ),
                    static fn () => $ctx->step->run(
                        'inside-b',
                        static fn () => 'b'
                    ),
                ),
            );
        });
        $add('many-steps', static function (Context $ctx): void {
            $branches = [];
            for ($i = 0; $i < 30; $i++) {
                $id         = "step-$i";
                $branches[] = static fn () => $ctx->step->run(
                    $id,
                    static fn () => $id
                );
            }
            $ctx->step->parallel(...$branches);
        });
        $add('branch-throws', static fn (Context $ctx) => $ctx->step->parallel(
            static function (): void {
                throw new \Error('branch broke');
            },
            static fn () => $ctx->step->run('A', static fn () => 'A'),
        ));
        $add(
            'no-step-branches',
            static fn (Context $ctx) => $ctx->step->parallel(
                static fn () => 1,
                static fn () => 2,
            )
        );
        $add('race-error', static function (Context $ctx): mixed {
            try {
                return $ctx->step->race(
                    static fn () => $ctx->step->run('A', static fn () => 'A'),
                    static fn () => $ctx->step->run('B', static fn () => 'B'),
                );
            } catch (StepError $e) {
                return 'caught: ' . $e->getMessage();
            }
        });
    }

    /**
     * Fixture case names
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
     * Status, headers, and body match the JS SDK
     *
     * @param string $name Case name
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testMatchesJs(string $name): void {
        $expected = $this->fixture()->$name;
        $request  = $expected->request;

        $responses = match ($name) {
            'use_api' => [
                MockHttp::json(200, [
                    ['name' => 'demo/event.sent', 'data' => ['n' => 1]],
                    ['name' => 'demo/event.sent', 'data' => ['n' => 2]],
                ]),
                MockHttp::json(200, [sha1('A') => 'from api']),
            ],
            'send_first', 'send_requested', 'send_input_replay' => [
                MockHttp::json(200, ['ids' => ['sent-1'], 'status' => 200]),
            ],
            'signal_first'   => [
                MockHttp::json(200, ['data' => ['run_id' => 'run-2']]),
            ],
            'signal_missing' => [MockHttp::json(404, new \stdClass())],
            default          => [],
        };

        $harness = new ServeHarness(
            new ClientOptions(is_dev: true),
            $responses,
            static fn (Client $client) => static::addFunctions($client)
        );

        $url = 'http://localhost:3000/api/inngest?' . http_build_query([
            'fnId'   => 'test-app-' . $request->fnId,
            'stepId' => $request->stepId,
        ]);
        $response = $harness->request(
            'POST',
            $url,
            array_merge(
                ['content-type' => 'application/json'],
                (array)$request->headers
            ),
            $request->body
        );

        $this->assertSame($expected->status, $response->status, 'status');
        $this->assertEquals(
            static::jsHeaders($expected->headers),
            static::phpHeaders($response->headers),
            'headers'
        );
        $this->assertJsonDataSame(
            static::encode(static::normalize($expected->body, true)),
            static::encode(static::normalize(
                json_decode($response->body, false),
                false
            )),
            'body'
        );

        $paths = array_map(
            static fn ($r) => $r->getUri()->getPath(),
            $harness->http->requests
        );
        $this->assertSame($expected->apiCalls, $paths, 'API calls');
    }

    /**
     * Loads the fixture, keeping `{}` vs `[]`
     *
     * @return \stdClass
     */
    protected function fixture(): \stdClass {
        return json_decode(
            $this->loadFixture(self::FIXTURE),
            false,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Normalizes a body for comparison
     *
     * @param mixed $value Decoded body (objects as stdClass)
     * @param bool  $js    Whether it came from JS
     *
     * @return mixed
     */
    protected static function normalize(mixed $value, bool $js): mixed {
        $result = $value;

        if ($value instanceof \stdClass) {
            $result = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                if ($key === 'stack' && is_string($item) && $item !== '') {
                    continue;
                }
                if ($key === 'code' && !$js) {
                    continue;
                }
                if ($key === 'timing') {
                    $item = '<timing>';
                } elseif (
                    $key === 'message' &&
                    is_string($item)   &&
                    str_starts_with($item, 'Failed to parse data from executor')
                ) {
                    $item = '<parse failure>';
                } elseif (
                    $key  === 'name' &&
                    $item === 'ExecutionException'
                ) {
                    $item = 'Error';
                } else {
                    $item = static::normalize($item, $js);
                }

                $result->$key = $item;
            }
        } elseif (is_array($value)) {
            $result = array_map(
                static fn ($item) => static::normalize($item, $js),
                $value
            );
        }

        return $result;
    }

    /**
     * Normalizes JS response headers
     *
     * @param \stdClass $headers Headers
     *
     * @return array<string, string>
     */
    protected static function jsHeaders(\stdClass $headers): array {
        $result = [];
        foreach (get_object_vars($headers) as $name => $value) {
            $result[$name] = match ($name) {
                'user-agent', Headers::SDK_VERSION => self::SDK,
                default                            => $value,
            };
        }
        unset($result['x-inngest-framework']);
        ksort($result);

        return $result;
    }

    /**
     * Sorts PHP response headers
     *
     * @param array<string, string> $headers Headers
     *
     * @return array<string, string>
     */
    protected static function phpHeaders(array $headers): array {
        ksort($headers);

        return $headers;
    }

    /**
     * Encodes data for assertJsonDataSame()
     *
     * @param mixed $data Data
     *
     * @return string
     */
    protected static function encode(mixed $data): string {
        return (string)json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}
