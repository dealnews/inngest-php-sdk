<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\Http\Signature;

/**
 * Signature helper that accepts expired signatures
 *
 * Lets tests use fixed signatures captured from real Inngest requests
 * (JS: `__testingAllowExpiredSignatures`).
 *
 * @package DealNews\Inngest\Tests\mock
 */
class ExpiredOkSignature extends Signature {

    /**
     * Verifies a signature, ignoring its age
     *
     * @param string      $header        Header value
     * @param string      $body          Raw body
     * @param string      $signing_key   Signing key
     * @param string|null $fallback_key  Fallback key
     * @param bool        $allow_expired Ignored; always true
     * @param float|null  $now_ms        Now, in milliseconds
     *
     * @return string
     */
    public function verify(
        string $header,
        string $body,
        string $signing_key,
        ?string $fallback_key = null,
        bool $allow_expired = false,
        ?float $now_ms = null
    ): string {
        return parent::verify(
            $header,
            $body,
            $signing_key,
            $fallback_key,
            true,
            $now_ms
        );
    }
}
