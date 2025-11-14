<?php

declare(strict_types=1);

namespace DealNews\Inngest\Function;

/**
 * Represents an Inngest function
 */
class InngestFunction
{
    /**
     * @param string $id Unique identifier for the function
     * @param callable $handler Function handler
     * @param array<TriggerInterface> $triggers Array of triggers
     * @param string|null $name Display name for the function
     * @param int $retries Number of retry attempts (default 3 retries = 4 total attempts)
     * @param array<Concurrency>|null $concurrency Optional concurrency limits (max 2)
     */
    public function __construct(
        protected string $id,
        protected $handler,
        protected array $triggers,
        protected ?string $name = null,
        protected int $retries = 3,
        protected ?array $concurrency = null
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
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
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

        if ($this->concurrency !== null && count($this->concurrency) > 0) {
            $data['concurrency'] = array_map(fn($c) => $c->toArray(), $this->concurrency);
        }

        return $data;
    }
}
