<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional\app;

use DealNews\Inngest\Client;
use DealNews\Inngest\Config\Checkpointing;
use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\FunctionReference;
use DealNews\Inngest\Functions\Trigger\CronTrigger;
use DealNews\Inngest\Functions\Trigger\EventTrigger;
use DealNews\Inngest\Middleware\DependencyInjection;
use DealNews\Inngest\Tests\functional\StateStore;

/**
 * Functions served by the dev-mode test app
 *
 * The first group are PHP twins of the JS SDK's `src/test/functions/*`
 * (same IDs and events, `Promise.all` → `parallel()`, `Promise.race` →
 * `race()`). The rest port scenarios from `src/test/integration/*`; those
 * write what they saw to the state store, keyed by run ID.
 *
 * @package DealNews\Inngest\Tests\functional\app
 */
class DevFunctions {

    /**
     * Team scores for the reduce functions
     *
     * @var array<string, int>
     */
    public const SCORES = [
        'blue'  => 50,
        'red'   => 25,
        'green' => 75,
    ];

    /**
     * Creates every function on the client
     *
     * @param Client     $client Client
     * @param StateStore $store  State store
     *
     * @return void
     */
    public static function register(Client $client, StateStore $store): void {
        static::registerExamples($client);
        static::registerSteps($client);
        static::registerDurations($client, $store);
        static::registerErrors($client, $store);
        static::registerCheckpointing($client, $store);
        static::registerMiddleware($client, $store);
    }

    /**
     * Suffix for the copy of a function that runs without checkpointing
     *
     * @var string
     */
    public const NO_CHECKPOINT_SUFFIX = '-nocp';

    /**
     * Creates a function twice: as given, and without checkpointing
     *
     * Both copies share the triggers, so one event runs both. This dev
     * server version leaves checkpointed steps out of run traces, so tests
     * check spans on the `-nocp` copy and outputs on both.
     *
     * @param Client          $client  Client
     * @param FunctionOptions $options Options (checkpointing unset)
     * @param callable        $handler Handler
     *
     * @return void
     */
    protected static function twin(
        Client $client,
        FunctionOptions $options,
        callable $handler
    ): void {
        $client->createFunction($options, $handler);

        $copy                = clone $options;
        $copy->id            = $options->id . self::NO_CHECKPOINT_SUFFIX;
        $copy->checkpointing = false;
        $client->createFunction($copy, $handler);
    }

