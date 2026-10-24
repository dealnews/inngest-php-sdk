<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Value object using constructor property promotion
 *
 * @package DealNews\Inngest\Tests\mock
 */
class SamplePromoted extends ValueObject {

    /**
     * Creates the object
     *
     * @param string   $label Label
     * @param int|null $size  Optional size
     */
    public function __construct(
        public string $label = 'default',
        public ?int $size = null
    ) {
    }
}
