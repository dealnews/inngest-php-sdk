<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Delays a run until events stop arriving for a period
 *
 * Usage:
 *
 * ```php
 * new Debounce(period: '5m', key: 'event.data.user_id', timeout: '1h');
 * ```
 *
 * @package DealNews\Inngest\Functions\Options
 */
class Debounce extends ValueObject {

    /**
     * Creates the debounce
     *
     * @param string      $period  Quiet period to wait for, e.g. `5m`
     * @param string|null $key     Expression; debounces per value
     * @param string|null $timeout Longest a run can be delayed
     */
    public function __construct(
        public string $period,
        public ?string $key = null,
        public ?string $timeout = null
    ) {
    }
}
