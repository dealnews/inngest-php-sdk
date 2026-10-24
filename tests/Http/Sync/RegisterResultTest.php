<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http\Sync;

use DealNews\Inngest\Http\Sync\RegisterResult;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for RegisterResult
 *
 * @package DealNews\Inngest\Tests\Http\Sync
 */
#[CoversClass(RegisterResult::class)]
class RegisterResultTest extends TestCase {

    /**
     * Holds the values; modified defaults to false
     *
     * @return void
     */
    public function testValues(): void {
        $result = new RegisterResult(500, 'Failed');

        $this->assertSame(500, $result->status);
        $this->assertSame('Failed', $result->message);
        $this->assertFalse($result->modified);
    }
}
