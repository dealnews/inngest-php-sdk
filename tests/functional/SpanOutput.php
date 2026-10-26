<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

/**
 * Output of a trace span (a step attempt, a step, or the whole run)
 *
 * Like the JS `TimelineItem.getOutput()`: either decoded `data` or an
 * `error` array (`name`, `message`, `stack`, `cause`).
 *
 * @package DealNews\Inngest\Tests\functional
 */
class SpanOutput {

    /**
     * Creates the output
     *
     * @param mixed                     $data  Decoded data
     * @param array<string, mixed>|null $error Error, when the span failed
     */
    public function __construct(
        public mixed $data = null,
        public ?array $error = null
    ) {
    }

    /**
     * Builds the output from a `runTraceSpanOutputByID` result
     *
     * @param array<string, mixed> $raw GraphQL result
     *
     * @return self
     */
    public static function fromArray(array $raw): self {
        $data = null;
        if (isset($raw['data']) && is_string($raw['data'])) {
            try {
                $data = json_decode(
                    $raw['data'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException) {
                $data = $raw['data'];
            }
        }

        $error = $raw['error'] ?? null;
        if (is_array($error) && is_string($error['cause'] ?? null)) {
            $error['cause'] = json_decode($error['cause'], true);
        }

        return new self($data, is_array($error) ? $error : null);
    }
}
