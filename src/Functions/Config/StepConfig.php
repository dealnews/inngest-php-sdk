<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Config;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * The single "step" entry in a function's registration config
 *
 * Every function registers one step, `step`, pointing at the serve URL.
 *
 * @package DealNews\Inngest\Functions\Config
 */
class StepConfig extends ValueObject {

    /**
     * Creates the step config
     *
     * @param string           $id      Step ID (always `step`)
     * @param string           $name    Step name (always `step`)
     * @param StepRuntime      $runtime How Inngest calls the step
     * @param StepRetries|null $retries Retry settings, when configured
     */
    public function __construct(
        public string $id = 'step',
        public string $name = 'step',
        public StepRuntime $runtime = new StepRuntime(),
        public ?StepRetries $retries = null
    ) {
    }
}
