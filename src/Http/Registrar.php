<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

use DealNews\Inngest\Api\ApiException;
use DealNews\Inngest\Client;
use DealNews\Inngest\Functions\Config\FunctionConfig;
use DealNews\Inngest\Http\Introspection\AuthenticatedIntrospection;
use DealNews\Inngest\Http\Introspection\UnauthenticatedIntrospection;
use DealNews\Inngest\Http\Sync\InBandRegisterRequest;
use DealNews\Inngest\Http\Sync\RegisterRequest;
use DealNews\Inngest\Http\Sync\RegisterResult;
use DealNews\Inngest\Support\Url;

/**
 * Builds sync payloads and performs out-of-band syncs
 *
 * Port of `configs()`, `registerBody()`, `inBandRegisterBody()`, and
 * `register()` in InngestCommHandler.ts.
 *
 * Edge cases:
 *
 * - For out-of-band syncs, the HTTP status sent back to Inngest is the
 *   `status` field in the register response body (default 200), not the
 *   register response's HTTP status. That's what JS does.
 *
 * @package DealNews\Inngest\Http
 */
class Registrar {

    /**
     * Author reported in in-band syncs
     *
     * @var string
     */
    public const SDK_AUTHOR = 'dealnews';

    /**
     * Message used when Inngest's response doesn't include one
     *
     * @var string
     */
    public const DEFAULT_MESSAGE = 'Successfully registered';

    /**
     * Creates the registrar
     *
     * @param Client $client Inngest client
     */
    public function __construct(protected Client $client) {
    }

    /**
     * Returns the configs of every function, pointing at the given URL
     *
     * @param string $url Serve endpoint URL
     *
     * @return list<FunctionConfig>
     */
    public function configs(string $url): array {
        $configs = [];
        foreach ($this->client->getFunctions() as $function) {
            array_push($configs, ...$function->getConfigs($url));
        }

        return $configs;
    }

    /**
     * Builds the out-of-band register body
     *
     * @param string      $url       Serve endpoint URL
     * @param string|null $deploy_id Deploy ID from the sync request
     *
     * @return RegisterRequest
     */
    public function registerBody(
        string $url,
        ?string $deploy_id
    ): RegisterRequest {
        return new RegisterRequest(
            url: $url,
            framework: Introspector::FRAMEWORK,
            app_name: $this->client->getAppId(),
            functions: $this->configs($url),
            sdk: $this->client->getVersion()->identifier(),
            deploy_id: $deploy_id === '' ? null : $deploy_id,
            app_version: $this->client->getConfig()->app_version
        );
    }

    /**
     * Builds the in-band sync response body
     *
     * The SDK language and version are only filled in when the request was
     * authenticated.
     *
     * @param string                       $url        Serve endpoint URL
     * @param UnauthenticatedIntrospection $inspection Introspection body
     *
     * @return InBandRegisterRequest
     */
    public function inBandBody(
        string $url,
        UnauthenticatedIntrospection $inspection
    ): InBandRegisterRequest {
        $config = $this->client->getConfig();

        $body = new InBandRegisterRequest(
            app_id: $config->app_id,
            app_version: $config->app_version,
            env: $config->env,
            framework: Introspector::FRAMEWORK,
            functions: $this->configs($url),
            inspection: $inspection,
            sdk_author: self::SDK_AUTHOR,
            sdk: $this->client->getVersion()->identifier(),
            url: $url
        );

        if ($inspection instanceof AuthenticatedIntrospection) {
            $body->sdk_language = $inspection->sdk_language;
            $body->sdk_version  = $inspection->sdk_version;
        }

        return $body;
    }

    /**
     * Registers the app's functions with Inngest (out-of-band sync)
     *
     * Never throws: failures become a 500 result with a message.
     *
     * @param string                $url       Serve endpoint URL
     * @param string|null           $deploy_id Deploy ID from the sync
     *                                         request
     * @param array<string, string> $headers   Extra headers to send
     *
     * @return RegisterResult
     */
    public function register(
        string $url,
        ?string $deploy_id,
        array $headers = []
    ): RegisterResult {
        $config   = $this->client->getConfig();
        $logger   = $this->client->getLogger();
        $endpoint = Url::resolve($config->api_base_url, '/fn/register');
        if ($deploy_id !== null && $deploy_id !== '') {
            $endpoint = Url::withQuery($endpoint, ['deployId' => $deploy_id]);
        }

        try {
            $response = $this->client->getApi()->request(
                'POST',
                $endpoint,
                $this->registerBody($url, $deploy_id)->toJson(),
                array_merge($headers, [Headers::SYNC_KIND => 'out_of_band'])
            );
        } catch (ApiException $e) {
            $logger->error('Failed to register', ['exception' => $e]);

            return new RegisterResult(
                500,
                'Failed to register; ' . $e->getMessage()
            );
        }

        $status_code = $response->getStatusCode();
        try {
            $data = json_decode(
                (string)$response->getBody(),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            $logger->warning(
                "Couldn't unpack register response",
                ['exception' => $e]
            );

            return new RegisterResult(
                500,
                'Failed to register; ' . $e->getMessage() .
                '; status code: ' . $status_code
            );
        }

        $result = static::parseResult($data);
        if ($result === null) {
            $logger->warning('Invalid register response schema');
            $result = new RegisterResult(
                500,
                'Failed to register; Invalid register response; ' .
                'status code: ' . $status_code
            );
        } elseif (!(bool)($data->skipped ?? false)) {
            $logger->debug('Registered inngest functions');
        }

        return $result;
    }

    /**
     * Reads `{status, error, skipped, modified}` from a register response
     *
     * Every field is optional, but a field that's present must have the
     * right type (JS validates this with zod).
     *
     * @param mixed $data Decoded response (objects as stdClass)
     *
     * @return RegisterResult|null Null when the shape is wrong
     */
    protected static function parseResult(mixed $data): ?RegisterResult {
        $result = null;

        if ($data instanceof \stdClass) {
            $status   = $data->status   ?? 200;
            $error    = $data->error    ?? self::DEFAULT_MESSAGE;
            $skipped  = $data->skipped  ?? false;
            $modified = $data->modified ?? false;

            $valid = (is_int($status) || is_float($status))  &&
                     is_string($error)                       &&
                     is_bool($skipped)                       &&
                     is_bool($modified)                      &&
                     static::nullFree($data);

            if ($valid) {
                $result = new RegisterResult((int)$status, $error, $modified);
            }
        }

        return $result;
    }

    /**
     * Whether none of the known fields is an explicit `null`
     *
     * zod's `.default()` only fills in missing fields, so `null` fails.
     *
     * @param \stdClass $data Decoded response
     *
     * @return bool
     */
    protected static function nullFree(\stdClass $data): bool {
        $result = true;
        foreach (['status', 'error', 'skipped', 'modified'] as $key) {
            if (property_exists($data, $key) && $data->$key === null) {
                $result = false;
            }
        }

        return $result;
    }
}
