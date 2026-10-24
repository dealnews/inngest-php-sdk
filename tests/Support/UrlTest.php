<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Support;

use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for Url (behavior of JS `new URL(ref, base)` and `normalizeUrl`)
 *
 * @package DealNews\Inngest\Tests\Support
 */
#[CoversClass(Url::class)]
class UrlTest extends TestCase {

    /**
     * Resolution matches WHATWG `new URL(ref, base)` for the SDK's cases
     *
     * @param string $base      Base URL
     * @param string $reference Reference
     * @param string $expected  Result
     *
     * @return void
     */
    #[DataProvider('resolveProvider')]
    public function testResolve(
        string $base,
        string $reference,
        string $expected
    ): void {
        $this->assertSame($expected, Url::resolve($base, $reference));
    }

    /**
     * Cases for testResolve
     *
     * @return array<string, array{string, string, string}>
     */
    public static function resolveProvider(): array {
        return [
            'event path'          => [
                'https://inn.gs/',
                'e/key',
                'https://inn.gs/e/key',
            ],
            'base without slash'  => [
                'http://localhost:8288',
                'e/key',
                'http://localhost:8288/e/key',
            ],
            'relative on subpath' => [
                'https://example.com/inngest/',
                'e/key',
                'https://example.com/inngest/e/key',
            ],
            'relative replaces last segment' => [
                'https://example.com/inngest',
                'e/key',
                'https://example.com/e/key',
            ],
            'absolute path'       => [
                'https://api.inngest.com/v1/',
                '/fn/register',
                'https://api.inngest.com/fn/register',
            ],
            'absolute url'        => [
                'https://api.inngest.com/',
                'http://other/x',
                'http://other/x',
            ],
            'keeps query'         => [
                'https://api.inngest.com/',
                '/fn/register?deployId=1',
                'https://api.inngest.com/fn/register?deployId=1',
            ],
            'root path added'     => [
                'http://host:1',
                'http://other:2',
                'http://other:2/',
            ],
        ];
    }

    /**
     * Malformed URLs throw
     *
     * @return void
     */
    public function testResolveInvalid(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(2101);
        Url::resolve('http://:80', 'x');
    }

    /**
     * Normalization matches JS `new URL(normalizeUrl(x)).href`
     *
     * @param string      $input    URL
     * @param string|null $expected Result
     *
     * @return void
     */
    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $input, ?string $expected): void {
        $this->assertSame($expected, Url::normalize($input));
    }

    /**
     * Cases for testNormalize
     *
     * @return array<string, array{string, ?string}>
     */
    public static function normalizeProvider(): array {
        return [
            'scheme and port' => [
                'http://localhost:3000',
                'http://localhost:3000/',
            ],
            'no scheme'       => ['0.0.0.0:8288', 'http://0.0.0.0:8288/'],
            'keeps path'      => [
                'https://dev.example.com/base',
                'https://dev.example.com/base',
            ],
            'no host'         => ['http://', null],
            'malformed'       => ['http://:80', null],
        ];
    }
}
