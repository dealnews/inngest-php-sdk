<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Options;

use DealNews\Inngest\Functions\Options\Singleton;
use DealNews\Inngest\Functions\Options\SingletonMode;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Singleton
 *
 * @package DealNews\Inngest\Tests\Functions\Options
 */
#[CoversClass(Singleton::class)]
#[UsesClass(ValueObject::class)]
class SingletonTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"mode":"skip"}',
            (new Singleton(SingletonMode::Skip))->toJson()
        );
        $this->assertSame(
            '{"mode":"cancel","key":"k"}',
            (new Singleton(SingletonMode::Cancel, 'k'))->toJson()
        );
    }

    /**
     * Wire data hydrates into the PHP properties
     *
     * @return void
     */
    public function testFromArray(): void {
        $this->assertSame(
            SingletonMode::Cancel,
            Singleton::fromArray(['mode' => 'cancel'])->mode
        );
    }
}
