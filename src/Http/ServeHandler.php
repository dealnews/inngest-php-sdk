<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

use DealNews\Inngest\Client;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Config\Mode;
use DealNews\Inngest\Error\ErrorSerializer;
use DealNews\Inngest\Execution\Engine;
use DealNews\Inngest\Execution\ExecutionException;
use DealNews\Inngest\Execution\ExecutionResult;
use DealNews\Inngest\Execution\OutgoingOp;
use DealNews\Inngest\Execution\ResultType;
use DealNews\Inngest\Execution\RunRequest;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Support\Url;
use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerInterface;

/**
 * The serve endpoint: answers every request Inngest sends to your app
 *
 * Port of `InngestCommHandler` (components/InngestCommHandler.ts), minus
 * framework adapters, streaming, and Connect.
 *
 * - `GET`: introspection (signed requests only, in cloud mode)
 * - `PUT`: sync, either out-of-band (the SDK calls Inngest's register API)
 *   or in-band (the sync details go back in the response)
 * - `POST`: trust probe or function execution
 *
 * Usage:
 *
 * ```php
 * $inngest = new Client('my-app');
 * $inngest->createFunction(...);
 *
 * $handler = new ServeHandler($inngest);
 * $handler->handle(Request::fromGlobals())->send();
 * ```
 *
 * Edge cases:
 *
 * - In cloud mode, responses to requests that fail signature checks
 *   don't carry the SDK identification headers (only
 *   `X-Inngest-SDK-Handled`), so anonymous callers can't fingerprint the
 *   app.
 * - Responses to signed requests are signed with the same key.
 * - Errors never escape `handle()`; they become a `500` response.
 *
 * @package DealNews\Inngest\Http
 */
class ServeHandler {

    /**
     * Execution version this SDK prefers (JS
     * `PREFERRED_ASYNC_EXECUTION_VERSION`)
     *
     * @var int
     */
    public const PREFERRED_EXECUTION_VERSION = 2;

    public const QUERY_FN_ID     = 'fnId';
    public const QUERY_STEP_ID   = 'stepId';
    public const QUERY_PROBE     = 'probe';
    public const QUERY_DEPLOY_ID = 'deployId';

    public const PROBE_TRUST = 'trust';

    public const SYNC_IN_BAND     = 'in_band';
    public const SYNC_OUT_OF_BAND = 'out_of_band';

    /**
     * Logger for SDK messages
     *
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Signs and verifies requests
     *
     * @var Signature
     */
    protected Signature $signature;

    /**
     * Builds introspection bodies
     *
     * @var Introspector
     */
    protected Introspector $introspector;

    /**
     * Builds sync payloads and performs out-of-band syncs
     *
     * @var Registrar
     */
    protected Registrar $registrar;

    /**
     * Serializes errors into response bodies
     *
     * @var ErrorSerializer
     */
    protected ErrorSerializer $serializer;

    /**
     * Creates the handler
     *
     * @param Client         $client    Client whose functions are served
     * @param Signature|null $signature Signature helper (for tests)
     */
    public function __construct(
        protected Client $client,
        ?Signature $signature = null
    ) {
        $this->logger       = $client->getLogger();
        $this->signature    = $signature ?? new Signature();
        $this->introspector = new Introspector(
            $client->getConfig(),
            $client->getVersion()
        );
        $this->registrar  = new Registrar($client);
        $this->serializer = new ErrorSerializer();
    }

    /**
     * Handles a request from Inngest
     *
     * @param Request $request Incoming request
     *
     * @return Response
     */
    public function handle(Request $request): Response {
        $signature = $this->validateSignature($request);

        try {
            $response = $this->handleAction($request, $signature);
        } catch (\Throwable $e) {
            $response = $this->internalError($e);
        }

        return $this->prepareResponse($response, $request, $signature);
    }

