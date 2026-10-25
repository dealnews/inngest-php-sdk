<?php

declare(strict_types=1);

namespace DealNews\Inngest\Middleware;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * The `$ctx->logger` handed to functions: quiet while steps replay
 *
 * Port of the JS `ProxyLogger` (middleware/logger.ts). Inngest reruns the
 * handler from the top on every request, so without this every log line
 * before the newest step would repeat once per step. Log calls are
 * dropped until `enable()` (called when replay is over).
 *
 * Edge cases:
 *
 * - Adds run details (`runID`, `eventName`) to each record's context;
 *   the record's own context wins on a clash. JS only does this for
 *   loggers with a `child()` method.
 *
 * @package DealNews\Inngest\Middleware
 */
class ProxyLogger extends AbstractLogger {

    /**
     * Whether log calls are passed on
     *
     * @var bool
     */
    protected bool $enabled = false;

    /**
     * Creates the logger
     *
     * @param LoggerInterface      $logger  Logger to pass calls to
     * @param array<string, mixed> $context Context added to every record
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected array $context = []
    ) {
    }

    /**
     * Starts passing log calls on
     *
     * @return void
     */
    public function enable(): void {
        $this->enabled = true;
    }

    /**
     * Stops passing log calls on
     *
     * @return void
     */
    public function disable(): void {
        $this->enabled = false;
    }

    /**
     * Whether log calls are passed on
     *
     * @return bool
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }

    /**
     * Logs a record, when enabled
     *
     * @param mixed                $level   Log level
     * @param string|\Stringable   $message Message
     * @param array<string, mixed> $context Context
     *
     * @return void
     */
    public function log(
        $level,
        string|\Stringable $message,
        array $context = []
    ): void {
        if ($this->enabled) {
            $this->logger->log(
                $level,
                $message,
                array_replace($this->context, $context)
            );
        }
    }
}
