<?php

declare(strict_types=1);

namespace DealNews\Inngest\Api;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Result of sending a signal
 *
 * Port of JS `SendSignalResponse` (types.ts).
 *
 * @package DealNews\Inngest\Api
 */
class SendSignalResult extends ValueObject {

    protected const WIRE_KEYS = [
        'run_id' => 'runId',
    ];

    /**
     * Creates the result
     *
     * @param string|null $run_id Run that received the signal; null when no
     *                            run was waiting for it
     */
    public function __construct(public ?string $run_id = null) {
    }
}
