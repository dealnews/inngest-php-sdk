<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http\Sync;

/**
 * Outcome of an out-of-band sync, passed back to whoever sent the `PUT`
 *
 * @package DealNews\Inngest\Http\Sync
 */
class RegisterResult {

    /**
     * Creates the result
     *
     * @param int    $status   HTTP status to respond with
     * @param string $message  Message to respond with
     * @param bool   $modified Whether Inngest saw changed functions
     */
    public function __construct(
        public readonly int $status,
        public readonly string $message,
        public readonly bool $modified = false
    ) {
    }
}
