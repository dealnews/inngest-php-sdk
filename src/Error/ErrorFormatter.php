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
     * Build the error array for a throwable, recursing into its cause chain
     *
     * @param  \Throwable $e Exception to format
     * @return array<string, mixed>
     */
    public static function format(\Throwable $e): array
    {
        $error = [
            'name' => get_class($e),
            'message' => $e->getMessage(),
            'stack' => $e->getTraceAsString(),
        ];

        $previous = $e->getPrevious();
        if ($previous !== null) {
            $error['cause'] = self::format($previous);
        }

        return $error;
    }
}
