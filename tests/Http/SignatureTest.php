<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Http;

use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Http\SignatureException;
use DealNews\Inngest\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for Signature
 *
 * Golden values in tests/fixtures/signing/js-golden.json come from the JS
 * SDK's own hash.js code (see generate.cjs there). The verification cases
 * port the JS `RequestSignature` tests.
 *
 * @package DealNews\Inngest\Tests\Http
 */
#[CoversClass(Signature::class)]
#[CoversClass(SignatureException::class)]
class SignatureTest extends TestCase {

    /**
     * Signing key from the JS RequestSignature tests
     *
     * @var string
     */
    public const KEY = 'signkey-test-deadbeefcafef00d';

    /**
     * Raw body used for verification tests
     *
     * @var string
     */
    public const BODY = '{"event":{"name":"demo/event.sent","data":{}}}';

    /**
     * Fixed "now": 2026-04-22T12:00:00Z, in milliseconds
     *
     * @var float
     */
    public const NOW_MS = 1776859200000.0;

    /**
     * Fixed "now" in seconds
     *
     * @var int
     */
    public const NOW_S = 1776859200;

    /**
     * HMACs match the JS SDK byte for byte
     *
     * @param string $body Body
     * @param string $key  Signing key
     * @param string $ts   Timestamp
     * @param string $mac  HMAC computed by JS
     *
     * @return void
     */
    #[DataProvider('signProvider')]
    public function testSignMatchesJs(
        string $body,
        string $key,
        string $ts,
        string $mac
    ): void {
        $this->assertSame($mac, (new Signature())->sign($body, $key, $ts));
    }

    /**
     * Cases from the JS golden fixture
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function signProvider(): array {
        $cases = [];
        foreach (static::golden()['sign'] as $index => $case) {
            $cases["case $index"] = [
                $case['body'],
                $case['key'],
                $case['ts'],
                $case['mac'],
            ];
        }

        return $cases;
    }

    /**
     * Signing key hashes match the JS SDK, including odd hex handling
     *
     * @param string $key  Signing key
     * @param string $hash Hash computed by JS
     *
     * @return void
     */
    #[DataProvider('hashSigningKeyProvider')]
    public function testHashSigningKeyMatchesJs(
        string $key,
        string $hash
    ): void {
        $this->assertSame($hash, Signature::hashSigningKey($key));
    }

    /**
     * Cases from the JS golden fixture
     *
     * @return array<string, array{string, string}>
     */
    public static function hashSigningKeyProvider(): array {
        $cases = [];
        foreach (static::golden()['hashSigningKey'] as $case) {
            $cases[$case['key']] = [$case['key'], $case['hash']];
        }

        return $cases;
    }

    /**
     * No key hashes to an empty string
     *
     * @return void
     */
    public function testHashSigningKeyEmpty(): void {
        $this->assertSame('', Signature::hashSigningKey(null));
        $this->assertSame('', Signature::hashSigningKey(''));
    }

    /**
     * Event key hashes match the JS SDK
     *
     * @return void
     */
    public function testHashEventKeyMatchesJs(): void {
        foreach (static::golden()['hashEventKey'] as $case) {
            $this->assertSame(
                $case['hash'],
                Signature::hashEventKey($case['key'])
            );
        }
    }

    /**
     * The prefix is stripped before keying the HMAC
     *
     * @return void
     */
    public function testRemoveKeyPrefix(): void {
        $this->assertSame(
            'mykey',
            Signature::removeKeyPrefix('signkey-prod-mykey')
        );
        $this->assertSame('mykey', Signature::removeKeyPrefix('mykey'));
    }

    /**
     * Valid signatures verify and return the key used
     *
     * @param int  $offset        Seconds from now for the timestamp
     * @param bool $allow_expired Skip the expiry check
     *
     * @return void
     */
    #[DataProvider('validTimestampProvider')]
    public function testVerifyAccepts(int $offset, bool $allow_expired): void {
        $this->assertSame(
            self::KEY,
            $this->verify((string)(self::NOW_S + $offset), $allow_expired)
        );
    }

    /**
     * Cases for testVerifyAccepts (JS RequestSignature tests)
     *
     * @return array<string, array{int, bool}>
     */
    public static function validTimestampProvider(): array {
        return [
            'now'                     => [0, false],
            '60s in the future'       => [60, false],
            'exactly 5 minutes old'   => [-300, false],
            'old, expiry allowed'     => [-3600, true],
            'future, expiry allowed'  => [3600, true],
        ];
    }

    /**
     * Timestamps outside the window, or unparseable, are rejected
     *
     * @param string $timestamp Timestamp to sign with
     *
     * @return void
     */
    #[DataProvider('expiredProvider')]
    public function testVerifyRejectsExpired(string $timestamp): void {
        $this->expectException(SignatureException::class);
        $this->expectExceptionCode(5002);
        $this->expectExceptionMessage('Signature has expired');
        $this->verify($timestamp);
    }

    /**
     * Cases for testVerifyRejectsExpired (JS RequestSignature tests)
     *
     * @return array<string, array{string}>
     */
    public static function expiredProvider(): array {
        return [
            'older than 5 minutes'    => [(string)(self::NOW_S - 301)],
            'future beyond 5 minutes' => [(string)(self::NOW_S + 301)],
            'not a number'            => ['not-a-number'],
            'hex prefixed'            => ['0x' . dechex(self::NOW_S)],
        ];
    }

