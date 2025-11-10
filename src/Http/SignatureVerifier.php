<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

use DealNews\Inngest\Config\Config;
use DealNews\Inngest\Error\InngestException;

/**
 * Handles signature verification for incoming requests
 */
class SignatureVerifier
{
    public function __construct(protected Config $config)
    {
    }

    /**
     * @throws InngestException
     */
    public function verify(string $body, ?string $signature_header, ?string $server_kind = null): void
    {
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

    protected function verifySignature(string $body, string $signature_header, string $signing_key): bool
    {
        parse_str($signature_header, $parts);
        
        if (!isset($parts['t']) || !isset($parts['s'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        $signature = $parts['s'];

        $current_time = time();
        if (abs($current_time - $timestamp) > 300) {
            return false;
        }

        $key = $this->extractKey($signing_key);
        $mac = hash_hmac('sha256', $body . $timestamp, $key);

        return hash_equals($signature, $mac);
    }

    protected function extractKey(string $signing_key): string
    {
        if (str_starts_with($signing_key, 'signkey-')) {
            $parts = explode('-', $signing_key, 3);
            if (count($parts) === 3) {
                return $parts[2];
            }
        }

        return $signing_key;
    }

    public function signRequest(string $body, string $signing_key): string
    {
        $timestamp = time();
        $key = $this->extractKey($signing_key);
        $mac = hash_hmac('sha256', $body . $timestamp, $key);

        return "t={$timestamp}&s={$mac}";
    }

    public function hashSigningKey(string $signing_key): string
    {
        $key = $this->extractKey($signing_key);
        $hashed = hash('sha256', $key);
        
        if (str_starts_with($signing_key, 'signkey-')) {
            $parts = explode('-', $signing_key, 3);
            return "signkey-{$parts[1]}-{$hashed}";
        }

        return $hashed;
    }
}
