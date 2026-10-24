<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

/**
 * A step's ID and optional display name
 *
 * Every step tool takes either a string ID or one of these.
 *
 * Usage:
 *
 * ```php
 * $ctx->step->run(new StepOptions('fetch-user', 'Fetch the user'), $fn);
 * ```
 *
 * @package DealNews\Inngest\Step
 */
class StepOptions {

    /**
     * Creates the options
     *
     * @param string      $id   Step ID; keep it stable between deploys
     * @param string|null $name Name shown in the Inngest UI; defaults to the
     *                          ID
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name = null
    ) {
    }

    /**
     * Normalizes a string ID or options to options
     *
     * @param string|self $id_or_options ID or options
     *
     * @return self
     */
    public static function from(string|self $id_or_options): self {
        return is_string($id_or_options) ?
            new self($id_or_options) :
            $id_or_options;
    }
}
