<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Tests for the shared TestCase helpers
 *
 * @package DealNews\Inngest\Tests
 */
#[CoversNothing]
class TestCaseTest extends TestCase {

    /**
     * Raw fixtures come back byte-for-byte
     *
     * @return void
     */
    public function testLoadFixtureReturnsRawContents(): void {
        $raw = $this->loadFixture('sample.json');

        $this->assertStringStartsWith("{\n", $raw);
        $this->assertStringEndsWith("}\n", $raw);
    }

    /**
     * JSON fixtures decode to arrays
     *
     * @return void
     */
    public function testLoadJsonFixtureDecodes(): void {
        $data = $this->loadJsonFixture('sample.json');

        $this->assertSame('test/fixture', $data['name']);
        $this->assertTrue($data['data']['ok']);
    }

    /**
     * Missing fixtures fail loudly
     *
     * @return void
     */
    public function testLoadFixtureThrowsWhenMissing(): void {
        $this->expectException(\RuntimeException::class);
        $this->loadFixture('does-not-exist.json');
    }
}
