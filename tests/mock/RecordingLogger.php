<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that records every call for assertions
 *
 * @package DealNews\Inngest\Tests\mock
 */
class RecordingLogger extends AbstractLogger {

    /**
     * Logged records, in order
     *
     * Each record has `level`, `message`, and `context` keys.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [];

    /**
     * Records a log call
     *
     * @param mixed              $level   Log level
     * @param string|\Stringable $message Message
     * @param array<mixed>       $context Context
     *
     * @return void
     */
    public function log(
        $level,
        string|\Stringable $message,
        array $context = []
    ): void {
        $this->records[] = [
            'level'   => $level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }
}
