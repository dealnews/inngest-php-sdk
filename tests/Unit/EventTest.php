<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit;

use DealNews\Inngest\Event\Event;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testCreateEventWithName(): void
    {
        $event = new Event('user/created', ['user_id' => 123]);

        $this->assertSame('user/created', $event->getName());
        $this->assertSame(['user_id' => 123], $event->getData());
        $this->assertNotNull($event->getId());
        $this->assertNotNull($event->getTs());
    }

    public function testCreateEventWithAllFields(): void
    {
        $event = new Event(
            name: 'user/updated',
            data: ['user_id' => 456],
            id: 'custom-id',
            user: ['email' => 'test@example.com'],
            ts: 1234567890000
        );

        $this->assertSame('user/updated', $event->getName());
        $this->assertSame(['user_id' => 456], $event->getData());
        $this->assertSame('custom-id', $event->getId());
        $this->assertSame(['email' => 'test@example.com'], $event->getUser());
        $this->assertSame(1234567890000, $event->getTs());
    }

    public function testToArray(): void
    {
        $event = new Event(
            name: 'test/event',
            data: ['foo' => 'bar'],
            id: 'test-id',
            ts: 1000000000000
        );

        $array = $event->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('ts', $array);
        $this->assertSame('test-id', $array['id']);
        $this->assertSame('test/event', $array['name']);
        $this->assertSame(['foo' => 'bar'], $array['data']);
        $this->assertSame(1000000000000, $array['ts']);
    }

    public function testToArrayWithUser(): void
    {
        $event = new Event(
            name: 'test/event',
            data: [],
            user: ['id' => 'user-123']
        );

        $array = $event->toArray();

        $this->assertArrayHasKey('user', $array);
        $this->assertSame(['id' => 'user-123'], $array['user']);
    }

    public function testGeneratesUniqueIds(): void
    {
        $event1 = new Event('test/event', []);
        $event2 = new Event('test/event', []);

        $this->assertNotSame($event1->getId(), $event2->getId());
    }

    public function testGeneratesTimestamp(): void
    {
        $before = (int) (microtime(true) * 1000);
        $event = new Event('test/event', []);
        $after = (int) (microtime(true) * 1000);

        $this->assertGreaterThanOrEqual($before, $event->getTs());
        $this->assertLessThanOrEqual($after, $event->getTs());
    }
}
