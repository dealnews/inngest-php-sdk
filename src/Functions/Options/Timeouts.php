<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Cancels runs that wait or run too long
 *
 * Usage:
 *
 * ```php
 * new Timeouts(start: '10m', finish: '1h');
 * ```
 *
 * @package DealNews\Inngest\Functions\Options
 */
class Timeouts extends ValueObject {

    /**
     * Creates the timeouts
     *
     * @param string|null $start  Longest a run may wait to start
     * @param string|null $finish Longest a run may take once started
     */
    public function __construct(
        public ?string $start = null,
        public ?string $finish = null
    ) {
    }
}
