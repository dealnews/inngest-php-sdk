<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

/**
 * Thrown when a request signature is missing, malformed, expired, or wrong
 *
 * Codes:
 * - 5001: malformed `X-Inngest-Signature` header
 * - 5002: signature expired
 * - 5003: signature doesn't match
 *
 * @package DealNews\Inngest\Http
 */
class SignatureException extends \RuntimeException {
}
