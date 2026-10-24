<?php

declare(strict_types=1);

namespace DealNews\Inngest\Functions\Options;

/**
 * What happens to a new run when one is already active
 *
 * @package DealNews\Inngest\Functions\Options
 */
enum SingletonMode: string {
    /** Skip the new run */
    case Skip = 'skip';

    /** Cancel the active run and start the new one */
    case Cancel = 'cancel';
}
