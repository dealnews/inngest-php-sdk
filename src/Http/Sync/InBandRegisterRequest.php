<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http\Sync;

use DealNews\Inngest\Functions\Config\FunctionConfig;
use DealNews\Inngest\Http\Capabilities;
use DealNews\Inngest\Http\Introspection\UnauthenticatedIntrospection;
use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Response body of an in-band sync (`PUT` with `X-Inngest-Sync-Kind:
 * in_band`)
 *
 * Port of JS `InBandRegisterRequest` (types.ts), minus
 * `featureObservations` and `platform`.
 *
 * @package DealNews\Inngest\Http\Sync
 */
class InBandRegisterRequest extends ValueObject {

    protected const WIRE_KEYS = [
        'app_version' => 'appVersion',
    ];

    protected const KEEP_NULL = [
        'env',
    ];

    protected const COLLECTION_TYPES = [
        'functions' => FunctionConfig::class,
    ];

    /**
     * Creates the body
     *
     * @param string                       $app_id       App ID
     * @param string|null                  $app_version  App version
     * @param Capabilities                 $capabilities Supported features
     * @param string|null                  $env          Inngest environment
     * @param string                       $framework    Always `php`
     * @param list<FunctionConfig>         $functions    Function configs
     * @param UnauthenticatedIntrospection $inspection   Introspection body
     * @param string                       $sdk_author   SDK author
     * @param string                       $sdk_language Language; empty
     *                                                   unless authenticated
     * @param string                       $sdk_version  Version; empty
     *                                                   unless authenticated
     * @param string                       $sdk          SDK identifier
     * @param string                       $url          Serve endpoint URL
     */
    public function __construct(
        public string $app_id = '',
        public ?string $app_version = null,
        public Capabilities $capabilities = new Capabilities(),
        public ?string $env = null,
        public string $framework = '',
        public array $functions = [],
        public UnauthenticatedIntrospection $inspection =
            new UnauthenticatedIntrospection(),
        public string $sdk_author = '',
        public string $sdk_language = '',
        public string $sdk_version = '',
        public string $sdk = '',
        public string $url = ''
    ) {
    }
}
