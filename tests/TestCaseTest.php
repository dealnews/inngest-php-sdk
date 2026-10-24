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
     * JSON comparison ignores key order but not list order or {} vs []
     *
     * @return void
     */
    public function testAssertJsonDataSame(): void {
        $this->assertJsonDataSame(
            '{"a":1,"b":{"y":[1,{"q":1,"p":2}],"x":{}}}',
            '{"b":{"x":{},"y":[1,{"p":2,"q":1}]},"a":1}'
        );

        $this->assertNotSame(
            static::canonicalJson('{"a":[1,2]}'),
            static::canonicalJson('{"a":[2,1]}')
        );
        $this->assertNotSame(
            static::canonicalJson('{"a":{}}'),
            static::canonicalJson('{"a":[]}')
        );
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
