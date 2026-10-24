<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Hard limit on runs per period; runs over the limit are skipped
 *
 * Usage:
 *
 * ```php
 * new RateLimit(limit: 10, period: '1m', key: 'event.data.user_id');
 * ```
 *
 * @package DealNews\Inngest\Functions\Options
 */
class RateLimit extends ValueObject {

    /**
     * Creates the rate limit
     *
     * @param int         $limit  Runs allowed per period
     * @param string      $period Period, e.g. `1m`
     * @param string|null $key    Expression; each value gets its own limit
     */
    public function __construct(
        public int $limit,
        public string $period,
        public ?string $key = null
    ) {
    }
}
