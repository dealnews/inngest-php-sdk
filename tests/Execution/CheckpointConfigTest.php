<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Config\Checkpointing;
use DealNews\Inngest\Execution\CheckpointConfig;
use DealNews\Inngest\Support\Duration;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for CheckpointConfig (JS `shouldAsyncCheckpoint`)
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(CheckpointConfig::class)]
#[UsesClass(Checkpointing::class)]
#[UsesClass(Duration::class)]
class CheckpointConfigTest extends TestCase {

    /**
     * Only discovery requests with a function ID checkpoint
     *
     * @param string|null $requested Requested step
     * @param string|null $fn_id     Function ID
     * @param bool        $disable   Immediate execution disabled
     * @param bool        $expected  Whether it checkpoints
     *
     * @return void
     */
    #[DataProvider('requestProvider')]
    public function testRequestConditions(
        ?string $requested,
        ?string $fn_id,
        bool $disable,
        bool $expected
    ): void {
        $config = CheckpointConfig::resolve(
            null,
            null,
            $requested,
            $fn_id,
            $disable,
            new Duration()
        );

        $this->assertSame($expected, $config !== null);
    }

    /**
     * Request shapes
     *
     * @return array<string, array{?string, ?string, bool, bool}>
     */
    public static function requestProvider(): array {
        return [
            'discovery'         => [null, 'fn', false, true],
            'requested step'    => ['abc', 'fn', false, false],
            'no function ID'    => [null, null, false, false],
            'empty function ID' => [null, '', false, false],
            'no immediate exec' => [null, 'fn', true, false],
        ];
    }

    /**
     * The function setting wins over the client's; both default to on
     *
     * @param bool|Checkpointing|null $function Function setting
     * @param bool|Checkpointing|null $client   Client setting
     * @param array{int, float, float}|null $expected Buffered steps, max
     *        runtime, max interval; null when off
     *
     * @return void
     */
    #[DataProvider('settingProvider')]
    public function testSettings(
        bool|Checkpointing|null $function,
        bool|Checkpointing|null $client,
        ?array $expected
    ): void {
        $config = CheckpointConfig::resolve(
            $function,
            $client,
            null,
            'fn',
            false,
            new Duration()
        );

        $this->assertSame(
            $expected,
            $config === null ?
                null :
                [
                    $config->buffered_steps,
                    $config->max_runtime_ms,
                    $config->max_interval_ms,
                ]
        );
    }

    /**
     * Settings
     *
     * @return array<string, array{mixed, mixed, ?array{int, float, float}}>
     */
    public static function settingProvider(): array {
        return [
            'defaults'          => [null, null, [1, 10000.0, 0.0]],
            'client off'        => [null, false, null],
            'function off'      => [false, true, null],
            'function on'       => [true, false, [1, 10000.0, 0.0]],
            'client options'    => [
                null,
                new Checkpointing(max_runtime: '30s'),
                [1, 30000.0, 0.0],
            ],
            'function options'  => [
                new Checkpointing(
                    max_runtime: 0,
                    buffered_steps: 3,
                    max_interval: '1s'
                ),
                false,
                [3, 0.0, 1000.0],
            ],
            'interval interval' => [
                new Checkpointing(max_interval: new \DateInterval('PT2S')),
                null,
                [1, 10000.0, 2000.0],
            ],
        ];
    }
}
