<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Error\NonRetriableError;
use DealNews\Inngest\Error\RetryAfterError;
use DealNews\Inngest\Error\StepError;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\FunctionContext;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Step\Step;
use DealNews\Inngest\Step\StepContext;

/**
 * Handles incoming HTTP requests from Inngest
 */
class ServeHandler
{
    protected SignatureVerifier $verifier;

    public function __construct(
        protected Inngest $client,
        protected string $serve_path = '/api/inngest'
    ) {
        $this->verifier = new SignatureVerifier($client->getConfig());
    }

    /**
     * Handle an incoming HTTP request
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param array<string, string> $headers Request headers
     * @param string $body Request body
     * @param array<string, string> $query Query parameters
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(
        string $method,
        string $path,
        array $headers,
        string $body = '',
        array $query = []
    ): array {
        if ($method === 'GET') {
            return $this->handleIntrospection($headers);
        }

        if ($method === 'PUT') {
            return $this->handleSync($headers, $query);
        }

        if ($method === 'POST') {
            return $this->handleCall($headers, $body, $query);
        }

        return $this->jsonResponse(['error' => 'Method not allowed'], 405);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected function handleIntrospection(array $headers): array
    {
        $signature = $headers[Headers::SIGNATURE] ?? $headers['x-inngest-signature'] ?? null;
        $authenticated = false;

        if ($signature !== null) {
            try {
                $this->verifier->verify('', $signature);
                $authenticated = true;
            } catch (\Exception $e) {
                $authenticated = false;
            }
        }

        $config = $this->client->getConfig();
        $signing_key = $config->getSigningKey();
        $event_key = $config->getEventKey();

        $response_data = [
            'authentication_succeeded' => $authenticated,
            'function_count' => count($this->client->getFunctions()),
            'has_event_key' => $event_key !== null,
            'has_signing_key' => $signing_key !== null,
            'has_signing_key_fallback' => $config->getSigningKeyFallback() !== null,
            'mode' => $config->isDev() ? 'dev' : 'cloud',
            'schema_version' => '2024-05-24',
        ];

        if ($authenticated) {
            $response_data = array_merge($response_data, [
                'api_origin' => $config->getApiBaseUrl(),
                'app_id' => $this->client->getAppId(),
                'env' => $config->getEnv(),
                'event_api_origin' => $config->getEventApiBaseUrl(),
                'event_key_hash' => $event_key ? hash('sha256', $event_key) : null,
                'framework' => 'php',
                'sdk_language' => 'php',
                'sdk_version' => Headers::SDK_VERSION,
                'serve_origin' => $config->getServeOrigin(),
                'serve_path' => $config->getServePath() ?? $this->serve_path,
                'signing_key_hash' => $signing_key ? hash('sha256', $signing_key) : null,
                'signing_key_fallback_hash' => $config->getSigningKeyFallback() 
                    ? hash('sha256', $config->getSigningKeyFallback()) 
                    : null,
            ]);
        }

        return $this->jsonResponse($response_data, 200, [
            Headers::SDK => $this->client->getSdkIdentifier(),
        ]);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected function handleSync(array $headers, array $query): array
    {
        try {
            $config = $this->client->getConfig();
            $url = $this->buildServeUrl();
            
            $deploy_id = $query['deployId'] ?? null;
            $sync_url = $config->getApiBaseUrl() . '/fn/register';
            
            if ($deploy_id !== null) {
                $sync_url .= '?deployId=' . urlencode($deploy_id);
            }

            $functions = [];
            foreach ($this->client->getFunctions() as $function) {
                $function_data = $function->toArray();
                $function_url = $url . '?fnId=' . urlencode($this->getCompositeId($function->getId())) . '&stepId=step';
                $function_data['steps']['step']['runtime']['url'] = $function_url;
                $function_data['id'] = $this->getCompositeId($function->getId());
                $functions[] = $function_data;
            }

            $payload = [
                'url' => $url,
                'deployType' => 'ping',
                'appName' => $this->client->getAppId(),
                'sdk' => $this->client->getSdkIdentifier(),
                'v' => '0.1',
                'framework' => 'php',
                'functions' => $functions,
            ];

            $response = $this->sendToInngest($sync_url, $payload);

            if ($response['status'] === 200) {
                $data = json_decode($response['body'], true);
                return $this->jsonResponse([
                    'message' => 'Successfully synced',
                    'modified' => $data['modified'] ?? false,
                ], 200);
            }

            $error_data = json_decode($response['body'], true);
            return $this->jsonResponse([
                'message' => $error_data['error'] ?? 'Sync failed',
                'modified' => false,
            ], 500);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'message' => $e->getMessage(),
                'modified' => false,
            ], 500);
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected function handleCall(array $headers, string $body, array $query): array
    {
        try {
            $signature = $headers[Headers::SIGNATURE] ?? $headers['x-inngest-signature'] ?? null;
            $server_kind = $headers[Headers::SERVER_KIND] ?? $headers['x-inngest-server-kind'] ?? null;
            
            $this->verifier->verify($body, $signature, $server_kind);

            $fn_id = $query['fnId'] ?? null;
            if ($fn_id === null) {
                return $this->jsonResponse(['error' => 'Missing fnId'], 400);
            }

            $function = $this->findFunction($fn_id);
            if ($function === null) {
                return $this->jsonResponse(['error' => 'Function not found'], 500);
            }

            $payload = json_decode($body, true);
            if (!is_array($payload)) {
                return $this->jsonResponse(['error' => 'Invalid payload'], 400);
            }

            return $this->executeFunction($function, $payload, $query);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $query
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected function executeFunction(InngestFunction $function, array $payload, array $query): array
    {
        $event_data = $payload['event'] ?? [];
        $events_data = $payload['events'] ?? [$event_data];
        $ctx_data = $payload['ctx'] ?? [];
        $steps_data = $payload['steps'] ?? [];

        $event = $this->hydrateEvent($event_data);
        $events = array_map(fn($e) => $this->hydrateEvent($e), $events_data);

        $step_context = new StepContext(
            $ctx_data['run_id'] ?? '',
            $ctx_data['attempt'] ?? 0,
            $ctx_data['disable_immediate_execution'] ?? false,
            $ctx_data['use_api'] ?? false,
            $ctx_data['stack'] ?? [],
            $steps_data
        );

        $step = new Step($step_context);
        
        $function_context = new FunctionContext(
            $event,
            $events,
            $step_context->getRunId(),
            $step_context->getAttempt(),
            $step
        );

        try {
            $result = $function->execute($function_context);

            $planned_steps = $step->getPlannedSteps();
            
            if (!empty($planned_steps)) {
                return $this->jsonResponse($planned_steps, 206, [
                    Headers::SDK => $this->client->getSdkIdentifier(),
                    Headers::REQ_VERSION => Headers::REQ_VERSION_CURRENT,
                ]);
            }

            return $this->jsonResponse($result, 200, [
                Headers::SDK => $this->client->getSdkIdentifier(),
                Headers::REQ_VERSION => Headers::REQ_VERSION_CURRENT,
            ]);
        } catch (NonRetriableError $e) {
            return $this->errorResponse($e, false);
        } catch (RetryAfterError $e) {
            return $this->errorResponse($e, true, $e->getRetryAfterHeader());
        } catch (StepError $e) {
            return $this->errorResponse($e, false);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, true);
        }
    }

    protected function errorResponse(\Throwable $e, bool $retriable = true, ?string $retry_after = null): array
    {
        $error_data = [
            'name' => get_class($e),
            'message' => $e->getMessage(),
            'stack' => $e->getTraceAsString(),
        ];

        $headers = [
            Headers::SDK => $this->client->getSdkIdentifier(),
            Headers::REQ_VERSION => Headers::REQ_VERSION_CURRENT,
            Headers::NO_RETRY => $retriable ? 'false' : 'true',
        ];

        if ($retry_after !== null) {
            $headers[Headers::RETRY_AFTER] = $retry_after;
        }

        $status = $retriable ? 500 : 400;

        return $this->jsonResponse($error_data, $status, $headers);
    }

    /**
     * @param array<string, mixed> $event_data
     */
    protected function hydrateEvent(array $event_data): Event
    {
        return new Event(
            $event_data['name'] ?? '',
            $event_data['data'] ?? [],
            $event_data['id'] ?? null,
            $event_data['user'] ?? null,
            $event_data['ts'] ?? null
        );
    }