    /**
     * Leading digits are read like JS parseInt
     *
     * @return void
     */
    public function testVerifyParsesLeadingDigits(): void {
        $this->assertSame(self::KEY, $this->verify(self::NOW_S . 'abc'));
    }

    /**
     * A tampered signature is rejected
     *
     * @return void
     */
    public function testVerifyRejectsTampered(): void {
        $signature = new Signature();
        $ts        = (string)self::NOW_S;
        $mac       = $signature->sign(self::BODY, self::KEY, $ts);
        $tampered  = ($mac[0] === '0' ? '1' : '0') . substr($mac, 1);

        $this->expectException(SignatureException::class);
        $this->expectExceptionCode(5003);
        $this->expectExceptionMessage('Invalid signature');
        $signature->verify(
            "t=$ts&s=$tampered",
            self::BODY,
            self::KEY,
            null,
            false,
            self::NOW_MS
        );
    }

    /**
     * A different body fails (raw bytes are what's signed)
     *
     * @return void
     */
    public function testVerifyRejectsReencodedBody(): void {
        $signature = new Signature();
        $ts        = (string)self::NOW_S;
        $mac       = $signature->sign(self::BODY, self::KEY, $ts);

        $this->expectException(SignatureException::class);
        $signature->verify(
            "t=$ts&s=$mac",
            '{"event": {"name": "demo/event.sent", "data": {}}}',
            self::KEY,
            null,
            false,
            self::NOW_MS
        );
    }

    /**
     * Malformed headers are rejected
     *
     * @param string $header Header value
     *
     * @return void
     */
    #[DataProvider('malformedProvider')]
    public function testVerifyRejectsMalformedHeader(string $header): void {
        $this->expectException(SignatureException::class);
        $this->expectExceptionCode(5001);
        $this->expectExceptionMessage(
            'Invalid ' . Headers::SIGNATURE . ' provided'
        );
        (new Signature())->verify($header, self::BODY, self::KEY);
    }

    /**
     * Cases for testVerifyRejectsMalformedHeader
     *
     * @return array<string, array{string}>
     */
    public static function malformedProvider(): array {
        return [
            'missing t' => ['s=abc'],
            'missing s' => ['t=123'],
            'empty t'   => ['t=&s=abc'],
            'empty'     => [''],
        ];
    }

    /**
     * The fallback key verifies when the primary key doesn't
     *
     * @return void
     */
    public function testVerifyFallbackKey(): void {
        $signature = new Signature();
        $fallback  = 'signkey-test-0ld0ld';
        $ts        = (string)self::NOW_S;
        $mac       = $signature->sign(self::BODY, $fallback, $ts);

        $used = $signature->verify(
            "t=$ts&s=$mac",
            self::BODY,
            self::KEY,
            $fallback,
            false,
            self::NOW_MS
        );

        $this->assertSame($fallback, $used);
    }

    /**
     * When both keys fail, the fallback's error is thrown
     *
     * @return void
     */
    public function testVerifyBothKeysFail(): void {
        $this->expectException(SignatureException::class);
        $this->expectExceptionCode(5003);
        (new Signature())->verify(
            't=' . self::NOW_S . '&s=deadbeef',
            self::BODY,
            self::KEY,
            'signkey-test-other',
            false,
            self::NOW_MS
        );
    }

    /**
     * Header parsing: first value wins and values are URL-decoded
     *
     * @return void
     */
    public function testHeaderParsing(): void {
        $signature = new Signature();
        $ts        = (string)self::NOW_S;
        $mac       = $signature->sign(self::BODY, self::KEY, $ts);

        $this->assertSame(self::KEY, $signature->verify(
            "t=$ts&s=$mac&s=ignored&extra",
            self::BODY,
            self::KEY,
            null,
            false,
            self::NOW_MS
        ));
    }

    /**
     * Without a fixed clock, a fresh signature verifies against real time
     *
     * @return void
     */
    public function testVerifyUsesCurrentTime(): void {
        $signature = new Signature();
        $header    = $signature->signatureHeader(self::BODY, self::KEY);

        $this->assertSame(
            self::KEY,
            $signature->verify($header, self::BODY, self::KEY)
        );
    }

    /**
     * Response signature headers have the `t=..&s=..` form
     *
     * @return void
     */
    public function testSignatureHeader(): void {
        $signature = new Signature();
        $header    = $signature->signatureHeader('[]', self::KEY, 1700000000);

        $this->assertSame(
            't=1700000000&s=' .
            $signature->sign('[]', self::KEY, '1700000000'),
            $header
        );
    }

    /**
     * Signs BODY at the given timestamp and verifies it at NOW_MS
     *
     * @param string $timestamp     Timestamp
     * @param bool   $allow_expired Skip the expiry check
     *
     * @return string Key used
     */
    protected function verify(
        string $timestamp,
        bool $allow_expired = false
    ): string {
        $signature = new Signature();
        $mac       = $signature->sign(self::BODY, self::KEY, $timestamp);

        return $signature->verify(
            "t=$timestamp&s=$mac",
            self::BODY,
            self::KEY,
            null,
            $allow_expired,
            self::NOW_MS
        );
    }

    /**
     * Loads the JS golden fixture
     *
     * @return array<string, mixed>
     */
    protected static function golden(): array {
        return json_decode(
            (string)file_get_contents(
                self::FIXTURE_DIR . '/signing/js-golden.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}
