<?php

declare(strict_types=1);

namespace DealNews\Inngest\Config;

use DealNews\Inngest\Support\Url;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fully resolved client configuration
 *
 * Built once, when the client is created, from (in priority order) the
 * `ClientOptions` object, `INNGEST_*` environment variables, and SDK
 * defaults. The rest of the SDK reads settings only from here.
 *
 * Usage:
 *
 * ```php
 * $config = ClientConfig::resolve('my-app', new ClientOptions(), new Env());
 * $config->mode;          // Mode::Cloud
 * $config->api_base_url;  // "https://api.inngest.com/"
 * ```
 *
 * Edge cases:
 *
 * - `INNGEST_DEV` may be a boolean or a Dev Server URL. A URL turns on dev
 *   mode and becomes the default for both base URLs.
 * - `INNGEST_SERVE_HOST` is a deprecated alias of `INNGEST_SERVE_ORIGIN`
 *   and logs a warning when used.
 *
 * @package DealNews\Inngest\Config
 */
class ClientConfig {

    public const DEFAULT_API_BASE_URL       = 'https://api.inngest.com/';
    public const DEFAULT_EVENT_API_BASE_URL = 'https://inn.gs/';
    public const DEFAULT_DEV_SERVER_URL     = 'http://localhost:8288/';

    /**
     * Creates a resolved configuration; prefer resolve()
     *
     * @param string      $app_id               App ID (client ID)
     * @param Mode        $mode                 Dev or cloud
     * @param string      $api_base_url         API base URL
     * @param string      $event_api_base_url   Event API base URL
     * @param string|null $event_key            Event key
     * @param string|null $signing_key          Signing key
     * @param string|null $signing_key_fallback Fallback signing key
     * @param string|null $env                  Inngest environment name
     * @param string|null $app_version          App version
     * @param string|null $serve_origin         Serve endpoint origin
     * @param string|null $serve_path           Serve endpoint path
     * @param bool        $enable_unauthed_sync Accept unsigned syncs
     * @param bool        $allow_in_band_sync   Allow in-band syncs
     */
    public function __construct(
        public readonly string $app_id,
        public readonly Mode $mode,
        public readonly string $api_base_url,
        public readonly string $event_api_base_url,
        public readonly ?string $event_key = null,
        public readonly ?string $signing_key = null,
        public readonly ?string $signing_key_fallback = null,
        public readonly ?string $env = null,
        public readonly ?string $app_version = null,
        public readonly ?string $serve_origin = null,
        public readonly ?string $serve_path = null,
        public readonly bool $enable_unauthed_sync = true,
        public readonly bool $allow_in_band_sync = true
    ) {
    }

    /**
     * Resolves settings: options first, then env vars, then defaults
     *
     * @param string               $app_id  App ID; must not be empty
     * @param ClientOptions|null   $options Explicit settings
     * @param Env|null             $env     Environment variable reader
     * @param LoggerInterface|null $logger  Receives deprecation warnings
     *
     * @throws \InvalidArgumentException When the app ID is empty
     *
     * @return self
     */
    public static function resolve(
        string $app_id,
        ?ClientOptions $options = null,
        ?Env $env = null,
        ?LoggerInterface $logger = null
    ): self {
        if (trim($app_id) === '') {
            throw new \InvalidArgumentException(
                'An app ID must be passed to create an Inngest client.',
                4001
            );
        }

        $options ??= new ClientOptions();
        $env     ??= new Env();
        $logger  ??= new NullLogger();

        $dev_url = static::explicitDevUrl($env);
        $mode    = static::resolveMode($options, $env, $dev_url);

        return new self(
            app_id: $app_id,
            mode: $mode,
            api_base_url: static::firstSet(
                $options->api_base_url,
                $options->base_url,
                $env->get(Env::API_BASE_URL),
                $env->get(Env::BASE_URL),
                static::defaultUrl(
                    self::DEFAULT_API_BASE_URL,
                    $mode,
                    $dev_url
                )
            ),
            event_api_base_url: static::firstSet(
                $options->event_api_base_url,
                $options->base_url,
                $env->get(Env::EVENT_API_BASE_URL),
                $env->get(Env::BASE_URL),
                static::defaultUrl(
                    self::DEFAULT_EVENT_API_BASE_URL,
                    $mode,
                    $dev_url
                )
            ),
            event_key: static::firstSet(
                $options->event_key,
                $env->get(Env::EVENT_KEY)
            ),
            signing_key: static::firstSet(
                $options->signing_key,
                $env->get(Env::SIGNING_KEY)
            ),
            signing_key_fallback: static::firstSet(
                $options->signing_key_fallback,
                $env->get(Env::SIGNING_KEY_FALLBACK)
            ),
            env: static::firstSet($options->env, $env->get(Env::ENV)),
            app_version: $options->app_version,
            serve_origin: static::firstSet(
                $options->serve_origin,
                static::serveOriginFromEnv($env, $logger)
            ),
            serve_path: static::firstSet(
                $options->serve_path,
                $env->get(Env::SERVE_PATH)
            ),
            enable_unauthed_sync: static::firstSet(
                $options->enable_unauthed_sync,
                $env->getBool(Env::ENABLE_UNAUTHED_SYNC),
                true
            ),
            allow_in_band_sync: static::firstSet(
                $options->allow_in_band_sync,
                $env->getBool(Env::ALLOW_IN_BAND_SYNC),
                true
            )
        );
    }

