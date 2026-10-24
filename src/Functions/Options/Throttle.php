<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Limits run starts per period; runs over the limit wait in a queue
 *
 * Usage:
 *
 * ```php
 * new Throttle(limit: 3, period: '10s', burst: 2);
 * ```
 *
 * @package DealNews\Inngest\Functions\Options
 */
class Throttle extends ValueObject {

    /**
     * Creates the throttle
     *
     * @param int         $limit  Runs started per period
     * @param string      $period Period, e.g. `10s`
     * @param string|null $key    Expression; each value gets its own limit
     * @param int|null    $burst  Runs allowed to start at once
     */
    public function __construct(
        public int $limit,
        public string $period,
        public ?string $key = null,
        public ?int $burst = null
    ) {
    }
}
