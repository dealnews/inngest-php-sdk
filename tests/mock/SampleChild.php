<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Nested value object used by ValueObject tests
 *
 * @package DealNews\Inngest\Tests\mock
 */
class SampleChild extends ValueObject {

    protected const WIRE_KEYS = [
        'child_name' => 'childName',
    ];

    /**
     * Child name, encoded as `childName`
     *
     * @var string
     */
    public string $child_name = '';

    /**
     * Optional count
     *
     * @var int|null
     */
    public ?int $count = null;
}