    /**
     * Returns the first value that isn't null
     *
     * @param mixed ...$values Candidates in priority order
     *
     * @return mixed
     */
    protected static function firstSet(mixed ...$values): mixed {
        $result = null;
        foreach ($values as $value) {
            if ($value !== null) {
                $result = $value;
                break;
            }
        }

        return $result;
    }

    /**
     * Returns the Dev Server URL when `INNGEST_DEV` holds a URL
     *
     * @param Env $env Environment variable reader
     *
     * @return string|null
     */
    protected static function explicitDevUrl(Env $env): ?string {
        $value  = $env->get(Env::DEV);
        $result = null;

        if (
            $value !== null        &&
            $value !== 'undefined' &&
            Env::parseBool($value) === null
        ) {
            $result = Url::normalize($value);
        }

        return $result;
    }

    /**
     * Decides dev vs cloud mode
     *
     * Order: `is_dev` option, `INNGEST_DEV` as a boolean, `INNGEST_DEV` as
     * a URL (dev), then cloud.
     *
     * @param ClientOptions $options Explicit settings
     * @param Env           $env     Environment variable reader
     * @param string|null   $dev_url URL from INNGEST_DEV, if any
     *
     * @return Mode
     */
    protected static function resolveMode(
        ClientOptions $options,
        Env $env,
        ?string $dev_url
    ): Mode {
        $is_dev = $options->is_dev  ??
            $env->getBool(Env::DEV) ??
            ($dev_url !== null ? true : null);

        return $is_dev === true ? Mode::Dev : Mode::Cloud;
    }

    /**
     * Returns the default URL for the current mode
     *
     * @param string      $cloud_url URL to use in cloud mode
     * @param Mode        $mode      Current mode
     * @param string|null $dev_url   URL from INNGEST_DEV, if any
     *
     * @return string
     */
    protected static function defaultUrl(
        string $cloud_url,
        Mode $mode,
        ?string $dev_url
    ): string {
        if ($dev_url !== null) {
            $result = $dev_url;
        } elseif ($mode === Mode::Cloud) {
            $result = $cloud_url;
        } else {
            $result = self::DEFAULT_DEV_SERVER_URL;
        }

        return $result;
    }

    /**
     * Reads the serve origin, falling back to the deprecated host variable
     *
     * @param Env             $env    Environment variable reader
     * @param LoggerInterface $logger Receives the deprecation warning
     *
     * @return string|null
     */
    protected static function serveOriginFromEnv(
        Env $env,
        LoggerInterface $logger
    ): ?string {
        $result = $env->get(Env::SERVE_ORIGIN);

        if ($result === null) {
            $result = $env->get(Env::SERVE_HOST);
            if ($result !== null) {
                $logger->warning(
                    'INNGEST_SERVE_HOST is deprecated; use ' .
                    'INNGEST_SERVE_ORIGIN instead'
                );
            }
        }

        return $result;
    }
}
