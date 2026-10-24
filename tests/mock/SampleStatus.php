<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

/**
 * Backed enum used to test value object enum handling
 *
 * @package DealNews\Inngest\Tests\mock
 */
enum SampleStatus: string {
    case Active   = 'active';
    case Disabled = 'disabled';
}