    /**
     * Checks the request signature
     *
     * Dev mode never checks signatures. In cloud mode a signing key and a
     * valid `X-Inngest-Signature` header are required. `POST` and `PUT`
     * signatures cover the raw body; other methods sign an empty body.
     *
     * @param Request $request Incoming request
     *
     * @return SignatureResult
     */
    protected function validateSignature(Request $request): SignatureResult {
        $config = $this->client->getConfig();
        $result = new SignatureResult(true);

        if ($config->mode === Mode::Cloud) {
            try {
                if ($config->signing_key === null) {
                    throw new SignatureException(
                        'No signing key found in client options or ' .
                        Env::SIGNING_KEY . ' env var. Find your keys at ' .
                        'https://app.inngest.com/env/production/manage/' .
                        'signing-key',
                        5004
                    );
                }

                $header = $request->header(Headers::SIGNATURE);
                if ($header === null || $header === '') {
                    throw new SignatureException(
                        'No ' . Headers::SIGNATURE . ' provided',
                        5005
                    );
                }

                $key_used = $this->signature->verify(
                    $header,
                    static::signedBody($request),
                    $config->signing_key,
                    $config->signing_key_fallback
                );
                $result = new SignatureResult(true, $key_used);
            } catch (SignatureException $e) {
                $result = new SignatureResult(false, '', $e);
            }
        }

        return $result;
    }

    /**
     * Decides what to do with a request (JS `handleAction`)
     *
     * @param Request         $request   Incoming request
     * @param SignatureResult $signature Signature check result
     *
     * @throws \Throwable Anything unexpected; handle() turns it into a 500
     *
     * @return Response
     */
    protected function handleAction(
        Request $request,
        SignatureResult $signature
    ): Response {
        $config = $this->client->getConfig();

        if ($config->mode === Mode::Cloud && $config->signing_key === null) {
            $this->logger->error(
                'In cloud mode but no signing key found. For local dev, set ' .
                'the INNGEST_DEV=1 env var. For production, set the ' .
                Env::SIGNING_KEY . ' env var'
            );
            $response = Response::json(
                500,
                ['code' => 'internal_server_error']
            );
        } elseif (
            $config->mode === Mode::Cloud  &&
            !$config->enable_unauthed_sync &&
            !$signature->success
        ) {
            $response = $this->signatureFailed($request, $signature);
        } else {
            $response = match ($request->method) {
                'POST'  => $this->handlePost($request, $signature),
                'GET'   => $this->handleGet($request, $signature),
                'PUT'   => $this->handlePut($request, $signature),
                default => $this->methodNotAllowed($request),
            };
        }

        return $response;
    }

    /**
     * Handles `POST`: trust probes and function execution
     *
     * @param Request         $request   Incoming request
     * @param SignatureResult $signature Signature check result
     *
     * @throws ServeException When the function ID is missing or unknown
     *
     * @return Response
     */
    protected function handlePost(
        Request $request,
        SignatureResult $signature
    ): Response {
        $probe = $request->query(self::QUERY_PROBE);

        if (static::isMissingBody($request)) {
            $this->logger->error(
                'Missing body when executing, possibly due to missing ' .
                'request body middleware'
            );
            $response = static::unauthorized();
        } elseif (!$signature->success) {
            $response = $this->signatureFailed($request, $signature);
        } elseif ($probe !== null && $probe !== '') {
            $response = $this->handleProbe($probe);
        } else {
            $fn_id = $request->query(self::QUERY_FN_ID);
            if ($fn_id === null || $fn_id === '') {
                throw new ServeException(
                    'No function ID found in async request',
                    5102
                );
            }

            $function = $this->client->getFunction($fn_id);
            if ($function === null) {
                throw new ServeException(
                    "Function \"$fn_id\" was not found in this app; is the " .
                    'app synced correctly?',
                    5103
                );
            }

            $response = $this->runFunction($request, $function, $fn_id);
        }

        return $response;
    }

    /**
     * Answers a probe (`POST ?probe=...`)
     *
     * @param string $probe Probe name
     *
     * @return Response
     */
    protected function handleProbe(string $probe): Response {
        if ($probe === self::PROBE_TRUST) {
            $response = new Response(
                200,
                ['content-type' => 'application/json']
            );
        } else {
            $response = Response::json(
                400,
                $this->serializer->serialize(
                    new ServeException("Unknown probe \"$probe\"", 5101)
                )
            );
        }

        return $response;
    }

