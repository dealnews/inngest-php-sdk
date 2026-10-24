<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

/**
 * A request to the serve endpoint couldn't be handled
 *
 * Codes:
 *
 * - 5101: unknown `probe` query parameter
 * - 5102: `POST` without a `fnId` query parameter
 * - 5103: `fnId` doesn't match any function
 * - 5104: in-band sync without a body
 * - 5105: in-band sync URL isn't an absolute URL
 *
 * @package DealNews\Inngest\Http
 */
class ServeException extends \RuntimeException {
}
