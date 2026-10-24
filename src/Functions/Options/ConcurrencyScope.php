<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

/**
 * What a concurrency limit applies across
 *
 * @package DealNews\Inngest\Functions\Options
 */
enum ConcurrencyScope: string {
    /** Per function (the default) */
    case Function = 'fn';

    /** Across the environment */
    case Environment = 'env';

    /** Across the whole account */
    case Account = 'account';
}
