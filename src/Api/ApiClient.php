<?php

declare(strict_types=1);

namespace DealNews\Inngest\Api;

use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Support\Url;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Makes authenticated requests to the Inngest REST API
 *
 * Port of `InngestApi` (api/api.ts) and `fetchWithAuthFallback`
 * (helpers/net.ts). Requests authenticate with
 * `Authorization: Bearer <hashed signing key>`; on a 401 or 403 the request
 * is retried once with the fallback signing key.
 *
 * Usage:
 *
 * ```php
 * $api   = new ApiClient($config, new \GuzzleHttp\Client(), $headers);
 * $steps = $api->getRunSteps($run_id);
 * ```
 *
 * Edge cases:
 *
 * - With no signing key (dev mode), no Authorization header is sent. (JS
 *   sends `Bearer ` with an empty token.)
 * - Error responses never throw from Guzzle (`http_errors` is off); the
 *   status is checked here instead.
 *
 * @package DealNews\Inngest\Api
 */
class ApiClient {

    /**
     * Creates the API client
     *
     * @param ClientConfig    $config  Resolved client config
     * @param ClientInterface $http    Guzzle client
     * @param Headers         $headers SDK header builder
     */
    public function __construct(
        protected ClientConfig $config,
        protected ClientInterface $http,
        protected Headers $headers
    ) {
    }

    /**
     * Sends a request to the API with signing-key auth and fallback
     *
     * @param string                $method  HTTP method
     * @param string                $path    Path (resolved against the API
     *                                       base URL) or absolute URL
     * @param string|null           $body    Raw request body
     * @param array<string, string> $headers Extra headers; these win
     *
     * @throws ApiException On transport failure (code 6001)
     *
     * @return ResponseInterface
     */
    public function request(
        string $method,
        string $path,
        ?string $body = null,
        array $headers = []
    ): ResponseInterface {
        $url      = Url::resolve($this->config->api_base_url, $path);
        $headers  = $this->headers->sdkHeaders($this->config->env, $headers);
        $token    = Signature::hashSigningKey($this->config->signing_key);
        $fallback = Signature::hashSigningKey(
            $this->config->signing_key_fallback
        );

        $response = $this->send($method, $url, $body, $headers, $token);

        if (
            in_array($response->getStatusCode(), [401, 403], true) &&
            $fallback !== ''
        ) {
            $response = $this->send($method, $url, $body, $headers, $fallback);
        }

        return $response;
    }

    /**
     * Fetches the memoized step state for a run
     *
     * Used when a request says `use_api` because the state was too large to
     * send inline.
     *
     * @param string $run_id Run ID
     *
     * @throws ApiException When the request fails or returns an error
     *
     * @return array<mixed> Decoded step state, keyed by hashed step ID
     */
    public function getRunSteps(string $run_id): array {
        return $this->getJson(
            '/v0/runs/' . rawurlencode($run_id) . '/actions',
            'Unknown error retrieving step data'
        );
    }

    /**
     * Fetches the event batch for a run
     *
     * @param string $run_id Run ID
     *
     * @throws ApiException When the request fails or returns an error
     *
     * @return array<mixed> Decoded list of events
     */
    public function getRunBatch(string $run_id): array {
        return $this->getJson(
            '/v0/runs/' . rawurlencode($run_id) . '/batch',
            'Unknown error retrieving event batch'
        );
    }

    /**
     * Sends a signal to the run waiting for it (`POST /v1/signals`)
     *
     * Port of JS `InngestApi.sendSignal`. A 404 means no run was waiting
     * for the signal; that's not an error.
     *
     * @param string      $signal Signal name
     * @param mixed       $data   Data for the waiting run; null sends none
     * @param string|null $env    Environment override (`X-Inngest-Env`)
     *
     * @throws ApiException When the request fails (6001) or Inngest
     *                      rejects the signal (6004)
     *
     * @return SendSignalResult
     */
    public function sendSignal(
        string $signal,
        mixed $data = null,
        ?string $env = null
    ): SendSignalResult {
        $body = ['signal' => $signal];
        if ($data !== null) {
            $body['data'] = $data;
        }

        $response = $this->request(
            'POST',
            '/v1/signals',
            (string)json_encode(
                $body,
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE |
                JSON_THROW_ON_ERROR
            ),
            $env === null ? [] : [Headers::ENVIRONMENT => $env]
        );

        $status = $response->getStatusCode();
        $raw    = (string)$response->getBody();
        $json   = json_decode($raw, true);
        $detail = "$status " . $response->getReasonPhrase() . " - $raw";

        if ($status === 404) {
            $result = new SendSignalResult();
        } elseif ($status < 200 || $status >= 300) {
            $message = is_array($json) && is_string($json['error'] ?? null) ?
                $json['error'] :
                $detail;

            throw new ApiException(
                "Failed to send signal: $message",
                6004,
                $status
            );
        } elseif (
            !is_array($json)                             ||
            !is_string($json['data']['run_id'] ?? null)  ||
            $json['data']['run_id'] === ''
        ) {
            throw new ApiException(
                'Failed to send signal: Successfully sent signal, but ' .
                "response parsing failed: $detail",
                6004,
                $status
            );
        } else {
            $result = new SendSignalResult($json['data']['run_id']);
        }

        return $result;
    }

    /**
     * GETs a path and decodes its JSON body
     *
     * @param string $path             API path
     * @param string $fallback_message Message when the error has none
     *
     * @throws ApiException When the request fails or returns an error
     *
     * @return array<mixed>
     */
    protected function getJson(string $path, string $fallback_message): array {
        $response = $this->request('GET', $path);
        $status   = $response->getStatusCode();
        $data     = json_decode((string)$response->getBody(), true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($data) && is_string($data['error'] ?? null) ?
                $data['error'] :
                $fallback_message;

            throw new ApiException($message, 6002, $status);
        }

        if (!is_array($data)) {
            throw new ApiException(
                "$fallback_message: response was not valid JSON",
                6003,
                $status
            );
        }

        return $data;
    }

    /**
     * Sends one request with the given bearer token
     *
     * @param string                $method  HTTP method
     * @param string                $url     Absolute URL
     * @param string|null           $body    Raw body
     * @param array<string, string> $headers Headers
     * @param string                $token   Bearer token; empty to omit
     *
     * @throws ApiException On transport failure
     *
     * @return ResponseInterface
     */
    protected function send(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        string $token
    ): ResponseInterface {
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $options = [
            'headers'     => $headers,
            'http_errors' => false,
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new ApiException(
                "Request to $url failed: " . $e->getMessage(),
                6001,
                null,
                $e
            );
        }

        return $response;
    }
}