    /**
     * Twins of the JS example functions (no steps or plain step.run)
     *
     * @param Client $client Client
     *
     * @return void
     */
    protected static function registerExamples(Client $client): void {
        static::twin(
            $client,
            new FunctionOptions(
                'hello-world',
                triggers: [new EventTrigger('demo/hello.world')]
            ),
            static fn () => 'Hello, Inngest!'
        );

        static::twin(
            $client,
            new FunctionOptions(
                'multiple-triggers',
                triggers: [
                    new EventTrigger('demo/multiple-triggers.1'),
                    new EventTrigger('demo/multiple-triggers.2'),
                ]
            ),
            static fn (Context $ctx) => "Hello, {$ctx->event->name}!"
        );

        static::twin(
            $client,
            new FunctionOptions(
                'sequential-reduce',
                triggers: [new EventTrigger('demo/sequential.reduce')]
            ),
            static function (Context $ctx): int {
                $total = 0;
                foreach (self::SCORES as $team => $score) {
                    $total += $ctx->step->run(
                        "Get $team team score",
                        static fn () => $score
                    );
                }

                return $total;
            }
        );

        static::twin(
            $client,
            new FunctionOptions(
                'parallel-reduce',
                triggers: [new EventTrigger('demo/parallel.reduce')]
            ),
            static function (Context $ctx): int {
                $branches = [];
                foreach (self::SCORES as $team => $score) {
                    $branches[] = static fn () => $ctx->step->run(
                        "Get $team team score",
                        static fn () => $score
                    );
                }

                return array_sum($ctx->step->parallel(...$branches));
            }
        );

        static::twin(
            $client,
            new FunctionOptions(
                'parallel-work',
                triggers: [new EventTrigger('demo/parallel.work')]
            ),
            static function (Context $ctx): array {
                $step = $ctx->step;

                $get_score = static function () use ($step): int {
                    $score  = $step->run('First score', static fn () => 1);
                    $score += $step->run('Second score', static fn () => 2);
                    $score += $step->run('Third score', static fn () => 3);

                    return $score;
                };

                $get_fruits = static fn () => implode(', ', $step->parallel(
                    static fn () => $step->run(
                        'Get apple',
                        static fn () => 'Apple'
                    ),
                    static fn () => $step->run(
                        'Get banana',
                        static fn () => 'Banana'
                    ),
                    static fn () => $step->run(
                        'Get orange',
                        static fn () => 'Orange'
                    ),
                ));

                return $step->parallel($get_score, $get_fruits);
            }
        );

        static::twin(
            $client,
            new FunctionOptions(
                'promise-all',
                triggers: [new EventTrigger('demo/promise.all')]
            ),
            static function (Context $ctx): mixed {
                $step    = $ctx->step;
                $results = $step->parallel(
                    static fn () => $step->run('Step 1', static fn () => 1),
                    static fn () => $step->run('Step 2', static fn () => 2),
                );

                return $step->run(
                    'Step 3',
                    static fn () => $results[0] + $results[1]
                );
            }
        );

        static::twin(
            $client,
            new FunctionOptions(
                'promise-race',
                triggers: [new EventTrigger('demo/promise.race')]
            ),
            static function (Context $ctx): void {
                $step   = $ctx->step;
                $winner = $step->race(
                    static fn () => $step->run('Step A', static fn () => 'A'),
                    static fn () => $step->run('Step B', static fn () => 'B'),
                );

                $step->run(
                    'Step C',
                    static fn () => "$winner is the winner!"
                );
            }
        );

        static::twin(
            $client,
            new FunctionOptions(
                'undefined-data',
                triggers: [new EventTrigger('demo/undefined.data')]
            ),
            static function (Context $ctx): void {
                $step = $ctx->step;
                $step->run('step1res', static fn () => 'step1res');
                $step->run('step1', static function (): void {
                });
                $step->parallel(
                    static fn () => $step->run(
                        'step2res',
                        static fn () => 'step2res'
                    ),
                    static fn () => $step->run(
                        'step2nores',
                        static function (): void {
                        }
                    ),
                    static fn () => $step->run(
                        'step2res2',
                        static fn () => 'step2res2'
                    ),
                );
                $step->run('step2', static function (): void {
                });
                $step->run('step3', static function (): void {
                });
            }
        );
    }

