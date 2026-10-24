<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\Version;

/**
 * Version that reports a fixed version instead of asking Composer
 *
 * @package DealNews\Inngest\Tests\mock
 */
class FixedVersion extends Version {

    /**
     * Creates the version
     *
     * @param string|null $fixed Version to report (null → "dev")
     */
    public function __construct(protected ?string $fixed = '1.2.3') {
    }

    /**
     * Returns the fixed version
     *
     * @param string $package Ignored
     *
     * @return string|null
     */
    protected function composerVersion(string $package = self::NAME): ?string {
        return $this->fixed;
    }
}
