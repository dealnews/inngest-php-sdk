<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

use DealNews\Inngest\Config\Config;
use DealNews\Inngest\Error\InngestException;

/**
 * Handles signature verification for incoming requests per SDK spec section 4.1.3
 *
 * Implements request signature verification using HMAC-SHA256. This ensures requests
 * from Inngest Cloud are authentic and haven't been tampered with.
 */
class SignatureVerifier {
    public function __construct(protected Config $config) {
    }

    /**
     * Verify request signature from Inngest server
     *
     * Validates the X-Inngest-Signature header to ensure the request is authentic.
     * In dev mode, skips verification but warns if the dev server header is missing.
     * Supports fallback signing key for key rotation scenarios.
     *
     * @param string      $body             Raw request body
     * @param string|null $signature_header Value of X-Inngest-Signature header (format: t={timestamp}&s={signature})
     * @param string|null $server_kind      Value of X-Inngest-Server-Kind header (expected: 'dev' in dev mode)
     *
     * @throws InngestException If signature validation fails or required config is missing
     */
    public function verify(string $body, ?string $signature_header, ?string $server_kind = null): void {
        if ($this->config->isDev()) {
            if ($server_kind !== 'dev') {
                error_log('Warning: Expected dev server but did not receive X-Inngest-Server-Kind: dev header');
            }
            return;
        }

        $signing_key = $this->config->getSigningKey();
        if ($signing_key === null) {
            throw new InngestException('No signing key configured');
        }

        if ($signature_header === null) {
            throw new InngestException('Missing X-Inngest-Signature header');
        }

        if (!$this->verifySignature($body, $signature_header, $signing_key)) {
            $fallback_key = $this->config->getSigningKeyFallback();
            if ($fallback_key !== null && $this->verifySignature($body, $signature_header, $fallback_key)) {
                return;
            }

            throw new InngestException('Invalid signature');
        }
    }

    /**
     * Verify HMAC-SHA256 signature matches expected value
     *
     * Implements SDK spec section 4.1.3 signature verification algorithm:
     * 1. Parse timestamp and signature from header
     * 2. Validate timestamp is within 5 minutes (300 seconds)
     * 3. Calculate HMAC-SHA256 of body + timestamp
     * 4. Compare calculated vs provided signature using constant-time comparison
     *
     * @param string $body             Request body
     * @param string $signature_header Signature header value (format: t={timestamp}&s={signature})
     * @param string $signing_key      Signing key with or without signkey-{env}- prefix
     *
     * @return bool True if signature is valid, false otherwise
     */
    protected function verifySignature(string $body, string $signature_header, string $signing_key): bool {
        parse_str($signature_header, $parts);

        if (!isset($parts['t']) || !isset($parts['s'])) {
            return false;
        }

        $timestamp = (int)$parts['t'];
        $signature = $parts['s'];

        $current_time = time();
        if (abs($current_time - $timestamp) > 300) {
            return false;
        }

        $key    = $this->extractKey($signing_key);
        $mac    = hash_hmac('sha256', $body . $timestamp, $key);
        $result = hash_equals($signature, $mac);

        if (!$result) {
            // Fix escaped Unicode characters that don't need to be escaped (only for valid JSON)
            if (!empty($body)) {
                $decoded = json_decode($body);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $body = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            $mac    = hash_hmac('sha256', $body . $timestamp, $key);
            $result = hash_equals($signature, $mac);
        }

        return $result;
    }

    /**
     * Extract the hex-encoded key portion from signing key
     *
     * Signing keys have format: signkey-{env}-{hex_key}
     * This removes the prefix and returns just the hex key portion.
     *
     * @param string $signing_key Full signing key (e.g., signkey-prod-abc123...)
     *
     * @return string Hex-encoded key without prefix
     */
    protected function extractKey(string $signing_key): string {
        if (str_starts_with($signing_key, 'signkey-')) {
            $parts = explode('-', $signing_key, 3);
            if (count($parts) === 3) {
                return $parts[2];
            }
        }

        return $signing_key;
    }

    /**
     * Sign a request body for outgoing requests to Inngest
     *
     * Generates an X-Inngest-Signature header value by creating an HMAC-SHA256
     * of the body + current timestamp.
     *
     * @param string $body        Request body to sign
     * @param string $signing_key Signing key with or without signkey-{env}- prefix
     *
     * @return string Signature header value (format: t={timestamp}&s={signature})
     */
    public function signRequest(string $body, string $signing_key): string {
        $timestamp = time();
        $key       = $this->extractKey($signing_key);
        $mac       = hash_hmac('sha256', $body . $timestamp, $key);

        return "t={$timestamp}&s={$mac}";
    }

    /**
     * Hash signing key for Authorization header
     *
     * Per SDK spec section 4.1.4: "The value of this bearer token should be the
     * Signing Key, where the value following the signkey-*- prefix is a hex-encoded
     * SHA256 hash of that value."
     *
     * Takes: signkey-prod-abc123
     * Returns: signkey-prod-{sha256(hex2bin("abc123"))}
     *
     * @param string $signing_key Full signing key (e.g., signkey-prod-abc123...)
     *
     * @return string Hashed signing key in same format
     */
    public function hashSigningKey(string $signing_key): string {
        $key    = $this->extractKey($signing_key);
        $hashed = hash('sha256', hex2bin($key));

        if (str_starts_with($signing_key, 'signkey-')) {
            $parts = explode('-', $signing_key, 3);
            return "signkey-{$parts[1]}-{$hashed}";
        }

        return $hashed;
    }
}
