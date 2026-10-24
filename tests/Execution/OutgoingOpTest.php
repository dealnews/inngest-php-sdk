<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Error\SerializedError;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\OutgoingOp;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for OutgoingOp
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(OutgoingOp::class)]
#[UsesClass(SerializedError::class)]
#[UsesClass(ValueObject::class)]
class OutgoingOpTest extends TestCase {

    /**
     * Only set fields are sent
     *
     * @return void
     */
    public function testMinimal(): void {
        $this->assertSame(
            '{"id":"abc","op":"RunComplete"}',
            json_encode(new OutgoingOp('abc', OpCode::RunComplete))
        );
    }

    /**
     * Every field, with empty opts as `{}` and null data kept
     *
     * @return void
     */
    public function testFull(): void {
        $op = new OutgoingOp(
            id: 'abc',
            op: OpCode::StepError,
            name: 'A',
            opts: [],
            display_name: 'Pretty',
            userland_id: 'A',
            userland_index: 2
        );
        $op->withData(null);
        $op->error          = new SerializedError();
        $op->error->message = 'm';
        $op->timing         = ['a' => 1, 'b' => 2];

        $this->assertSame(
            '{"id":"abc","op":"StepError","name":"A","opts":{},' .
            '"displayName":"Pretty","userland":{"id":"A","index":2},' .
            '"data":null,"error":{"name":"Error","message":"m",' .
            '"stack":"","__serialized":true},"timing":{"a":1,"b":2}}',
            json_encode($op)
        );
    }

    /**
     * Options and data are sent as given
     *
     * @return void
     */
    public function testOptsAndData(): void {
        $op = (new OutgoingOp('abc', OpCode::StepRun, opts: ['input' => [1]]))
            ->withData(['x' => 1]);

        $this->assertTrue($op->has_data);
        $this->assertSame(
            '{"id":"abc","op":"StepRun","opts":{"input":[1]},"data":{"x":1}}',
            json_encode($op)
        );
    }
}
