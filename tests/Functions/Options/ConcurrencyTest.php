<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Options;

use DealNews\Inngest\Functions\Options\Concurrency;
use DealNews\Inngest\Functions\Options\ConcurrencyScope;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Concurrency
 *
 * @package DealNews\Inngest\Tests\Functions\Options
 */
#[CoversClass(Concurrency::class)]
#[UsesClass(ValueObject::class)]
class ConcurrencyTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"limit":5}',
            (new Concurrency(5))->toJson()
        );
        $this->assertSame(
            '{"limit":5,"key":"event.data.a","scope":"account"}',
            (new Concurrency(
                5,
                'event.data.a',
                ConcurrencyScope::Account
            ))->toJson()
        );
    }

    /**
     * Wire data hydrates into the PHP properties
     *
     * @return void
     */
    public function testFromArray(): void {
        $this->assertSame(
            ConcurrencyScope::Function,
            Concurrency::fromArray(['limit' => 1, 'scope' => 'fn'])->scope
        );
    }
}
