<?php

declare(strict_types=1);

namespace DealNews\Inngest\Api;

/**
 * Thrown when Inngest rejects a checkpoint because the request is stale
 *
 * Port of JS `StaleDispatchError` (api/api.ts). A `409` from the
 * checkpoint endpoint means Inngest already handed the run to another
 * request, so this one must stop without running more steps. The name
 * matches JS because it's sent to Inngest as the error name.
 *
 * @package DealNews\Inngest\Api
 */
class StaleDispatchError extends ApiException {
}
