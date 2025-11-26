<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Unit\Http;

use DealNews\Inngest\Config\Config;
use DealNews\Inngest\Error\InngestException;
use DealNews\Inngest\Http\SignatureVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SignatureVerifier class
 *
 * Verifies signature verification logic including:
 * - JSON canonicalization
 * - HMAC-SHA256 signature generation and validation
 * - Timestamp expiration
 * - Fallback key support
 * - Dev mode bypass
 */
class SignatureVerifierTest extends TestCase
{
    protected const SIGNING_KEY = 'signkey-test-0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    protected const FALLBACK_KEY = 'signkey-test-fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

    /**
     * Test signature verification succeeds with valid signature
     */
    public function testVerifySucceedsWithValidSignature(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(false);
        $config->method('getSigningKey')->willReturn(self::SIGNING_KEY);

        $verifier = new SignatureVerifier($config);

        $body = '{"event":"test","data":{"foo":"bar"}}';
        $signature = $verifier->signRequest($body, self::SIGNING_KEY);

        $verifier->verify($body, $signature);

        $this->assertTrue(true);
    }

    /**
     * Test signature verification fails with invalid signature
     */
    public function testVerifyFailsWithInvalidSignature(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(false);
        $config->method('getSigningKey')->willReturn(self::SIGNING_KEY);
        $config->method('getSigningKeyFallback')->willReturn(null);

        $verifier = new SignatureVerifier($config);

        $body = '{"event":"test"}';
        $timestamp = time();
        $invalid_signature = "t={$timestamp}&s=invalid_signature_value";

        $this->expectException(InngestException::class);
        $this->expectExceptionMessage('Invalid signature');

        $verifier->verify($body, $invalid_signature);
    }

    /**
     * Test signature verification fails with expired timestamp
     */
    public function testVerifyFailsWithExpiredTimestamp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(false);
        $config->method('getSigningKey')->willReturn(self::SIGNING_KEY);
        $config->method('getSigningKeyFallback')->willReturn(null);

        $verifier = new SignatureVerifier($config);

        $body = '{"event":"test"}';
        $expired_timestamp = time() - 400;
        $key = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $mac = hash_hmac('sha256', $body . $expired_timestamp, $key);
        $signature = "t={$expired_timestamp}&s={$mac}";

        $this->expectException(InngestException::class);
        $this->expectExceptionMessage('Invalid signature');

        $verifier->verify($body, $signature);
    }

    /**
     * Test signature verification uses fallback key when primary fails
     */
    public function testVerifyUsesFallbackKey(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(false);
        $config->method('getSigningKey')->willReturn(self::SIGNING_KEY);
        $config->method('getSigningKeyFallback')->willReturn(self::FALLBACK_KEY);

        $verifier = new SignatureVerifier($config);

        $body = '{"event":"test"}';
        $signature = $verifier->signRequest($body, self::FALLBACK_KEY);

        $verifier->verify($body, $signature);

        $this->assertTrue(true);
    }

    /**
     * Test signature verification bypasses in dev mode
     */
    public function testVerifyBypassesInDevMode(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(true);

        $verifier = new SignatureVerifier($config);

        $verifier->verify('any body', null, 'dev');

        $this->assertTrue(true);
    }

    /**
     * Test signature verification fails when missing signature header
     */
    public function testVerifyFailsWithMissingSignatureHeader(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(false);
        $config->method('getSigningKey')->willReturn(self::SIGNING_KEY);

        $verifier = new SignatureVerifier($config);

        $this->expectException(InngestException::class);
        $this->expectExceptionMessage('Missing X-Inngest-Signature header');

        $verifier->verify('{"event":"test"}', null);
    }

    /**
     * Test signature verification fails when no signing key configured
     */
    public function testVerifyFailsWithNoSigningKey(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(false);
        $config->method('getSigningKey')->willReturn(null);

        $verifier = new SignatureVerifier($config);

        $this->expectException(InngestException::class);
        $this->expectExceptionMessage('No signing key configured');

        $verifier->verify('{"event":"test"}', 't=123&s=abc');
    }

    /**
     * Test signing key hashing for Authorization header
     */
    public function testHashSigningKey(): void
    {
        $config = $this->createMock(Config::class);
        $verifier = new SignatureVerifier($config);

        $signing_key = 'signkey-prod-0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $hashed = $verifier->hashSigningKey($signing_key);

        $this->assertStringStartsWith('signkey-prod-', $hashed);

        $parts = explode('-', $hashed, 3);
        $this->assertCount(3, $parts);
        $this->assertEquals('signkey', $parts[0]);
        $this->assertEquals('prod', $parts[1]);
        $this->assertEquals(64, strlen($parts[2]), 'SHA256 hash should be 64 hex chars');
    }

    /**
     * Test signing key hashing without prefix
     */
    public function testHashSigningKeyWithoutPrefix(): void
    {
        $config = $this->createMock(Config::class);
        $verifier = new SignatureVerifier($config);

        $raw_key = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $hashed = $verifier->hashSigningKey($raw_key);

        $this->assertEquals(64, strlen($hashed), 'SHA256 hash should be 64 hex chars');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashed);
    }

    /**
     * Test signature verification with malformed signature header
     */
    public function testVerifyFailsWithMalformedSignatureHeader(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(false);
        $config->method('getSigningKey')->willReturn(self::SIGNING_KEY);
        $config->method('getSigningKeyFallback')->willReturn(null);

        $verifier = new SignatureVerifier($config);

        $this->expectException(InngestException::class);
        $this->expectExceptionMessage('Invalid signature');

        $verifier->verify('{"event":"test"}', 'malformed');
    }

    /**
     * Test signature verification with empty body
     */
    public function testVerifyWithEmptyBody(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(false);
        $config->method('getSigningKey')->willReturn(self::SIGNING_KEY);

        $verifier = new SignatureVerifier($config);

        $body = '';
        $signature = $verifier->signRequest($body, self::SIGNING_KEY);

        $verifier->verify($body, $signature);

        $this->assertTrue(true);
    }

    /**
     * Test signature verification with non-JSON body
     *
     * Non-JSON bodies should not be canonicalized
     */
    public function testVerifyWithNonJsonBody(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(false);
        $config->method('getSigningKey')->willReturn(self::SIGNING_KEY);

        $verifier = new SignatureVerifier($config);

        $body = 'plain text body';
        $signature = $verifier->signRequest($body, self::SIGNING_KEY);

        $verifier->verify($body, $signature);

        $this->assertTrue(true);
    }

    /**
     * Test signature verification accepts timestamp within 5 minute window
     */
    public function testVerifyAcceptsTimestampWithinFiveMinutes(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isDev')->willReturn(false);
        $config->method('getSigningKey')->willReturn(self::SIGNING_KEY);

        $verifier = new SignatureVerifier($config);

        $body = '{"event":"test"}';

        $timestamp_4_min_ago = time() - 240;
        $key = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $mac = hash_hmac('sha256', $body . $timestamp_4_min_ago, $key);
        $signature = "t={$timestamp_4_min_ago}&s={$mac}";

        $verifier->verify($body, $signature);

        $this->assertTrue(true);
    }
}
