<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Config;

use DealNews\Inngest\Functions\Options\BatchEvents;
use DealNews\Inngest\Functions\Options\Concurrency;
use DealNews\Inngest\Functions\Options\Debounce;
use DealNews\Inngest\Functions\Options\Priority;
use DealNews\Inngest\Functions\Options\RateLimit;
use DealNews\Inngest\Functions\Options\Singleton;
use DealNews\Inngest\Functions\Options\Throttle;
use DealNews\Inngest\Functions\Options\Timeouts;
use DealNews\Inngest\Functions\Trigger\Trigger;
use DealNews\Inngest\ValueObject\ValueObject;

/**
 * One function's registration config, as sent to Inngest when syncing
 *
 * Port of the JS `FunctionConfig` (types.ts `functionConfigSchema`).
 * Property order matches the JS output.
 *
 * @package DealNews\Inngest\Functions\Config
 */
class FunctionConfig extends ValueObject {

    protected const WIRE_KEYS = [
        'batch_events' => 'batchEvents',
        'rate_limit'   => 'rateLimit',
    ];

    protected const OBJECT_KEYS = [
        'steps',
    ];

    /**
     * Creates the config
     *
     * Every option maps to the JS function option of the same name.
     *
     * @param string                    $id           `<app id>-<fn id>`
     * @param string|null               $name         Display name
     * @param list<Trigger>             $triggers     Triggers
     * @param array<string, StepConfig> $steps        Steps, keyed by ID
     * @param string|null               $idempotency  Idempotency key
     * @param BatchEvents|null          $batch_events Batching
     * @param RateLimit|null            $rate_limit   Rate limit
     * @param Throttle|null             $throttle     Throttle
     * @param int|Concurrency|list<Concurrency>|null $concurrency Limits
     * @param Debounce|null             $debounce     Debounce
     * @param Priority|null             $priority     Priority
     * @param Timeouts|null             $timeouts     Timeouts
     * @param Singleton|null            $singleton    Singleton
     * @param list<CancelConfig>|null   $cancel       Cancellations
     */
    public function __construct(
        public string $id = '',
        public ?string $name = null,
        public array $triggers = [],
        public array $steps = [],
        public ?string $idempotency = null,
        public ?BatchEvents $batch_events = null,
        public ?RateLimit $rate_limit = null,
        public ?Throttle $throttle = null,
        public int|Concurrency|array|null $concurrency = null,
        public ?Debounce $debounce = null,
        public ?Priority $priority = null,
        public ?Timeouts $timeouts = null,
        public ?Singleton $singleton = null,
        public ?array $cancel = null
    ) {
    }
}
