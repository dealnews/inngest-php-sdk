<?php

declare(strict_types=1);

namespace DealNews\Inngest\Execution;

/**
 * A function run couldn't be set up or driven
 *
 * Codes:
 *
 * - 7001: the request body from Inngest couldn't be parsed
 * - 7002: run data couldn't be fetched from the API (`use_api`)
 * - 7101: a step tool was called outside the function handler
 * - 7102: the run stalled: steps were found, but none can run or resolve
 * - 7103: a step tool was called after the request ended (from a
 *   `finally` block while a paused function was cleaned up)
 *
 * Related `\LogicException` codes: 7201 (no free step ID index), 7202
 * (failure handler missing).
 *
 * @package DealNews\Inngest\Execution
 */
class ExecutionException extends \RuntimeException {
}
