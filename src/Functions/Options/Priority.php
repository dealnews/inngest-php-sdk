<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Decides run priority when many runs are queued
 *
 * Usage:
 *
 * ```php
 * new Priority(run: 'event.data.plan == "enterprise" ? 120 : 0');
 * ```
 *
 * @package DealNews\Inngest\Functions\Options
 */
class Priority extends ValueObject {

    /**
     * Creates the priority config
     *
     * @param string|null $run Expression returning -600..600 (seconds)
     */
    public function __construct(public ?string $run = null) {
    }
}
