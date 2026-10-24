<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Optional protocol features the SDK supports, reported when syncing
 *
 * JS also reports `connect: "v1"`; this SDK doesn't support Connect, so it
 * only reports the trust probe.
 *
 * @package DealNews\Inngest\Http
 */
class Capabilities extends ValueObject {

    /**
     * Creates the capabilities
     *
     * @param string $trust_probe Trust probe version (`POST ?probe=trust`)
     */
    public function __construct(
        public string $trust_probe = 'v1'
    ) {
    }
}