    /**
     * Functions for the other step tools
     *
     * @param Client $client Client
     *
     * @return void
     */
    protected static function registerSteps(Client $client): void {
        $client->createFunction(
            new FunctionOptions(
                'send-event',
                triggers: [new EventTrigger('demo/send.event')]
            ),
            static function (Context $ctx): void {
                // The marker lets the test find this run's events
                $data = [
                    'foo'    => 'bar',
                    'marker' => $ctx->event->data['marker'] ?? null,
                ];
                $ctx->step->parallel(
                    static fn () => $ctx->step->sendEvent('single-event', [
                        'name' => 'app/my.event.happened',
                        'data' => $data,
                    ]),
                    static fn () => $ctx->step->sendEvent('multiple-events', [
                        [
                            'name' => 'app/my.event.happened.multiple.1',
                            'data' => $data,
                        ],
                        [
                            'name' => 'app/my.event.happened.multiple.2',
                            'data' => $data,
                        ],
                    ]),
                );
            }
        );

        $cron_fn = $client->createFunction(
            new FunctionOptions(
                'step-invoke-cron',
                triggers: [new CronTrigger('59 23 31 12 *')]
            ),
            static function (Context $ctx): array {
                $ctx->step->sleep('wait-a-moment', '1s');

                return ['cronInvokeDone' => true];
            }
        );

        $event_fn = $client->createFunction(
            new FunctionOptions(
                'step-invoke-event',
                triggers: [new EventTrigger('demo/step.invoke.other')]
            ),
            static function (Context $ctx): array {
                $ctx->step->sleep('wait-a-moment', '1s');

                return ['eventInvokeDone' => true];
            }
        );

        $client->createFunction(
            new FunctionOptions(
                'step-invoke',
                triggers: [new EventTrigger('demo/step.invoke')]
            ),
            static fn (Context $ctx) => $ctx->step->parallel(
                static fn () => $ctx->step->invoke('event-fn', $event_fn),
                static fn () => $ctx->step->invoke('cron-fn', $cron_fn),
            )
        );

        $client->createFunction(
            new FunctionOptions(
                'step-invoke-not-found',
                triggers: [new EventTrigger('demo/step.invoke.not-found')]
            ),
            static function (Context $ctx): void {
                $ctx->step->invoke(
                    'invoke-non-existent-fn',
                    new FunctionReference('non-existant-fn')
                );
            }
        );

        $client->createFunction(
            new FunctionOptions(
                'sleep-wait',
                triggers: [new EventTrigger('demo/sleep.wait')]
            ),
            static function (Context $ctx): array {
                $ctx->step->sleep('nap', '1s');
                $ctx->step->sleepUntil(
                    'nap-until',
                    new \DateTimeImmutable('+2 seconds')
                );
                $resumed = $ctx->step->waitForEvent(
                    'wait-resume',
                    'demo/sleep.wait.resume',
                    '1m',
                    match: 'data.id'
                );
                $timed_out = $ctx->step->waitForEvent(
                    'wait-timeout',
                    'demo/sleep.wait.never',
                    '1s'
                );

                return [
                    'resumed'   => $resumed['data'] ?? null,
                    'timed_out' => $timed_out === null,
                ];
            }
        );
    }

    /**
     * Sub-second durations (JS integration/subSecondDurations.test.ts)
     *
     * Records a timestamp before and after each sleep on every request;
     * the test uses the first of each.
     *
     * @param Client     $client Client
     * @param StateStore $store  State store
     *
     * @return void
     */
    protected static function registerDurations(
        Client $client,
        StateStore $store
    ): void {
        foreach (['500' => 500, '1500' => 1500] as $suffix => $ms) {
            $client->createFunction(
                new FunctionOptions(
                    "sleep-ms-$suffix",
                    triggers: [new EventTrigger("demo/sleep.ms.$suffix")],
                    retries: 0
                ),
                static function (Context $ctx) use ($store, $ms): string {
                    $store->append($ctx->run_id, [
                        'before' => microtime(true),
                    ]);
                    $ctx->step->sleep('sleep', $ms);
                    $store->append($ctx->run_id, [
                        'after' => microtime(true),
                    ]);

                    return 'done';
                }
            );
        }

        $client->createFunction(
            new FunctionOptions(
                'wait-ms-500',
                triggers: [new EventTrigger('demo/wait.ms.500')],
                retries: 0
            ),
            static function (Context $ctx): array {
                $result = $ctx->step->waitForEvent(
                    'wait',
                    'demo/wait.ms.never',
                    500
                );

                return ['result' => $result];
            }
        );
    }

