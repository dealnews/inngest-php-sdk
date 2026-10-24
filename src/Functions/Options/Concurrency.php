<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Limits how many steps can run at once
 *
 * Usage:
 *
 * ```php
 * new Concurrency(limit: 5, key: 'event.data.user_id');
 * ```
 *
 * @package DealNews\Inngest\Functions\Options
 */
class Concurrency extends ValueObject {

    /**
     * Creates the limit
     *
     * @param int                   $limit Maximum concurrent steps
     * @param string|null           $key   Expression; each value gets its own
     *                                     limit
     * @param ConcurrencyScope|null $scope What the limit applies across
     */
    public function __construct(
        public int $limit,
        public ?string $key = null,
        public ?ConcurrencyScope $scope = null
    ) {
    }
}
