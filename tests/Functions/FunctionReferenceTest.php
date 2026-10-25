<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions;

use DealNews\Inngest\Functions\FunctionReference;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for FunctionReference
 *
 * @package DealNews\Inngest\Tests\Functions
 */
#[CoversClass(FunctionReference::class)]
class FunctionReferenceTest extends TestCase {

    /**
     * The app ID defaults to the caller's (JS `appId || client.id`)
     *
     * @return void
     */
    public function testAbsoluteId(): void {
        $this->assertSame(
            'mine-fn',
            (new FunctionReference('fn'))->absoluteId('mine')
        );
        $this->assertSame(
            'mine-fn',
            (new FunctionReference('fn', ''))->absoluteId('mine')
        );
        $this->assertSame(
            'other-fn',
            (new FunctionReference('fn', 'other'))->absoluteId('mine')
        );
        $this->assertSame(
            'fn',
            (new FunctionReference('fn'))->absoluteId('')
        );
    }
}
