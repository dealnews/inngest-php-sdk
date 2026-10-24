<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Http\Introspection\AuthenticatedIntrospection;
use DealNews\Inngest\Http\Introspection\IntrospectionExtra;
use DealNews\Inngest\Http\Introspection\UnauthenticatedIntrospection;
use DealNews\Inngest\Version;

/**
 * Builds introspection bodies (the `GET` response)
 *
 * Port of `introspectionBody()` in InngestCommHandler.ts.
 *
 * Edge cases:
 *
 * - Only the first 12 characters of the hashed signing keys are reported;
 *   enough to tell keys apart without giving the hash away.
 *
 * @package DealNews\Inngest\Http
 */
class Introspector {

    /**
     * Framework name reported to Inngest
     *
     * @var string
     */
    public const FRAMEWORK = 'php';

    /**
     * SDK language reported to Inngest
     *
     * @var string
     */
    public const SDK_LANGUAGE = 'php';

    /**
     * Number of hashed key characters to report
     *
     * @var int
     */
    public const KEY_HASH_LENGTH = 12;

    /**
     * Creates the builder
     *
     * @param ClientConfig $config  Resolved client config
     * @param Version      $version SDK version source
     */
    public function __construct(
        protected ClientConfig $config,
        protected Version $version
    ) {
    }

    /**
     * Builds the introspection body
     *
     * @param int  $function_count Number of function configs
     * @param bool $authenticated  Whether the request was signed (cloud mode)
     *
     * @return UnauthenticatedIntrospection An AuthenticatedIntrospection
     *                                      when authenticated
     */
    public function build(
        int $function_count,
        bool $authenticated
    ): UnauthenticatedIntrospection {
        $config = $this->config;

        if ($authenticated) {
            $result = new AuthenticatedIntrospection(
                extra: new IntrospectionExtra(is_streaming: false)
            );
            $result->api_origin       = $config->api_base_url;
            $result->app_id           = $config->app_id;
            $result->capabilities     = new Capabilities();
            $result->env              = $config->env;
            $result->event_api_origin = $config->event_api_base_url;
            $result->event_key_hash   = $config->event_key === null ?
                null :
                Signature::hashEventKey($config->event_key);
            $result->framework                 = self::FRAMEWORK;
            $result->sdk_language              = self::SDK_LANGUAGE;
            $result->sdk_version               = $this->version->version();
            $result->serve_origin              = $config->serve_origin;
            $result->serve_path                = $config->serve_path;
            $result->signing_key_fallback_hash = static::shortKeyHash(
                $config->signing_key_fallback
            );
            $result->signing_key_hash = static::shortKeyHash(
                $config->signing_key
            );
        } else {
            $result = new UnauthenticatedIntrospection();
        }

        $result->has_event_key   = $config->event_key   !== null;
        $result->has_signing_key = $config->signing_key !== null;
        $result->function_count  = $function_count;
        $result->mode            = $config->mode->value;

        return $result;
    }

    /**
     * Returns the start of a signing key's hash, without its prefix
     *
     * @param string|null $signing_key Signing key
     *
     * @return string|null Null when there's no key
     */
    protected static function shortKeyHash(?string $signing_key): ?string {
        $result = null;

        if ($signing_key !== null) {
            $result = substr(
                Signature::removeKeyPrefix(
                    Signature::hashSigningKey($signing_key)
                ),
                0,
                self::KEY_HASH_LENGTH
            );
        }

        return $result;
    }
}
