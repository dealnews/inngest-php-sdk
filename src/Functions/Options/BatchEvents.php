<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Runs the function with a batch of events instead of one at a time
 *
 * Usage:
 *
 * ```php
 * new BatchEvents(max_size: 100, timeout: '5s');
 * ```
 *
 * @package DealNews\Inngest\Functions\Options
 */
class BatchEvents extends ValueObject {

    protected const WIRE_KEYS = [
        'max_size' => 'maxSize',
    ];

    /**
     * Creates the batch config
     *
     * @param int         $max_size Most events in one batch
     * @param string      $timeout  How long to wait to fill a batch, e.g. `5s`
     * @param string|null $key      Expression; batches per value
     * @param string|null $if       Expression; only matching events batch
     */
    public function __construct(
        public int $max_size,
        public string $timeout,
        public ?string $key = null,
        public ?string $if = null
    ) {
    }
}
