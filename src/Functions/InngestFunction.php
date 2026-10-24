<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions;

use DealNews\Inngest\Functions\Config\CancelConfig;
use DealNews\Inngest\Functions\Config\FunctionConfig;
use DealNews\Inngest\Functions\Config\StepConfig;
use DealNews\Inngest\Functions\Config\StepRetries;
use DealNews\Inngest\Functions\Config\StepRuntime;
use DealNews\Inngest\Functions\Trigger\EventTrigger;
use DealNews\Inngest\Functions\Trigger\Trigger;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Support\Url;

/**
 * A function Inngest can run: options plus the handler
 *
 * Port of the JS `InngestFunction` (components/InngestFunction.ts). Create
 * these with `Client::createFunction()`.
 *
 * Edge cases:
 *
 * - A function with `on_failure` registers two configs: the function and
 *   a `<id>-failure` companion triggered by `inngest/function.failed`.
 * - `inngest/function.invoked` triggers are left out of the config; they'd
 *   make the function run on every invoke.
 *
 * @package DealNews\Inngest\Functions
 */
class InngestFunction {

    /**
     * ID and name of the single step every function registers
     *
     * @var string
     */
    public const STEP_ID = 'step';

    /**
     * Suffix for the failure handler's function ID
     *
     * @var string
     */
    public const FAILURE_SUFFIX = '-failure';

    /**
     * Event sent when a function fails after all retries
     *
     * @var string
     */
    public const EVENT_FUNCTION_FAILED = 'inngest/function.failed';

    /**
     * Event used to invoke a function directly
     *
     * @var string
     */
    public const EVENT_FUNCTION_INVOKED = 'inngest/function.invoked';

    /**
     * The function's handler
     *
     * @var \Closure
     */
    protected \Closure $handler;

    /**
     * Creates the function
     *
     * @param string          $app_id  ID of the app (client) it belongs to
     * @param FunctionOptions $options Options
     * @param callable        $handler Handler, called with a Context
     */
    public function __construct(
        protected string $app_id,
        protected FunctionOptions $options,
        callable $handler
    ) {
        $this->handler = \Closure::fromCallable($handler);
    }

    /**
     * Returns the function ID, optionally prefixed (`<prefix>-<id>`)
     *
     * @param string|null $prefix Prefix, usually the app ID
     *
     * @return string
     */
    public function id(?string $prefix = null): string {
        return implode(
            '-',
            array_filter(
                [$prefix, $this->options->id],
                static fn ($part) => $part !== null && $part !== ''
            )
        );
    }

    /**
     * Returns the full ID used by Inngest: `<app id>-<function id>`
     *
     * @return string
     */
    public function absoluteId(): string {
        return $this->id($this->app_id);
    }

    /**
     * Returns the full ID of the failure handler
     *
     * @return string
     */
    public function failureId(): string {
        return $this->absoluteId() . self::FAILURE_SUFFIX;
    }

    /**
     * Returns the display name: the `name` option, else the unprefixed ID
     *
     * @return string
     */
    public function name(): string {
        return $this->options->name !== null && $this->options->name !== '' ?
            $this->options->name :
            $this->id();
    }

    /**
     * Returns the description, if any
     *
     * @return string|null
     */
    public function description(): ?string {
        return $this->options->description;
    }

    /**
     * Returns the options
     *
     * @return FunctionOptions
     */
    public function getOptions(): FunctionOptions {
        return $this->options;
    }

    /**
     * Returns the handler
     *
     * @return \Closure
     */
    public function getHandler(): \Closure {
        return $this->handler;
    }

    /**
     * Returns the failure handler, if any
     *
     * @return \Closure|null
     */
    public function getFailureHandler(): ?\Closure {
        return $this->options->on_failure;
    }

