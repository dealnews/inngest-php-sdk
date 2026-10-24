<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Execution\StepState;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for StepState (ports the JS `stepSchema` union)
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(StepState::class)]
class StepStateTest extends TestCase {

    /**
     * Wire values map to the right type and value
     *
     * @param string $json  Wire JSON
     * @param string $type  Expected type
     * @param mixed  $value Expected data, error, or input (by type)
     *
     * @return void
     */
    #[DataProvider('states')]
    public function testFromWire(
        string $json,
        string $type,
        mixed $value = null
    ): void {
        $state = StepState::fromWire(json_decode($json, false));

        $this->assertSame($type, $state->type);
        $this->assertSame($type === 'data' ? $value : null, $state->data);
        $this->assertSame($type === 'error' ? $value : null, $state->error);
        $this->assertSame($type === 'input' ? $value : null, $state->input);
        $this->assertSame($type !== 'input', $state->isFulfilled());
    }

    /**
     * Wire values and their state
     *
     * @return array<string, array<int, mixed>>
     */
    public static function states(): array {
        $error = ['name' => 'E', 'message' => 'm'];

        return [
            'data'           => ['{"data":{"a":[1]}}', 'data', ['a' => [1]]],
            'typed data'     => ['{"type":"data","data":1}', 'data', 1],
            'null data'      => ['{"data":null}', 'data', null],
            'error'          => [
                '{"error":{"name":"E","message":"m"}}',
                'error',
                $error,
            ],
            'typed error'    => ['{"type":"error","error":"x"}', 'error', 'x'],
            'empty object'   => ['{}', 'error'],
            'type only err'  => ['{"type":"error"}', 'error'],
            'input'          => ['{"input":[1,2]}', 'input', [1, 2]],
            'typed input'    => [
                '{"type":"input","input":{"a":1}}',
                'input',
                ['a' => 1],
            ],
            'raw string'     => ['"slept"', 'data', 'slept'],
            'raw null'       => ['null', 'data', null],
            'raw list'       => ['[1,2]', 'data', [1, 2]],
            'raw empty list' => ['[]', 'data', []],
            'extra key'      => [
                '{"data":1,"x":2}',
                'data',
                ['data' => 1, 'x' => 2],
            ],
            'wrong type' => [
                '{"type":"input","data":1}',
                'data',
                ['type' => 'input', 'data' => 1],
            ],
            'null type' => [
                '{"type":null,"data":1}',
                'data',
                ['type' => null, 'data' => 1],
            ],
            'type only data' => ['{"type":"data"}', 'data', ['type' => 'data']],
            'event payload'  => [
                '{"name":"a","data":{"x":1}}',
                'data',
                ['name' => 'a', 'data' => ['x' => 1]],
            ],
        ];
    }

    /**
     * Associative arrays (from the API) are read like objects
     *
     * @return void
     */
    public function testAssociativeArrays(): void {
        $this->assertSame(
            'input',
            StepState::fromWire(['input' => [1]])->type
        );
        $this->assertSame(
            ['data' => 1, 'x' => 2],
            StepState::fromWire(['data' => 1, 'x' => 2])->data
        );
    }

    /**
     * stdClass becomes arrays, recursively
     *
     * @return void
     */
    public function testToPhp(): void {
        $this->assertSame(
            ['a' => [['b' => 1]], 'c' => []],
            StepState::toPhp(json_decode('{"a":[{"b":1}],"c":{}}', false))
        );
        $this->assertSame('x', StepState::toPhp('x'));
    }
}