    /**
     * Error, retry, and failure-handler functions
     *
     * @param Client     $client Client
     * @param StateStore $store  State store
     *
     * @return void
     */
    protected static function registerErrors(
        Client $client,
        StateStore $store
    ): void {
        static::twin(
            $client,
            new FunctionOptions(
                'handling-step-errors',
                triggers: [new EventTrigger('demo/handling.step.errors')],
                retries: 1
            ),
            static function (Context $ctx): void {
                $step = $ctx->step;
                try {
                    $step->run('a', static function (): void {
                        throw new \Error(
                            'Oh no!',
                            0,
                            new \Error('This is the cause')
                        );
                    });
                } catch (\Throwable $e) {
                    $step->run(
                        'b',
                        static fn () => sprintf(
                            'err was: "%s" and the cause was: "%s"',
                            $e->getMessage(),
                            $e->getPrevious()?->getMessage()
                        )
                    );
                }

                $step->parallel(
                    static fn () => $step->run(
                        'c succeeds',
                        static fn () => 'c succeeds'
                    ),
                    static function () use ($step): mixed {
                        try {
                            $result = $step->run(
                                'd fails',
                                static function (): void {
                                    throw new \Error('D failed!');
                                }
                            );
                        } catch (\Throwable $e) {
                            $result = $step->run(
                                'e succeeds',
                                static fn () => [
                                    'errMessage' => $e->getMessage(),
                                ]
                            );
                        }

                        return $result;
                    },
                );
            }
        );

        static::twin(
            $client,
            new FunctionOptions(
                'unhandled-step-errors',
                triggers: [new EventTrigger('demo/unhandled.step.errors')],
                retries: 1
            ),
            static function (Context $ctx): void {
                $ctx->step->run('a fails', static function (): void {
                    throw new \Error('A failed!');
                });
                $ctx->step->run('b never runs', static fn () => 'b');
            }
        );

        // JS retry.test.ts, run with and without checkpointing
        foreach (['on' => true, 'off' => false] as $suffix => $checkpointing) {
            $client->createFunction(
                new FunctionOptions(
                    "retry-reset-$suffix",
                    triggers: [new EventTrigger("demo/retry.reset.$suffix")],
                    retries: 1,
                    checkpointing: $checkpointing
                ),
                static function (Context $ctx) use ($store): void {
                    $ctx->step->run(
                        'a',
                        static function () use ($ctx, $store): void {
                            $store->append($ctx->run_id, [
                                'step'    => 'a',
                                'attempt' => $ctx->attempt,
                            ]);
                            $seen = array_filter(
                                $store->read($ctx->run_id),
                                static fn ($e) => $e['step'] === 'a'
                            );
                            if (count($seen) === 1) {
                                throw new \Error('a: error');
                            }
                        }
                    );
                    $ctx->step->run(
                        'b',
                        static function () use ($ctx, $store): void {
                            $store->append($ctx->run_id, [
                                'step'    => 'b',
                                'attempt' => $ctx->attempt,
                            ]);

                            throw new \Error('b: error');
                        }
                    );
                }
            );
        }

        $client->createFunction(
            new FunctionOptions(
                'on-failure',
                triggers: [new EventTrigger('demo/on.failure')],
                retries: 0,
                on_failure: static function (Context $ctx) use ($store): void {
                    $original = $ctx->event->data['event'] ?? [];
                    $store->append($original['data']['marker'] ?? 'none', [
                        'event'          => $ctx->event->name,
                        'error_class'    => $ctx->error === null
                            ? null
                            : $ctx->error::class,
                        'error_name'     => $ctx->error?->getErrorName(),
                        'error_message'  => $ctx->error?->getMessage(),
                        'original_event' => $original['name']           ?? null,
                        'run_id'         => $ctx->event->data['run_id'] ?? null,
                    ]);
                }
            ),
            static function (): void {
                throw new \RuntimeException('test failure');
            }
        );
    }

