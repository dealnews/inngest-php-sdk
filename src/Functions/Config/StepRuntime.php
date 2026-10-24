<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Config;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * How Inngest reaches a function's step: always HTTP for this SDK
 *
 * @package DealNews\Inngest\Functions\Config
 */
class StepRuntime extends ValueObject {

    /**
     * Creates the runtime
     *
     * @param string $type Transport; `http` (WebSockets aren't supported)
     * @param string $url  URL Inngest calls to run the function
     */
    public function __construct(
        public string $type = 'http',
        public string $url = ''
    ) {
    }
}
