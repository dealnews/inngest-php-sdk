<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

/**
 * Step operation codes exchanged with Inngest
 *
 * Port of the in-scope members of JS `StepOpCode` (types.ts).
 *
 * @package DealNews\Inngest\Execution
 */
enum OpCode: string {
    case WaitForSignal    = 'WaitForSignal';
    case WaitForEvent     = 'WaitForEvent';
    case StepRun          = 'StepRun';
    case StepError        = 'StepError';
    case StepFailed       = 'StepFailed';
    case StepPlanned      = 'StepPlanned';
    case Sleep            = 'Sleep';
    case StepNotFound     = 'StepNotFound';
    case InvokeFunction   = 'InvokeFunction';
    case AiGateway        = 'AIGateway';
    case Gateway          = 'Gateway';
    case RunComplete      = 'RunComplete';
    case DiscoveryRequest = 'DiscoveryRequest';
}
