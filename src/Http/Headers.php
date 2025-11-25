<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

/**
 * HTTP headers used by Inngest
 */
class Headers
{
    public const SDK = 'X-Inngest-Sdk';
    public const SIGNATURE = 'X-Inngest-Signature';
    public const ENV = 'X-Inngest-Env';
    public const PLATFORM = 'X-Inngest-Platform';
    public const FRAMEWORK = 'X-Inngest-Framework';
    public const NO_RETRY = 'X-Inngest-No-Retry';
    public const REQ_VERSION = 'X-Inngest-Req-Version';
    public const RETRY_AFTER = 'Retry-After';
    public const SERVER_KIND = 'X-Inngest-Server-Kind';
    public const EXPECTED_SERVER_KIND = 'X-Inngest-Expected-Server-Kind';
    public const AUTHORIZATION = 'Authorization';
    public const SYNC_KIND = 'X-Inngest-Sync-Kind';

    public const SDK_VERSION = '0.1.5';
    public const SDK_NAME = 'php';
    public const REQ_VERSION_CURRENT = '1';
}
