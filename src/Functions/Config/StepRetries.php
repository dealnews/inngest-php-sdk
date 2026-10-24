<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Config;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Retry settings for a function's steps
 *
 * @package DealNews\Inngest\Functions\Config
 */
class StepRetries extends ValueObject {

    /**
     * Creates the retry settings
     *
     * @param int|null $attempts Retries per step (0-20)
     */
    public function __construct(public ?int $attempts = null) {
    }
}
