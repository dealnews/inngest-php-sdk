<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http;

use DealNews\Inngest\Http\SignatureResult;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for SignatureResult
 *
 * @package DealNews\Inngest\Tests\Http
 */
#[CoversClass(SignatureResult::class)]
class SignatureResultTest extends TestCase {

    /**
     * Only a success with a key counts as signed
     *
     * @return void
     */
    public function testIsSigned(): void {
        $error = new \RuntimeException('nope');

        $this->assertTrue((new SignatureResult(true, 'key'))->isSigned());
        $this->assertFalse((new SignatureResult(true))->isSigned());
        $this->assertFalse(
            (new SignatureResult(false, '', $error))->isSigned()
        );
        $this->assertSame(
            $error,
            (new SignatureResult(false, '', $error))->error
        );
    }
}