    /**
     * Runs a function and turns the result into a response
     *
     * @param Request         $request  Incoming request
     * @param InngestFunction $function Function to run
     * @param string          $fn_id    Requested function ID (the failure
     *                                  handler's ID runs `on_failure`)
     *
     * @throws ExecutionException When the request body can't be parsed or
     *                            run data can't be fetched
     *
     * @return Response
     */
    protected function runFunction(
        Request $request,
        InngestFunction $function,
        string $fn_id
    ): Response {
        $run = RunRequest::parse(
            (string)$request->body,
            $request->header(Headers::REQUEST_VERSION)
        )->withApiData($this->client->getApi());

        $step_id = $request->query(self::QUERY_STEP_ID);
        if ($step_id === null || $step_id === '') {
            $step_id = $request->header(Headers::STEP_ID);
        }
        if ($step_id === '' || $step_id === InngestFunction::STEP_ID) {
            $step_id = null;
        }

        $engine = new Engine(
            $function,
            $fn_id === $function->failureId(),
            $run,
            $step_id,
            $this->logger,
            $this->serializer
        );

        return $this->executionResponse($engine->execute());
    }

    /**
     * Maps an execution result to a response (JS `resultHandlers`)
     *
     * @param ExecutionResult $result Result
     *
     * @return Response
     */
    protected function executionResponse(ExecutionResult $result): Response {
        $retry_headers = static::retryHeaders($result->retriable);

        return match ($result->type) {
            ResultType::FunctionResolved => Response::json(
                200,
                $result->data
            ),
            ResultType::FunctionRejected => Response::json(
                $result->retriable === false ? 400 : 500,
                $result->error,
                $retry_headers
            ),
            ResultType::StepRan => Response::json(
                206,
                static::opsWithData($result->ops),
                $retry_headers
            ),
            ResultType::StepsFound => Response::json(
                206,
                static::opsWithData($result->ops)
            ),
            ResultType::StepNotFound => Response::json(
                500,
                [
                    'error'           => static::stepNotFoundMessage($result),
                    'requestedStep'   => $result->step_id,
                    'foundSteps'      => $result->found_steps,
                    'totalFoundSteps' => $result->total_found,
                ],
                [Headers::NO_RETRY => 'false']
            ),
        };
    }

    /**
     * Returns `X-Inngest-No-Retry` / `Retry-After` for a retriable value
     *
     * @param bool|string|null $retriable Retriable value; null for none
     *
     * @return array<string, string>
     */
    protected static function retryHeaders(bool|string|null $retriable): array {
        $headers = [];

        if ($retriable !== null) {
            $headers[Headers::NO_RETRY] = $retriable === false ?
                'true' :
                'false';
            if (is_string($retriable)) {
                $headers[Headers::RETRY_AFTER] = $retriable;
            }
        }

        return $headers;
    }

    /**
     * Makes every op carry `data` (null when unset), like JS
     *
     * @param list<OutgoingOp> $ops Ops
     *
     * @return list<OutgoingOp>
     */
    protected static function opsWithData(array $ops): array {
        foreach ($ops as $op) {
            if (!$op->has_data) {
                $op->withData(null);
            }
        }

        return $ops;
    }

    /**
     * Builds the "step not found" message
     *
     * @param ExecutionResult $result Step-not-found result
     *
     * @return string
     */
    protected static function stepNotFoundMessage(
        ExecutionResult $result
    ): string {
        $message = "Could not find step \"{$result->step_id}\" to run; " .
                   'timed out.';

        if ($result->found_steps !== []) {
            $lines = [];
            foreach ($result->found_steps as $step) {
                $name    = $step['displayName'] ?? $step['id'];
                $lines[] = "$name ({$step['id']})";
            }
            $message .= " Found new steps: \n" . implode("\n", $lines) . '.';
        }

        if ($result->total_found > count($result->found_steps)) {
            $message .= ' (showing ' . count($result->found_steps) . ' of ' .
                        $result->total_found . ')';
        }

        return $message;
    }

    /**
     * Handles `GET`: introspection
     *
     * @param Request         $request   Incoming request
     * @param SignatureResult $signature Signature check result
     *
     * @return Response
     */
    protected function handleGet(
        Request $request,
        SignatureResult $signature
    ): Response {
        $cloud = $this->client->getConfig()->mode === Mode::Cloud;

        if ($cloud && !$signature->success) {
            $response = $this->signatureFailed($request, $signature);
        } else {
            $url      = $this->serveUrl($request->url);
            $response = Response::json(
                200,
                $this->introspector->build(
                    count($this->registrar->configs($url)),
                    $cloud
                )
            );
        }

        return $response;
    }

