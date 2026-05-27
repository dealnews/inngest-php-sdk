<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

use DealNews\Inngest\Middleware\AbstractMiddleware;

/**
 * Represents an Inngest function
 */
class InngestFunction
{
    /** @var array<AbstractMiddleware> */
    protected array $middleware = [];

    /**
     * @param string $id Unique identifier for the function
     * @param callable $handler Function handler
     * @param array<TriggerInterface> $triggers Array of triggers
     * @param string|null $name Display name for the function
     * @param int $retries Number of retry attempts (default 3 retries = 4 total attempts)
     * @param array<Concurrency>|null $concurrency Optional concurrency limits (max 2)
     * @param Priority|null $priority Optional priority configuration for dynamic execution ordering
     * @param Debounce|null $debounce Optional debounce configuration to delay execution until events stop
     * @param RateLimit|null $rate_limit Optional rate limit to cap function runs per time period
     * @param Throttle|null $throttle Optional throttle to enqueue excess function runs over time period
     * @param Singleton|null $singleton Optional singleton to ensure only one run executes at a time
     * @param string|null $description Function description
     * @param string|null $idempotency Optional idempotency key expression
     * @param Timeouts|null $timeouts Optional timeout configuration for start and finish operations
     * @param BatchEvents|null $batch_events Optional batch event configuration
     * @param array<Cancel>|null $cancel Optional array of cancellation triggers
     * @param bool $checkpointing Whether to enable checkpointing for this function
     */
    public function __construct(
        protected string $id,
        protected $handler,
        protected array $triggers,
        protected ?string $name = null,
        protected int $retries = 3,
        protected ?array $concurrency = null,
        protected ?Priority $priority = null,
        protected ?Debounce $debounce = null,
        protected ?RateLimit $rate_limit = null,
        protected ?Throttle $throttle = null,
        protected ?Singleton $singleton = null,
        protected ?string $description = null,
        protected ?string $idempotency = null,
        protected ?Timeouts $timeouts = null,
        protected ?BatchEvents $batch_events = null,
        protected ?array $cancel = null,
        protected bool $checkpointing = false
    ) {
        if (empty($triggers)) {
            throw new \InvalidArgumentException('Function must have at least one trigger');
        }

        if ($concurrency !== null && count($concurrency) > 2) {
            throw new \InvalidArgumentException('Maximum of 2 concurrency options allowed');
        }

        if ($concurrency !== null) {
            foreach ($concurrency as $item) {
                if (!$item instanceof Concurrency) {
                    throw new \InvalidArgumentException('Concurrency array must contain only Concurrency instances');
                }
            }
        }

        if ($cancel !== null) {
            foreach ($cancel as $item) {
                if (!$item instanceof Cancel) {
                    throw new \InvalidArgumentException('Cancel array must contain only Cancel instances');
                }
            }
        }
    }

    public function addMiddleware(AbstractMiddleware $mw): void
    {
        $this->middleware[] = $mw;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<TriggerInterface>
     */
    public function getTriggers(): array
    {
        return $this->triggers;
    }

    public function getHandler(): callable
    {
        return $this->handler;
    }

    public function getRetries(): int
    {
        return $this->retries;
    }

    /**
     * Get concurrency configuration
     *
     * @return array<Concurrency>|null
     */
    public function getConcurrency(): ?array
    {
        return $this->concurrency;
    }

    /**
     * Get priority configuration
     *
     * @return Priority|null
     */
    public function getPriority(): ?Priority
    {
        return $this->priority;
    }

    /**
     * Get debounce configuration
     *
     * @return Debounce|null
     */
    public function getDebounce(): ?Debounce
    {
        return $this->debounce;
    }

    /**
     * Get rate limit configuration
     *
     * @return RateLimit|null
     */
    public function getRateLimit(): ?RateLimit
    {
        return $this->rate_limit;
    }

    /**
     * Get throttle configuration
     *
     * @return Throttle|null
     */
    public function getThrottle(): ?Throttle
    {
        return $this->throttle;
    }

    /**
     * Get singleton configuration
     *
     * @return Singleton|null
     */
    public function getSingleton(): ?Singleton
    {
        return $this->singleton;
    }

    public function isCheckpointing(): bool
    {
        return $this->checkpointing;
    }

    /**
     * Execute the function handler
     *
     * @return mixed
     */
    public function execute(FunctionContext $context): mixed
    {
        return ($this->handler)($context);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name ?? $this->id,
            'triggers' => array_map(fn($t) => $t->toArray(), $this->triggers),
            'steps' => [
                'step' => [
                    'id' => 'step',
                    'name' => 'step',
                    'runtime' => [
                        'type' => 'http',
                    ],
                    'retries' => [
                        'attempts' => $this->retries + 1,
                    ],
                ],
            ],
        ];

        if ($this->checkpointing) {
            $data['steps']['step']['checkpoint'] = ['enabled' => true];
        }

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if ($this->idempotency !== null) {
            $data['idempotency'] = $this->idempotency;
        }

        if ($this->concurrency !== null && count($this->concurrency) > 0) {
            $data['concurrency'] = array_map(fn($c) => $c->toArray(), $this->concurrency);
        }

        if ($this->priority !== null) {
            $data['priority'] = $this->priority->toArray();
        }

        if ($this->debounce !== null) {
            $data['debounce'] = $this->debounce->toArray();
        }

        if ($this->rate_limit !== null) {
            $data['rateLimit'] = $this->rate_limit->toArray();
        }

        if ($this->throttle !== null) {
            $data['throttle'] = $this->throttle->toArray();
        }

        if ($this->singleton !== null) {
            $data['singleton'] = $this->singleton->toArray();
        }

        if ($this->timeouts !== null) {
            $data['timeouts'] = $this->timeouts->toArray();
        }

        if ($this->batch_events !== null) {
            $data['batchEvents'] = $this->batch_events->toArray();
        }

        if ($this->cancel !== null && count($this->cancel) > 0) {
            $data['cancel'] = array_map(fn($c) => $c->toArray(), $this->cancel);
        }

        return $data;
    }
}
