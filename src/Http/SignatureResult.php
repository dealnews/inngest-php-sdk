<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

/**
 * Outcome of checking a request's signature
 *
 * In dev mode every request "passes" without a key, so `key_used` is empty
 * and responses aren't signed.
 *
 * @package DealNews\Inngest\Http
 */
class SignatureResult {

    /**
     * Creates the result
     *
     * @param bool            $success  Whether the request may proceed
     * @param string          $key_used Signing key that verified it; empty
     *                                  when no key was checked
     * @param \Throwable|null $error    Why verification failed
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $key_used = '',
        public readonly ?\Throwable $error = null
    ) {
    }

    /**
     * Whether the request was verified with a signing key
     *
     * @return bool
     */
    public function isSigned(): bool {
        return $this->success && $this->key_used !== '';
    }
}