    /**
     * Handles `PUT`: syncs the app with Inngest
     *
     * In-band syncs happen when Inngest asks for one and they're allowed;
     * otherwise the SDK registers out-of-band.
     *
     * @param Request         $request   Incoming request
     * @param SignatureResult $signature Signature check result
     *
     * @throws ServeException When an in-band sync URL is invalid
     *
     * @return Response
     */
    protected function handlePut(
        Request $request,
        SignatureResult $signature
    ): Response {
        $deploy_id = $request->query(self::QUERY_DEPLOY_ID);
        if ($deploy_id === 'undefined') {
            $deploy_id = null;
        }

        $in_band = $this->client->getConfig()->allow_in_band_sync &&
                   $request->header(Headers::SYNC_KIND) === self::SYNC_IN_BAND;

        if ($in_band) {
            $response = $this->handleInBandSync($request, $signature);
        } else {
            $result = $this->registrar->register(
                $this->serveUrl($request->url),
                $deploy_id,
                $this->outboundHeaders($request)
            );

            $response = Response::json(
                $result->status,
                [
                    'message'  => $result->message,
                    'modified' => $result->modified,
                ],
                [Headers::SYNC_KIND => self::SYNC_OUT_OF_BAND]
            );
        }

        return $response;
    }

    /**
     * Handles an in-band sync
     *
     * @param Request         $request   Incoming request
     * @param SignatureResult $signature Signature check result
     *
     * @throws ServeException When the URL in the body is invalid
     *
     * @return Response
     */
    protected function handleInBandSync(
        Request $request,
        SignatureResult $signature
    ): Response {
        if (static::isMissingBody($request)) {
            $this->logger->error(
                'Missing body when syncing, possibly due to missing request ' .
                'body middleware'
            );
            $response = Response::json(
                500,
                $this->serializer->serialize(
                    new ServeException(
                        'Missing request body when syncing, possibly due to ' .
                        'missing request body middleware',
                        5104
                    )
                )
            );
        } elseif (!$signature->success) {
            $response = $this->signatureFailed($request, $signature);
        } else {
            $sync_url = static::inBandUrl((string)$request->body);

            if ($sync_url === null) {
                $response = Response::json(400, [
                    'code'    => 'invalid_request',
                    'message' => 'Expected a JSON object with a single ' .
                                 'string "url" property',
                ]);
            } else {
                // The URL comes from a verified request, so it's trusted.
                $url        = $this->serveUrl($sync_url);
                $inspection = $this->introspector->build(
                    count($this->registrar->configs($url)),
                    $this->client->getConfig()->mode === Mode::Cloud
                );

                $response = Response::json(
                    200,
                    $this->registrar->inBandBody($url, $inspection),
                    [Headers::SYNC_KIND => self::SYNC_IN_BAND]
                );
            }
        }

        return $response;
    }

    /**
     * Responds to an unsupported method
     *
     * @param Request $request Incoming request
     *
     * @return Response
     */
    protected function methodNotAllowed(Request $request): Response {
        $this->logger->error(
            'Received unhandled HTTP method; expected POST, PUT, or GET',
            ['method' => $request->method]
        );

        return Response::json(405, ['message' => 'Method not allowed']);
    }

    /**
     * Logs a failed signature check and returns `401`
     *
     * @param Request         $request   Incoming request
     * @param SignatureResult $signature Failed signature check
     *
     * @return Response
     */
    protected function signatureFailed(
        Request $request,
        SignatureResult $signature
    ): Response {
        $this->logger->error('Signature validation failed', [
            'exception' => $signature->error,
            'method'    => $request->method,
        ]);

        return static::unauthorized();
    }

    /**
     * Builds the `500` response for an unexpected error
     *
     * @param \Throwable $error Error
     *
     * @return Response
     */
    protected function internalError(\Throwable $error): Response {
        $serialized = $this->serializer->serialize($error)->jsonSerialize();

        return Response::json(
            500,
            array_merge(['type' => 'internal'], (array)$serialized)
        );
    }

    /**
     * Adds SDK headers, the request version, and the signature
     *
     * @param Response        $response  Response from the action
     * @param Request         $request   Incoming request
     * @param SignatureResult $signature Signature check result
     *
     * @return Response
     */
    protected function prepareResponse(
        Response $response,
        Request $request,
        SignatureResult $signature
    ): Response {
        $headers = static::lowercaseKeys(array_merge(
            $this->client->getHeaders()->sdkHeaders(
                $this->client->getConfig()->env,
                $this->outboundHeaders($request)
            ),
            $response->headers
        ));
        $version = (string)self::PREFERRED_EXECUTION_VERSION;
        $headers[Headers::REQUEST_VERSION] ??= $version;

        if ($signature->isSigned()) {
            $headers[Headers::SIGNATURE] = $this->signature->signatureHeader(
                $response->body,
                $signature->key_used
            );
        }

        if (!$signature->success) {
            $headers = static::withoutSdkHeaders($headers);
        }

        return $response->withHeaders($headers);
    }

