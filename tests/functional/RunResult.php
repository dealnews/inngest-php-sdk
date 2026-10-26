<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

/**
 * How a run ended
 *
 * @package DealNews\Inngest\Tests\functional
 */
class RunResult {

    /**
     * Trace statuses that mean the run is over
     *
     * @var list<string>
     */
    public const END_STATUSES = ['COMPLETED', 'FAILED', 'CANCELLED'];

    /**
     * Creates the result
     *
     * @param string     $status Trace status (`COMPLETED`, `FAILED`, ...)
     * @param SpanOutput $output The run's output
     */
    public function __construct(
        public string $status,
        public SpanOutput $output
    ) {
    }
}
