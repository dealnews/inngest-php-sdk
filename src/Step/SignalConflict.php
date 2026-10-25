<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

/**
 * What `step->waitForSignal()` does when another run already waits for
 * the same signal
 *
 * @package DealNews\Inngest\Step
 */
enum SignalConflict: string {

    /**
     * Take over the signal; the other wait stays pending until it times out
     */
    case Replace = 'replace';

    /**
     * Fail this run
     */
    case Fail = 'fail';
}
