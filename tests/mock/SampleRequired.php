<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Value object whose constructor has a required parameter
 *
 * @package DealNews\Inngest\Tests\mock
 */
class SampleRequired extends ValueObject {

    /**
     * Name
     *
     * @var string
     */
    public string $name = 'unset';

    /**
     * Creates the object
     *
     * @param string $name Name
     */
    public function __construct(string $name) {
        $this->name = strtoupper($name);
    }
}