    /**
     * Async checkpointing functions (JS integration/checkpointing/*)
     *
     * @param Client     $client Client
     * @param StateStore $store  State store
     *
     * @return void
     */
    protected static function registerCheckpointing(
        Client $client,
        StateStore $store
    ): void {
        $tiny_runtime = new Checkpointing(max_runtime: 1);

        $client->createFunction(
            new FunctionOptions(
                'cp-runtime-stepless',
                triggers: [new EventTrigger('demo/cp.runtime.stepless')],
                retries: 0,
                checkpointing: $tiny_runtime
            ),
            static function (Context $ctx) use ($store): string {
                $store->append($ctx->run_id, 'enter');
                usleep(2_000_000);

                return 'done';
            }
        );

        $client->createFunction(
            new FunctionOptions(
                'cp-runtime-between',
                triggers: [new EventTrigger('demo/cp.runtime.between')],
                retries: 0,
                checkpointing: $tiny_runtime
            ),
            static function (Context $ctx) use ($store): string {
                $store->append($ctx->run_id, 'enter');
                $ctx->step->run('before', static function (): void {
                });
                usleep(2_000_000);
                $ctx->step->run('after', static function (): void {
                });

                return 'done';
            }
        );

        $client->createFunction(
            new FunctionOptions(
                'cp-runtime-after',
                triggers: [new EventTrigger('demo/cp.runtime.after')],
                retries: 0,
                checkpointing: $tiny_runtime
            ),
            static function (Context $ctx) use ($store): string {
                $store->append($ctx->run_id, 'enter');
                $ctx->step->run('before', static function (): void {
                });
                usleep(2_000_000);

                return 'done';
            }
        );

        $client->createFunction(
            new FunctionOptions(
                'cp-resume-parallel',
                triggers: [new EventTrigger('demo/cp.resume.parallel')],
                retries: 0,
                checkpointing: true
            ),
            static function (Context $ctx) use ($store): string {
                $step  = $ctx->step;
                $track = static function (string $name) use (
                    $ctx,
                    $store
                ): \Closure {
                    return static function () use ($ctx, $store, $name) {
                        $store->append($ctx->run_id, $name);

                        return $name;
                    };
                };

                $step->run('seq-1', $track('seq-1'));
                $step->run('seq-2', $track('seq-2'));
                $step->parallel(
                    static fn () => $step->run('par-1', $track('par-1')),
                    static fn () => $step->run('par-2', $track('par-2')),
                );
                $step->run('after-1', $track('after-1'));
                $step->run('after-2', $track('after-2'));
                $step->run('after-3', $track('after-3'));

                return 'done';
            }
        );

        $client->createFunction(
            new FunctionOptions(
                'cp-serialization',
                triggers: [new EventTrigger('demo/cp.serialization')],
                retries: 0
            ),
            static function (Context $ctx) use ($store): void {
                $store->append($ctx->run_id, 'request');
                $output = $ctx->step->run(
                    'date-step',
                    static fn () => [
                        'date'   => new IsoDate('2026-02-03'),
                        'list'   => [new IsoDate('2026-02-03')],
                        'nested' => ['date' => new IsoDate('2026-02-03')],
                    ]
                );
                $store->append($ctx->run_id, ['output' => $output]);
            }
        );
    }

    /**
     * Middleware and logger functions (JS integration/middleware/*,
     * integration/logger.test.ts)
     *
     * @param Client     $client Client
     * @param StateStore $store  State store
     *
     * @return void
     */
    protected static function registerMiddleware(
        Client $client,
        StateStore $store
    ): void {
        $client->createFunction(
            new FunctionOptions(
                'mw-lifecycle',
                triggers: [new EventTrigger('demo/mw.lifecycle')],
                retries: 0,
                // One request per step, so instances can't be shared
                checkpointing: false,
                middleware: [
                    static fn (Client $client) => new LifecycleMiddleware(
                        $client,
                        $store
                    ),
                ]
            ),
            static function (Context $ctx): string {
                $ctx->step->run('step-1', static fn () => 'one');
                $ctx->step->run('step-2', static fn () => 'two');

                return 'done';
            }
        );

        $client->createFunction(
            new FunctionOptions(
                'mw-di',
                triggers: [new EventTrigger('demo/mw.di')],
                retries: 0,
                middleware: [
                    static fn (Client $client) => new DependencyInjection(
                        $client,
                        ['greeting' => 'hello from DI']
                    ),
                ]
            ),
            static fn (Context $ctx) => $ctx->step->run(
                'use-it',
                static fn () => $ctx->extras['greeting'] ?? null
            )
        );

        foreach (['on' => true, 'off' => false] as $suffix => $checkpointing) {
            $client->createFunction(
                new FunctionOptions(
                    "logger-$suffix",
                    triggers: [new EventTrigger("demo/logger.$suffix")],
                    retries: 0,
                    checkpointing: $checkpointing
                ),
                static function (Context $ctx): string {
                    $ctx->logger->info('before');
                    $ctx->step->run('step-a', static fn () => 'a');
                    $ctx->logger->info('between');
                    $ctx->step->run('step-b', static fn () => 'b');
                    $ctx->step->sleep('zzz', '1s');
                    $ctx->logger->info('after');

                    return 'done';
                }
            );
        }
    }
}
