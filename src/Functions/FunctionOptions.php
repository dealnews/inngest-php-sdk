<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions;

use DealNews\Inngest\Config\Checkpointing;
use DealNews\Inngest\Functions\Options\BatchEvents;
use DealNews\Inngest\Functions\Options\Cancellation;
use DealNews\Inngest\Functions\Options\Concurrency;
use DealNews\Inngest\Functions\Options\Debounce;
use DealNews\Inngest\Functions\Options\Priority;
use DealNews\Inngest\Functions\Options\RateLimit;
use DealNews\Inngest\Functions\Options\Singleton;
use DealNews\Inngest\Functions\Options\Throttle;
use DealNews\Inngest\Functions\Options\Timeouts;
use DealNews\Inngest\Functions\Trigger\Trigger;

/**
 * Options for creating an Inngest function
 *
 * Port of the JS `InngestFunction.Options`. Not a value object: it holds
 * the `on_failure` handler, which can't be serialized.
 *
 * Usage:
 *
 * ```php
 * $inngest->createFunction(
 *     new FunctionOptions(
 *         id: 'send-welcome-email',
 *         triggers: [new EventTrigger('app/user.created')],
 *         retries: 5,
 *         concurrency: new Concurrency(limit: 10),
 *     ),
 *     function (Context $ctx) {
 *         // ...
 *     }
 * );
 * ```
 *
 * Edge cases:
 *
 * - `id` must stay the same across deploys; change `name` instead.
 * - Concurrency takes a number, one limit, or a list of at most two.
 * - Retries must be between 0 and 20 (default: 3, set by Inngest).
 *
 * @package DealNews\Inngest\Functions
 */
class FunctionOptions {

    /**
     * Most retries Inngest allows
     *
     * @var int
     */
    public const MAX_RETRIES = 20;

    /**
     * Most concurrency limits a function can have
     *
     * @var int
     */
    public const MAX_CONCURRENCY_LIMITS = 2;

    /**
     * Handler run when the function fails after all retries
     *
     * @var \Closure|null
     */
    public ?\Closure $on_failure = null;

    /**
     * Creates the options
     *
     * @param string                            $id           Stable ID
     * @param string|null                       $name         Display name
     * @param string|null                       $description  Description
     * @param list<Trigger>                     $triggers     Triggers
     * @param int|Concurrency|list<Concurrency>|null $concurrency Limits
     * @param BatchEvents|null                  $batch_events Batching
     * @param string|null                       $idempotency  Idempotency
     *                                                        key expression
     * @param RateLimit|null                    $rate_limit   Rate limit
     * @param Throttle|null                     $throttle     Throttle
     * @param Debounce|null                     $debounce     Debounce
     * @param Priority|null                     $priority     Priority
     * @param Timeouts|null                     $timeouts     Timeouts
     * @param Singleton|null                    $singleton    Singleton
     * @param list<Cancellation>                $cancel_on    Cancellations
     * @param int|null                          $retries      Retries per
     *                                                        step (0-20)
     * @param callable|null                     $on_failure   Runs after the
     *                                                        function fails
     * @param bool|Checkpointing|null           $checkpointing Async
     *        checkpointing; null uses the client's setting (on by default)
     *
     * @throws \InvalidArgumentException When an option is invalid
     */
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $description = null,
        public array $triggers = [],
        public int|Concurrency|array|null $concurrency = null,
        public ?BatchEvents $batch_events = null,
        public ?string $idempotency = null,
        public ?RateLimit $rate_limit = null,
        public ?Throttle $throttle = null,
        public ?Debounce $debounce = null,
        public ?Priority $priority = null,
        public ?Timeouts $timeouts = null,
        public ?Singleton $singleton = null,
        public array $cancel_on = [],
        public ?int $retries = null,
        ?callable $on_failure = null,
        public bool|Checkpointing|null $checkpointing = null
    ) {
        if ($on_failure !== null) {
            $this->on_failure = \Closure::fromCallable($on_failure);
        }

        $this->validate();
    }

    /**
     * Checks options the type system can't
     *
     * @throws \InvalidArgumentException When an option is invalid
     *
     * @return void
     */
    protected function validate(): void {
        if (trim($this->id) === '') {
            throw new \InvalidArgumentException(
                'A function ID is required',
                9001
            );
        }

        if (
            $this->retries !== null &&
            ($this->retries < 0 || $this->retries > self::MAX_RETRIES)
        ) {
            throw new \InvalidArgumentException(
                'Retries must be between 0 and ' . self::MAX_RETRIES,
                9002
            );
        }

        if (is_array($this->concurrency)) {
            $count = count($this->concurrency);
            if ($count < 1 || $count > self::MAX_CONCURRENCY_LIMITS) {
                throw new \InvalidArgumentException(
                    'Concurrency takes 1 to ' . self::MAX_CONCURRENCY_LIMITS .
                    ' limits',
                    9003
                );
            }
            static::assertList($this->concurrency, Concurrency::class, 9004);
        }

        static::assertList($this->triggers, Trigger::class, 9005);
        static::assertList($this->cancel_on, Cancellation::class, 9006);
    }

    /**
     * Ensures an array is a list of the given type
     *
     * @param array<mixed> $items Items
     * @param string       $class Required class or interface
     * @param int          $code  Exception code
     *
     * @throws \InvalidArgumentException When an item has the wrong type
     *
     * @return void
     */
    protected static function assertList(
        array $items,
        string $class,
        int $code
    ): void {
        $parts = explode('\\', $class);
        $short = end($parts);

        if (!array_is_list($items)) {
            throw new \InvalidArgumentException(
                "Expected a list of $short",
                $code
            );
        }

        foreach ($items as $item) {
            if (!$item instanceof $class) {
                throw new \InvalidArgumentException(
                    "Expected a list of $short",
                    $code
                );
            }
        }
    }
}