    protected function findFunction(string $composite_id): ?InngestFunction
    {
        $parts = explode('-', $composite_id, 2);
        $function_id = $parts[1] ?? $composite_id;
        
        return $this->client->getFunction($function_id);
    }

    protected function getCompositeId(string $function_id): string
    {
        return $this->client->getAppId() . '-' . $function_id;
    }

    protected function buildServeUrl(): string
    {
        $config = $this->client->getConfig();
        $origin = $config->getServeOrigin();
        $path = $config->getServePath() ?? $this->serve_path;

        if ($origin !== null) {
            return rtrim($origin, '/') . '/' . ltrim($path, '/');
        }

        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $protocol . '://' . $_SERVER['HTTP_HOST'] . $path;
        }

        throw new \RuntimeException('Cannot determine serve URL');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    protected function sendToInngest(string $url, array $payload): array
    {
        $signing_key = $this->client->getConfig()->getSigningKey();
        if ($signing_key === null) {
            throw new \Exception('No signing key configured');
        }

        $body = json_encode($payload);
        $hashed_key = $this->verifier->hashSigningKey($signing_key);

        $headers = [
            'Content-Type: application/json',
            Headers::SDK . ': ' . $this->client->getSdkIdentifier(),
            Headers::AUTHORIZATION . ': Bearer ' . $hashed_key,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response_body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $response_body ?: '',
        ];
    }

    /**
     * @param mixed $data
     * @param array<string, string> $extra_headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected function jsonResponse(mixed $data, int $status = 200, array $extra_headers = []): array
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
        ], $extra_headers);

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => json_encode($data),
        ];
    }
}
