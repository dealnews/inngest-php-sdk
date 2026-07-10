<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

/**
 * Formats a thrown exception, including any chained previous exceptions,
 * into the array shape reported to Inngest.
 */
class ErrorFormatter
{
    /**
     * Maximum number of cause levels to report; deeper causes are dropped.
     */
    public const MAX_CAUSE_DEPTH = 10;

    /**
     * Build the error array for a throwable, recursing into its cause chain
     *
     * @param  \Throwable $e     Exception to format
     * @param  int        $depth Current depth in the cause chain
     * @return array<string, mixed>
     */
    public static function format(\Throwable $e, int $depth = 0): array
    {
        if ($e instanceof StepError) {
            return $e->toArray($depth);
        }

        $error = [
            'name' => get_class($e),
            'message' => $e->getMessage(),
            'stack' => $e->getTraceAsString(),
        ];

        $previous = $e->getPrevious();
        if ($previous !== null && $depth < self::MAX_CAUSE_DEPTH) {
            $error['cause'] = self::format($previous, $depth + 1);
        }

        return $error;
    }
}
