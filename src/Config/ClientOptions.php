<?php

declare(strict_types=1);

namespace DealNews\Inngest\Config;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Optional client settings that override environment variables
 *
 * Every setting is optional. A value set here wins over the matching
 * `INNGEST_*` environment variable; `null` means "not set, use the env var
 * or default".
 *
 * Usage:
 *
 * ```php
 * $options = new ClientOptions(
 *     signing_key: $secrets['inngest_signing_key'],
 *     event_key:   $secrets['inngest_event_key'],
 * );
 *
 * // Or from a config array
 * $options = ClientOptions::fromArray($config['inngest']);
 * ```
 *
 * @package DealNews\Inngest\Config
 */
class ClientOptions extends ValueObject {

    /**
     * Creates the options
     *
     * @param string|null $event_key            Key for sending events
     *                                          (INNGEST_EVENT_KEY)
     * @param string|null $signing_key          Key for signing requests
     *                                          (INNGEST_SIGNING_KEY)
     * @param string|null $signing_key_fallback Previous signing key, used
     *                                          during key rotation
     *                                          (INNGEST_SIGNING_KEY_FALLBACK)
     * @param string|null $env                  Inngest environment/branch
     *                                          name (INNGEST_ENV)
     * @param string|null $base_url             Base URL for both the API and
     *                                          the event API
     *                                          (INNGEST_BASE_URL)
     * @param string|null $api_base_url         API base URL; wins over
     *                                          base_url
     *                                          (INNGEST_API_BASE_URL)
     * @param string|null $event_api_base_url   Event API base URL; wins over
     *                                          base_url
     *                                          (INNGEST_EVENT_API_BASE_URL)
     * @param bool|null   $is_dev               Force dev (true) or cloud
     *                                          (false) mode (INNGEST_DEV)
     * @param string|null $app_version          Your app's version, e.g. a
     *                                          git SHA
     * @param string|null $serve_origin         Public origin of the serve
     *                                          endpoint, e.g.
     *                                          https://example.com
     *                                          (INNGEST_SERVE_ORIGIN)
     * @param string|null $serve_path           Public path of the serve
     *                                          endpoint, e.g. /api/inngest
     *                                          (INNGEST_SERVE_PATH)
     * @param bool|null   $enable_unauthed_sync Accept unsigned sync (PUT)
     *                                          requests in cloud mode
     *                                          (INNGEST_ENABLE_UNAUTHED_SYNC)
     * @param bool|null   $allow_in_band_sync   Allow in-band syncs
     *                                          (INNGEST_ALLOW_IN_BAND_SYNC)
     * @param bool|Checkpointing|null $checkpointing Async checkpointing
     *        for every function (on by default); functions can override
     */
    public function __construct(
        public ?string $event_key = null,
        public ?string $signing_key = null,
        public ?string $signing_key_fallback = null,
        public ?string $env = null,
        public ?string $base_url = null,
        public ?string $api_base_url = null,
        public ?string $event_api_base_url = null,
        public ?bool $is_dev = null,
        public ?string $app_version = null,
        public ?string $serve_origin = null,
        public ?string $serve_path = null,
        public ?bool $enable_unauthed_sync = null,
        public ?bool $allow_in_band_sync = null,
        public bool|Checkpointing|null $checkpointing = null
    ) {
    }
}
