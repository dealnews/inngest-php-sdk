<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use DealNews\Inngest\Execution\OpCode;

/**
 * Which step tool a step came from, as middleware sees it
 *
 * Port of JS `Middleware.StepType` and `stepTypeFromOpCode`
 * (components/middleware/utils.ts).
 *
 * @package DealNews\Inngest\Middleware
 */
enum StepType: string {

    case AiInfer       = 'ai.infer';
    case AiWrap        = 'ai.wrap';
    case Fetch         = 'fetch';
    case Invoke        = 'invoke';
    case Run           = 'run';
    case SendEvent     = 'sendEvent';
    case SendSignal    = 'sendSignal';
    case Sleep         = 'sleep';
    case WaitForEvent  = 'waitForEvent';
    case WaitForSignal = 'waitForSignal';
    case Unknown       = 'unknown';

    /**
     * Works out the step type from an op
     *
     * JS has no `sendSignal` type (it logs "Unknown step type" and uses
     * `unknown`); this port names it.
     *
     * @param OpCode               $op   Op code
     * @param array<string, mixed> $opts Op options (`type` is checked)
     *
     * @return self
     */
    public static function fromOp(OpCode $op, array $opts): self {
        $type = $opts['type'] ?? null;

        if ($op === OpCode::StepPlanned) {
            $result = match ($type) {
                null              => self::Run,
                'step.ai.wrap'    => self::AiWrap,
                'step.sendEvent'  => self::SendEvent,
                'step.sendSignal' => self::SendSignal,
                default           => self::Unknown,
            };
        } elseif ($op === OpCode::AiGateway) {
            $result = $type === 'step.ai.infer' ? self::AiInfer : self::Unknown;
        } else {
            $result = match ($op) {
                OpCode::Gateway        => self::Fetch,
                OpCode::InvokeFunction => self::Invoke,
                OpCode::Sleep          => self::Sleep,
                OpCode::WaitForEvent   => self::WaitForEvent,
                OpCode::WaitForSignal  => self::WaitForSignal,
                default                => self::Unknown,
            };
        }

        return $result;
    }

    /**
     * Whether the whole op options are the step's input (`[opts]`)
     *
     * @return bool
     */
    public function inputIsOpts(): bool {
        return $this === self::Invoke || $this === self::WaitForEvent;
    }
}
