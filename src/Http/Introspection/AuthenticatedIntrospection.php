<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http\Introspection;

use DealNews\Inngest\Http\Capabilities;

/**
 * Introspection body for signed requests in cloud mode
 *
 * Port of JS `AuthenticatedIntrospection` (types.ts). Adds the app's
 * settings to the unauthenticated body. Unset values are sent as `null`,
 * not left out.
 *
 * @package DealNews\Inngest\Http\Introspection
 */
class AuthenticatedIntrospection extends UnauthenticatedIntrospection {

    protected const KEEP_NULL = [
        'env',
        'event_key_hash',
        'serve_origin',
        'serve_path',
        'signing_key_fallback_hash',
        'signing_key_hash',
    ];

    /**
     * Always true; marks the authenticated shape
     *
     * @var bool
     */
    public bool $authentication_succeeded = true;

    /**
     * API base URL
     *
     * @var string
     */
    public string $api_origin = '';

    /**
     * App ID
     *
     * @var string
     */
    public string $app_id = '';

    /**
     * Supported protocol features
     *
     * @var Capabilities
     */
    public Capabilities $capabilities;

    /**
     * Inngest environment name
     *
     * @var string|null
     */
    public ?string $env = null;

    /**
     * Event API base URL
     *
     * @var string
     */
    public string $event_api_origin = '';

    /**
     * SHA-256 of the event key
     *
     * @var string|null
     */
    public ?string $event_key_hash = null;

    /**
     * Framework name; always `php`
     *
     * @var string
     */
    public string $framework = '';

    /**
     * SDK language; always `php`
     *
     * @var string
     */
    public string $sdk_language = '';

    /**
     * SDK version, without a `v` prefix
     *
     * @var string
     */
    public string $sdk_version = '';

    /**
     * Configured serve origin
     *
     * @var string|null
     */
    public ?string $serve_origin = null;

    /**
     * Configured serve path
     *
     * @var string|null
     */
    public ?string $serve_path = null;

    /**
     * First 12 characters of the hashed fallback signing key
     *
     * @var string|null
     */
    public ?string $signing_key_fallback_hash = null;

    /**
     * First 12 characters of the hashed signing key
     *
     * @var string|null
     */
    public ?string $signing_key_hash = null;
}
