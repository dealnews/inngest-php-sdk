<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Config;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * A cancellation, as sent in the registration config
 *
 * @package DealNews\Inngest\Functions\Config
 */
class CancelConfig extends ValueObject {

    /**
     * Creates the cancel config
     *
     * @param string      $event   Event that cancels the run
     * @param string|null $if      Match expression
     * @param string|null $timeout Time string or ISO date
     */
    public function __construct(
        public string $event = '',
        public ?string $if = null,
        public ?string $timeout = null
    ) {
    }
}
