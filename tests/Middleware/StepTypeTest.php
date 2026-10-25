<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Middleware;

use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Middleware\StepType;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for StepType
 *
 * Port of `stepTypeFromOpCode` cases (components/middleware/utils.ts).
 *
 * @package DealNews\Inngest\Tests\Middleware
 */
#[CoversClass(StepType::class)]
class StepTypeTest extends TestCase {

    /**
     * Ops and the step types they map to
     *
     * @return array<string, array{OpCode, array<string, mixed>, StepType}>
     */
    public static function ops(): array {
        return [
            'ai.infer'       => [
                OpCode::AiGateway,
                ['type' => 'step.ai.infer'],
                StepType::AiInfer,
            ],
            'ai gateway, no type' => [
                OpCode::AiGateway,
                [],
                StepType::Unknown,
            ],
            'fetch'          => [OpCode::Gateway, [], StepType::Fetch],
            'invoke'         => [OpCode::InvokeFunction, [], StepType::Invoke],
            'run'            => [OpCode::StepPlanned, [], StepType::Run],
            'run with input' => [
                OpCode::StepPlanned,
                ['input' => [1]],
                StepType::Run,
            ],
            'ai.wrap'        => [
                OpCode::StepPlanned,
                ['type' => 'step.ai.wrap'],
                StepType::AiWrap,
            ],
            'sendEvent'      => [
                OpCode::StepPlanned,
                ['type' => 'step.sendEvent'],
                StepType::SendEvent,
            ],
            'sendSignal'     => [
                OpCode::StepPlanned,
                ['type' => 'step.sendSignal'],
                StepType::SendSignal,
            ],
            'planned, unknown type' => [
                OpCode::StepPlanned,
                ['type' => 'step.realtime.publish'],
                StepType::Unknown,
            ],
            'sleep'          => [OpCode::Sleep, [], StepType::Sleep],
            'waitForEvent'   => [
                OpCode::WaitForEvent,
                [],
                StepType::WaitForEvent,
            ],
            'waitForSignal'  => [
                OpCode::WaitForSignal,
                [],
                StepType::WaitForSignal,
            ],
            'other op'       => [OpCode::StepRun, [], StepType::Unknown],
        ];
    }

    /**
     * Maps op codes (and `opts.type`) to step types
     *
     * @param OpCode               $op       Op code
     * @param array<string, mixed> $opts     Op options
     * @param StepType             $expected Expected type
     *
     * @return void
     */
    #[DataProvider('ops')]
    public function testFromOp(
        OpCode $op,
        array $opts,
        StepType $expected
    ): void {
        $this->assertSame($expected, StepType::fromOp($op, $opts));
    }

    /**
     * Only invoke and waitForEvent use the whole op options as input
     *
     * @return void
     */
    public function testInputIsOpts(): void {
        $with = [];
        foreach (StepType::cases() as $type) {
            if ($type->inputIsOpts()) {
                $with[] = $type;
            }
        }

        $this->assertSame([StepType::Invoke, StepType::WaitForEvent], $with);
    }
}
