<?php

declare(strict_types=1);

namespace DealNews\Inngest\Config;

/**
 * Whether the SDK talks to a local Dev Server or Inngest Cloud
 *
 * Dev mode skips signature verification and defaults to the local Dev
 * Server URL. Cloud mode requires a signing key.
 *
 * @package DealNews\Inngest\Config
 */
enum Mode: string {
    case Dev   = 'dev';
    case Cloud = 'cloud';
}
