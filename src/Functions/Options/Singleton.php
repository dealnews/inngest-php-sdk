<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Allows only one active run at a time (per key)
 *
 * Usage:
 *
 * ```php
 * new Singleton(mode: SingletonMode::Skip, key: 'event.data.user_id');
 * ```
 *
 * @package DealNews\Inngest\Functions\Options
 */
class Singleton extends ValueObject {

    /**
     * Creates the singleton config
     *
     * @param SingletonMode $mode What to do with a new run
     * @param string|null   $key  Expression; one active run per value
     */
    public function __construct(
        public SingletonMode $mode,
        public ?string $key = null
    ) {
    }
}
