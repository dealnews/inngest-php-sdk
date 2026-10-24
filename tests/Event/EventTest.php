<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Event;

use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Event\EventMeta;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Event
 *
 * @package DealNews\Inngest\Tests\Event
 */
#[CoversClass(Event::class)]
#[UsesClass(EventMeta::class)]
#[UsesClass(ValueObject::class)]
class EventTest extends TestCase {

    /**
     * Minimal events encode with `{}` data and no optional fields
     *
     * @return void
     */
    public function testMinimalJson(): void {
        $this->assertSame(
            '{"name":"app/test","data":{}}',
            (new Event(name: 'app/test'))->toJson()
        );
    }

    /**
     * Every field encodes with its wire name
     *
     * @return void
     */
    public function testFullJson(): void {
        $event = new Event(
            name: 'app/test',
            data: ['user' => ['id' => 1]],
            id: 'evt-1',
            ts: 1700000000000,
            v: '2024-01-01.1',
            meta: new EventMeta(sessions: ['s' => 'x'])
        );

        $this->assertSame(
            '{"name":"app/test","data":{"user":{"id":1}},"id":"evt-1",' .
            '"ts":1700000000000,"v":"2024-01-01.1",' .
            '"meta":{"sessions":{"s":"x"}}}',
            $event->toJson()
        );
    }

    /**
     * Received events hydrate, including meta
     *
     * @return void
     */
    public function testFromArray(): void {
        $event = Event::fromArray([
            'name' => 'app/test',
            'data' => ['a' => 1],
            'id'   => '01HX',
            'ts'   => 1700000000000,
            'meta' => ['sessions' => ['conversation_id' => 'c1']],
        ]);

        $this->assertSame('app/test', $event->name);
        $this->assertSame(['a' => 1], $event->data);
        $this->assertSame('01HX', $event->id);
        $this->assertSame(1700000000000, $event->ts);
        $this->assertInstanceOf(EventMeta::class, $event->meta);
        $this->assertSame(['conversation_id' => 'c1'], $event->meta->sessions);
    }
}
