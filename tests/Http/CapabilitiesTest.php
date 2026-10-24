<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http;

use DealNews\Inngest\Http\Capabilities;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Capabilities
 *
 * @package DealNews\Inngest\Tests\Http
 */
#[CoversClass(Capabilities::class)]
#[UsesClass(ValueObject::class)]
class CapabilitiesTest extends TestCase {

    /**
     * Reports the trust probe only (no Connect)
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"trust_probe":"v1"}',
            (new Capabilities())->toJson()
        );
    }
}
