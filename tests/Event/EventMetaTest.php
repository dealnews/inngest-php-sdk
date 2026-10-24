<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Event;

use DealNews\Inngest\Event\EventMeta;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for EventMeta (port of JS sessions normalization tests)
 *
 * @package DealNews\Inngest\Tests\Event
 */
#[CoversClass(EventMeta::class)]
#[UsesClass(ValueObject::class)]
class EventMetaTest extends TestCase {

    /**
     * Valid sessions are stringified; null tombstones are kept
     *
     * @return void
     */
    public function testNormalized(): void {
        $meta = (new EventMeta(sessions: [
            'conversation_id' => 'conversation_1234',
            'priority'        => 1,
            'weight'          => 1.5,
            'cut'             => null,
            7                 => 'numeric key',
        ]))->normalized();

        $this->assertSame(
            [
                'conversation_id' => 'conversation_1234',
                'priority'        => '1',
                'weight'          => '1.5',
                'cut'             => null,
                7                 => 'numeric key',
            ],
            $meta->sessions
        );
        $this->assertSame(
            '{"sessions":{"conversation_id":"conversation_1234",' .
            '"priority":"1","weight":"1.5","cut":null,"7":"numeric key"}}',
            $meta->toJson()
        );
    }

    /**
     * No sessions means no meta at all
     *
     * @return void
     */
    public function testNormalizedEmpty(): void {
        $this->assertNull((new EventMeta())->normalized());
        $this->assertNull((new EventMeta(sessions: []))->normalized());
    }

    /**
     * Invalid sessions throw with the JS messages
     *
     * @param array<mixed> $sessions Sessions
     * @param int          $code     Expected code
     * @param string       $message  Expected message
     *
     * @return void
     */
    #[DataProvider('invalidProvider')]
    public function testInvalid(
        array $sessions,
        int $code,
        string $message
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode($code);
        $this->expectExceptionMessage($message);
        (new EventMeta(sessions: $sessions))->normalized();
    }

    /**
     * Cases for testInvalid
     *
     * @return array<string, array{array<mixed>, int, string}>
     */
    public static function invalidProvider(): array {
        return [
            'empty key'    => [
                ['' => 'x'],
                8001,
                'Event session keys cannot be empty',
            ],
            'boolean'      => [
                ['active' => true],
                8002,
                'Event session "active" must be a string, number, or null',
            ],
            'array'        => [
                ['nested' => ['x']],
                8002,
                'Event session "nested" must be a string, number, or null',
            ],
            'NaN'          => [
                ['n' => NAN],
                8003,
                'Event session "n" must be a finite number',
            ],
            'infinity'     => [
                ['n' => INF],
                8003,
                'Event session "n" must be a finite number',
            ],
            'empty string' => [
                ['s' => ''],
                8004,
                'Event session "s" cannot have an empty ID',
            ],
        ];
    }
}
