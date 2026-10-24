<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

use DealNews\Inngest\Version;

/**
 * Header names and the SDK identification headers
 *
 * Port of `headerKeys` (helpers/consts.ts) and `inngestHeaders()`
 * (helpers/env.ts). Every request to Inngest and every response from the
 * serve endpoint carries the SDK headers.
 *
 * Usage:
 *
 * ```php
 * $headers = (new Headers(new Version()))->sdkHeaders('my-branch');
 * // [
 * //     'Content-Type'          => 'application/json',
 * //     'User-Agent'            => 'dealnews/inngest-php-sdk:v1.0.0',
 * //     'x-inngest-sdk'         => 'dealnews/inngest-php-sdk:v1.0.0',
 * //     'x-inngest-sdk-handled' => 'true',
 * //     'x-inngest-env'         => 'my-branch',
 * // ]
 * ```
 *
 * Edge cases:
 *
 * - Framework and platform headers are never sent (no detection, D8).
 *
 * @package DealNews\Inngest\Http
 */
class Headers {

    public const CONTENT_TYPE          = 'content-type';
    public const HOST                  = 'host';
    public const FORWARDED_FOR         = 'x-forwarded-for';
    public const REAL_IP               = 'x-real-ip';
    public const LOCATION              = 'location';
    public const CONTENT_LENGTH        = 'content-length';
    public const SIGNATURE             = 'x-inngest-signature';
    public const SDK_VERSION           = 'x-inngest-sdk';
    public const ENVIRONMENT           = 'x-inngest-env';
    public const NO_RETRY              = 'x-inngest-no-retry';
    public const REQUEST_ID            = 'x-request-id';
    public const GENERATION_ID         = 'x-inngest-generation-id';
    public const JOB_ID                = 'x-inngest-job-id';
    public const REQUEST_VERSION       = 'x-inngest-req-version';
    public const RETRY_AFTER           = 'retry-after';
    public const SERVER_KIND           = 'x-inngest-server-kind';
    public const EXPECTED_SERVER_KIND  = 'x-inngest-expected-server-kind';
    public const SYNC_KIND             = 'x-inngest-sync-kind';
    public const EVENT_ID_SEED         = 'x-inngest-event-id-seed';
    public const TRACE_PARENT          = 'traceparent';
    public const TRACE_STATE           = 'tracestate';
    public const RUN_ID                = 'x-run-id';
    public const STEP_ID               = 'x-inngest-step-id';
    public const FORCE_STEP_PLAN       = 'x-inngest-force-step-plan';
    public const SDK_HANDLED           = 'x-inngest-sdk-handled';

    /**
     * Request headers copied onto responses (trace context)
     *
     * @var list<string>
     */
    public const FORWARDED = [
        self::TRACE_PARENT,
        self::TRACE_STATE,
    ];

    /**
     * Creates the header builder
     *
     * @param Version $version SDK version source
     */
    public function __construct(protected Version $version) {
    }

    /**
     * Returns the headers sent with every request and response
     *
     * @param string|null           $env   Inngest environment name, if any
     * @param array<string, string> $extra Additional headers; these win
     *
     * @return array<string, string>
     */
    public function sdkHeaders(?string $env = null, array $extra = []): array {
        $identifier = $this->version->identifier();

        $headers = [
            'Content-Type'    => 'application/json',
            'User-Agent'      => $identifier,
            self::SDK_VERSION => $identifier,
            self::SDK_HANDLED => 'true',
        ];

        if ($env !== null && $env !== '') {
            $headers[self::ENVIRONMENT] = $env;
        }

        return array_merge($headers, $extra);
    }
}
