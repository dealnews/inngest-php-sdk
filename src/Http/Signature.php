<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

/**
 * Signs and verifies Inngest requests and responses
 *
 * Port of the signing helpers in helpers/net.ts and helpers/strings.ts,
 * plus `RequestSignature` from InngestCommHandler.ts.
 *
 * A signature is an HMAC-SHA256 of `body . timestamp`, keyed with the
 * signing key minus its `signkey-<env>-` prefix, sent as
 * `X-Inngest-Signature: t=<unix seconds>&s=<hex>`.
 *
 * Usage:
 *
 * ```php
 * $signature = new Signature();
 * $key_used  = $signature->verify($header, $raw_body, $key, $fallback);
 * $response_header = $signature->signatureHeader($response_body, $key_used);
 * ```
 *
 * Edge cases:
 *
 * - Always pass the **raw** body string. Never re-encode decoded JSON
 *   (plan decision D7).
 * - Signatures more than 5 minutes old (or in the future) are rejected.
 * - When the primary key fails, the fallback key is tried; the key that
 *   worked is returned so responses can be signed with it.
 *
 * @package DealNews\Inngest\Http
 */
class Signature {

    /**
     * How far a signature's timestamp may be from now, in seconds
     *
     * @var int
     */
    public const MAX_AGE_SECONDS = 300;

    /**
     * Pattern matching a signing key's prefix, e.g. `signkey-prod-`
     *
     * @var string
     */
    public const KEY_PREFIX_PATTERN = '/^signkey-\\w+-/';

    /**
     * Computes the HMAC for a body and timestamp
     *
     * @param string $body        Raw body
     * @param string $signing_key Signing key, with or without prefix
     * @param string $timestamp   Unix timestamp in seconds, as sent
     *
     * @return string Hex HMAC-SHA256
     */
    public function sign(
        string $body,
        string $signing_key,
        string $timestamp
    ): string {
        return hash_hmac(
            'sha256',
            $body . $timestamp,
            static::removeKeyPrefix($signing_key)
        );
    }

    /**
     * Builds an `X-Inngest-Signature` header value for a response body
     *
     * @param string   $body        Exact response body being sent
     * @param string   $signing_key Key that verified the request
     * @param int|null $now         Unix time in seconds; defaults to now
     *
     * @return string
     */
    public function signatureHeader(
        string $body,
        string $signing_key,
        ?int $now = null
    ): string {
        $timestamp = (string)($now ?? (int)round(microtime(true)));

        return 't=' . $timestamp . '&s=' .
               $this->sign($body, $signing_key, $timestamp);
    }

    /**
     * Verifies a request signature, trying the fallback key if needed
     *
     * @param string      $header        `X-Inngest-Signature` value
     * @param string      $body          Raw request body
     * @param string      $signing_key   Signing key
     * @param string|null $fallback_key  Fallback signing key, if any
     * @param bool        $allow_expired Skip the timestamp check
     * @param float|null  $now_ms        Unix time in milliseconds; defaults
     *                                   to now
     *
     * @throws SignatureException When verification fails
     *
     * @return string The signing key that verified the request
     */
    public function verify(
        string $header,
        string $body,
        string $signing_key,
        ?string $fallback_key = null,
        bool $allow_expired = false,
        ?float $now_ms = null
    ): string {
        $parsed = static::parseHeader($header);

        try {
            $this->verifyWithKey(
                $parsed,
                $body,
                $signing_key,
                $allow_expired,
                $now_ms
            );
            $result = $signing_key;
        } catch (SignatureException $e) {
            if ($fallback_key === null || $fallback_key === '') {
                throw $e;
            }

            $this->verifyWithKey(
                $parsed,
                $body,
                $fallback_key,
                $allow_expired,
                $now_ms
            );
            $result = $fallback_key;
        }

        return $result;
    }

    /**
     * Removes the `signkey-<env>-` prefix from a signing key
     *
     * @param string $signing_key Signing key
     *
     * @return string
     */
    public static function removeKeyPrefix(string $signing_key): string {
        return (string)preg_replace(
            self::KEY_PREFIX_PATTERN,
            '',
            $signing_key
        );
    }