    /**
     * Returns headers to pass along: the expected server kind and trace
     * context
     *
     * @param Request $request Incoming request
     *
     * @return array<string, string>
     */
    protected function outboundHeaders(Request $request): array {
        $headers = [];

        $server_kind = $request->header(Headers::SERVER_KIND);
        if ($server_kind !== null && $server_kind !== '') {
            $headers[Headers::EXPECTED_SERVER_KIND] = $server_kind;
        }

        foreach (Headers::FORWARDED as $name) {
            $value = $request->header($name);
            if ($value !== null && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Returns the serve URL to report, applying `serve_origin` and
     * `serve_path` (JS `reqUrl`)
     *
     * @param string $url Request URL (or the URL from an in-band sync)
     *
     * @throws ServeException When the URL isn't absolute
     *
     * @return string
     */
    protected function serveUrl(string $url): string {
        $config = $this->client->getConfig();

        try {
            $uri = new Uri($url);
        } catch (\InvalidArgumentException $e) {
            throw new ServeException("Invalid URL: $url", 5105, $e);
        }

        if ($uri->getScheme() === '' || $uri->getHost() === '') {
            throw new ServeException("Invalid URL: $url", 5105);
        }

        $path = $uri->getPath();
        if ($config->serve_path !== null && $config->serve_path !== '') {
            $path = $config->serve_path;
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $query = $uri->getQuery();
        $base  = $url;
        if ($config->serve_origin !== null && $config->serve_origin !== '') {
            $base = $config->serve_origin;
        }

        return Url::resolve($base, $path . ($query !== '' ? '?' . $query : ''));
    }

    /**
     * Returns the body a signature covers
     *
     * @param Request $request Incoming request
     *
     * @return string
     */
    protected static function signedBody(Request $request): string {
        return in_array($request->method, ['POST', 'PUT'], true) ?
            (string)$request->body :
            '';
    }

    /**
     * Whether the body is missing, the way JS sees it
     *
     * JS parses the body and treats a falsy result (`""`, `null`, `false`,
     * `0`) as missing.
     *
     * @param Request $request Incoming request
     *
     * @return bool
     */
    protected static function isMissingBody(Request $request): bool {
        $raw    = trim((string)$request->body);
        $result = true;

        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                $result  = in_array($decoded, [null, false, 0, 0.0, ''], true);
            } catch (\JsonException) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Reads the URL from an in-band sync body (`{"url": "..."}`)
     *
     * @param string $body Raw body
     *
     * @return string|null Null when the body isn't exactly that shape
     */
    protected static function inBandUrl(string $body): ?string {
        $result = null;

        try {
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }

        if (
            $data instanceof \stdClass                     &&
            array_keys(get_object_vars($data)) === ['url'] &&
            is_string($data->url)
        ) {
            $result = $data->url;
        }

        return $result;
    }

    /**
     * Returns the `401` response
     *
     * @return Response
     */
    protected static function unauthorized(): Response {
        return Response::json(401, ['message' => 'Unauthorized']);
    }

    /**
     * Lowercases header names (later duplicates win)
     *
     * @param array<string, string> $headers Headers
     *
     * @return array<string, string>
     */
    protected static function lowercaseKeys(array $headers): array {
        $result = [];
        foreach ($headers as $name => $value) {
            $result[strtolower((string)$name)] = $value;
        }

        return $result;
    }

    /**
     * Drops headers that identify the SDK, for unauthenticated callers
     *
     * Keeps `X-Inngest-SDK-Handled`, which tells Inngest the SDK answered.
     *
     * @param array<string, string> $headers Lowercase headers
     *
     * @return array<string, string>
     */
    protected static function withoutSdkHeaders(array $headers): array {
        $result = [];
        foreach ($headers as $name => $value) {
            $name = (string)$name;
            if (
                $name === 'user-agent' ||
                (
                    str_starts_with($name, 'x-inngest-') &&
                    $name !== Headers::SDK_HANDLED
                )
            ) {
                continue;
            }

            $result[$name] = $value;
        }

        return $result;
    }
}
