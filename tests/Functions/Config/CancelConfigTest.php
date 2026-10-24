<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Functions\Config;

use DealNews\Inngest\Functions\Config\CancelConfig;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for CancelConfig
 *
 * @package DealNews\Inngest\Tests\Functions\Config
 */
#[CoversClass(CancelConfig::class)]
#[UsesClass(ValueObject::class)]
class CancelConfigTest extends TestCase {

    /**
     * Encodes to the JS wire shape, leaving out unset fields
     *
     * @return void
     */
    public function testJson(): void {
        $this->assertSame(
            '{"event":"e"}',
            (new CancelConfig('e'))->toJson()
        );
        $this->assertSame(
            '{"event":"e","if":"x","timeout":"1h"}',
            (new CancelConfig('e', 'x', '1h'))->toJson()
        );
    }
}
