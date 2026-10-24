<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http\Sync;

use DealNews\Inngest\Functions\Config\FunctionConfig;
use DealNews\Inngest\Http\Capabilities;
use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Body of an out-of-band sync (`POST {api}/fn/register`)
 *
 * Port of JS `RegisterRequest` (types.ts), minus `featureObservations`.
 *
 * @package DealNews\Inngest\Http\Sync
 */
class RegisterRequest extends ValueObject {

    protected const WIRE_KEYS = [
        'deploy_type' => 'deployType',
        'app_name'    => 'appName',
        'deploy_id'   => 'deployId',
        'app_version' => 'appVersion',
    ];

    protected const COLLECTION_TYPES = [
        'functions' => FunctionConfig::class,
    ];

    /**
     * Creates the body
     *
     * @param string               $url          Serve endpoint URL
     * @param string               $deploy_type  Always `ping`
     * @param string               $framework    Always `php`
     * @param string               $app_name     App ID
     * @param list<FunctionConfig> $functions    Function configs
     * @param string               $sdk          SDK identifier
     * @param string               $v            Register schema version
     * @param string|null          $deploy_id    Deploy ID from the request
     * @param Capabilities         $capabilities Supported features
     * @param string|null          $app_version  App version
     */
    public function __construct(
        public string $url = '',
        public string $deploy_type = 'ping',
        public string $framework = '',
        public string $app_name = '',
        public array $functions = [],
        public string $sdk = '',
        public string $v = '0.1',
        public ?string $deploy_id = null,
        public Capabilities $capabilities = new Capabilities(),
        public ?string $app_version = null
    ) {
    }
}