    /**
     * Hashes a signing key for use as an API bearer token
     *
     * Keeps the prefix and SHA-256 hashes the hex-decoded key, matching the
     * JS SDK's `hashSigningKey` (including how hash.js decodes hex).
     *
     * @param string|null $signing_key Signing key
     *
     * @return string Empty string when there's no key
     */
    public static function hashSigningKey(?string $signing_key): string {
        $result = '';

        if ($signing_key !== null && $signing_key !== '') {
            $matches = [];
            preg_match(self::KEY_PREFIX_PATTERN, $signing_key, $matches);
            $prefix = $matches[0] ?? '';
            $bytes  = static::hexToBytes(static::removeKeyPrefix($signing_key));
            $result = $prefix . hash('sha256', $bytes);
        }

        return $result;
    }

    /**
     * Hashes an event key (SHA-256 hex), as reported in introspection
     *
     * @param string $event_key Event key
     *
     * @return string
     */
    public static function hashEventKey(string $event_key): string {
        return hash('sha256', $event_key);
    }

    /**
     * Checks one key against a parsed signature
     *
     * @param array{t: string, s: string} $parsed        Parsed header
     * @param string                      $body          Raw body
     * @param string                      $signing_key   Key to try
     * @param bool                        $allow_expired Skip expiry check
     * @param float|null                  $now_ms        Now, in ms
     *
     * @throws SignatureException When the signature is expired or wrong
     *
     * @return void
     */
    protected function verifyWithKey(
        array $parsed,
        string $body,
        string $signing_key,
        bool $allow_expired,
        ?float $now_ms
    ): void {
        if (!$allow_expired && static::hasExpired($parsed['t'], $now_ms)) {
            throw new SignatureException('Signature has expired', 5002);
        }

        $expected = $this->sign($body, $signing_key, $parsed['t']);
        if (!hash_equals($expected, $parsed['s'])) {
            throw new SignatureException('Invalid signature', 5003);
        }
    }

    /**
     * Parses `t=...&s=...` like JS `URLSearchParams` (first value wins)
     *
     * @param string $header Header value
     *
     * @throws SignatureException When `t` or `s` is missing or empty
     *
     * @return array{t: string, s: string}
     */
    protected static function parseHeader(string $header): array {
        $values = [];

        foreach (explode('&', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            $key   = urldecode($parts[0]);
            if (!array_key_exists($key, $values)) {
                $values[$key] = urldecode($parts[1] ?? '');
            }
        }

        $timestamp = $values['t'] ?? '';
        $signature = $values['s'] ?? '';
        if ($timestamp === '' || $signature === '') {
            throw new SignatureException(
                'Invalid ' . Headers::SIGNATURE . ' provided',
                5001
            );
        }

        return ['t' => $timestamp, 's' => $signature];
    }

    /**
     * Whether a signature timestamp is outside the allowed window
     *
     * Parses the timestamp like JS `parseInt(t, 10)` (leading digits only).
     *
     * @param string     $timestamp Timestamp from the header
     * @param float|null $now_ms    Now, in milliseconds
     *
     * @return bool
     */
    protected static function hasExpired(
        string $timestamp,
        ?float $now_ms
    ): bool {
        $matches = [];
        if (!preg_match('/^\\s*([+-]?\\d+)/', $timestamp, $matches)) {
            $result = true;
        } else {
            $now_ms ??= microtime(true) * 1000;
            $delta  = $now_ms - ((int)$matches[1] * 1000);
            $result = abs($delta) > self::MAX_AGE_SECONDS * 1000;
        }

        return $result;
    }

    /**
     * Decodes hex the way hash.js does for `update(key, "hex")`
     *
     * Non-alphanumeric characters are dropped, odd lengths get a leading
     * `0`, and each pair is read like JS `parseInt(pair, 16)`: leading hex
     * digits only, and `0` when the pair doesn't start with one.
     *
     * @param string $hex Hex string
     *
     * @return string Raw bytes
     */
    protected static function hexToBytes(string $hex): string {
        $hex = (string)preg_replace('/[^a-z0-9]+/i', '', $hex);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }

        $bytes = '';
        foreach (str_split($hex, 2) as $pair) {
            if (ctype_xdigit($pair)) {
                $value = hexdec($pair);
            } elseif (ctype_xdigit($pair[0])) {
                $value = hexdec($pair[0]);
            } else {
                $value = 0;
            }
            $bytes .= chr((int)$value);
        }

        return $bytes;
    }
}
