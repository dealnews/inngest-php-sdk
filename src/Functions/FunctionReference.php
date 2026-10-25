<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions;

/**
 * Points at a function by ID, for `step->invoke()`
 *
 * Port of JS `referenceFunction()` (components/InngestFunctionReference.ts).
 * Use it to invoke a function in another app, or one whose definition you
 * don't want to load.
 *
 * Usage:
 *
 * ```php
 * $ctx->step->invoke('bill', new FunctionReference('charge', 'billing'));
 * ```
 *
 * @package DealNews\Inngest\Functions
 */
class FunctionReference {

    /**
     * Creates the reference
     *
     * @param string      $function_id Function ID, without the app prefix
     * @param string|null $app_id      App the function lives in; defaults to
     *                                 the calling client's app
     */
    public function __construct(
        public readonly string $function_id,
        public readonly ?string $app_id = null
    ) {
    }

    /**
     * Returns the full ID Inngest knows the function by
     *
     * @param string $default_app_id App ID to use when none was given
     *
     * @return string
     */
    public function absoluteId(string $default_app_id): string {
        $app_id = $this->app_id !== null && $this->app_id !== '' ?
            $this->app_id :
            $default_app_id;

        return implode(
            '-',
            array_filter(
                [$app_id, $this->function_id],
                static fn (string $part) => $part !== ''
            )
        );
    }
}