    /**
     * Returns the IDs this function registers (with the failure handler's)
     *
     * @return list<string>
     */
    public function configIds(): array {
        $ids = [$this->absoluteId()];
        if ($this->options->on_failure !== null) {
            $ids[] = $this->failureId();
        }

        return $ids;
    }

    /**
     * Builds the registration config(s) for this function
     *
     * Port of JS `getConfig()`.
     *
     * @param string        $base_url Serve endpoint URL
     * @param Duration|null $duration Converts cancellation timeouts
     *
     * @return list<FunctionConfig>
     */
    public function getConfigs(
        string $base_url,
        ?Duration $duration = null
    ): array {
        $duration ??= new Duration();
        $fn_id      = $this->absoluteId();
        $step_url   = Url::withQuery($base_url, [
            'fnId'   => $fn_id,
            'stepId' => self::STEP_ID,
        ]);
        $retries = $this->options->retries === null ?
            null :
            new StepRetries($this->options->retries);

        $config = new FunctionConfig(
            id: $fn_id,
            name: $this->name(),
            triggers: $this->configTriggers(),
            steps: [
                self::STEP_ID => new StepConfig(
                    runtime: new StepRuntime(url: $step_url),
                    retries: $retries
                ),
            ],
            idempotency: $this->options->idempotency,
            batch_events: $this->options->batch_events,
            rate_limit: $this->options->rate_limit,
            throttle: $this->options->throttle,
            concurrency: $this->options->concurrency,
            debounce: $this->options->debounce,
            priority: $this->options->priority,
            timeouts: $this->options->timeouts,
            singleton: $this->options->singleton,
            cancel: $this->cancelConfigs($duration)
        );

        $configs = [$config];

        if ($this->options->on_failure !== null) {
            $configs[] = $this->failureConfig($step_url);
        }

        return $configs;
    }

    /**
     * Builds the trigger list, dropping invoke triggers
     *
     * @return list<Trigger>
     */
    protected function configTriggers(): array {
        $triggers = [];

        foreach ($this->options->triggers as $trigger) {
            if (
                $trigger instanceof EventTrigger &&
                $trigger->event === self::EVENT_FUNCTION_INVOKED
            ) {
                continue;
            }

            $triggers[] = $trigger;
        }

        return $triggers;
    }

    /**
     * Converts cancellations to their wire form
     *
     * `match` becomes `event.<match> == async.<match>` and wins over `if`.
     *
     * @param Duration $duration Converts timeouts
     *
     * @return list<CancelConfig>|null Null when there are none
     */
    protected function cancelConfigs(Duration $duration): ?array {
        $result = null;

        if ($this->options->cancel_on !== []) {
            $result = [];
            foreach ($this->options->cancel_on as $cancel) {
                $if = $cancel->if;
                if ($cancel->match !== null && $cancel->match !== '') {
                    $if = 'event.' . $cancel->match . ' == async.' .
                          $cancel->match;
                }

                $timeout = null;
                if ($cancel->timeout !== null && $cancel->timeout !== '') {
                    $timeout = $duration->toTimeString($cancel->timeout);
                }

                $result[] = new CancelConfig($cancel->event, $if, $timeout);
            }
        }

        return $result;
    }

    /**
     * Builds the failure handler's config
     *
     * @param string $step_url The main function's step URL
     *
     * @return FunctionConfig
     */
    protected function failureConfig(string $step_url): FunctionConfig {
        return new FunctionConfig(
            id: $this->failureId(),
            name: $this->name() . ' (failure)',
            triggers: [
                new EventTrigger(
                    self::EVENT_FUNCTION_FAILED,
                    "event.data.function_id == '" . $this->absoluteId() . "'"
                ),
            ],
            steps: [
                self::STEP_ID => new StepConfig(
                    runtime: new StepRuntime(
                        url: Url::withQuery(
                            $step_url,
                            ['fnId' => $this->failureId()]
                        )
                    ),
                    retries: new StepRetries(1)
                ),
            ]
        );
    }
}
