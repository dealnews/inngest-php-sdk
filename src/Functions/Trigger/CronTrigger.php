<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Trigger;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Runs a function on a cron schedule
 *
 * Usage:
 *
 * ```php
 * new CronTrigger('0 * * * *');
 * new CronTrigger('TZ=Europe/Paris 0 12 * * 5', jitter: '30s');
 * ```
 *
 * @package DealNews\Inngest\Functions\Trigger
 */
class CronTrigger extends ValueObject implements Trigger {

    /**
     * Creates the trigger
     *
     * @param string      $cron   Cron expression, optionally `TZ=...` first
     * @param string|null $jitter Random delay added to each run, e.g. `30s`
     */
    public function __construct(
        public string $cron,
        public ?string $jitter = null
    ) {
    }
}
