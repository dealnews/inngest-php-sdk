<?php

declare(strict_types=1);

namespace DealNews\Inngest;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Api\ApiException;
use DealNews\Inngest\Api\SendSignalResult;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Config\Mode;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Event\SendEventResult;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\InngestFunction;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Support\Url;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The Inngest client: configuration, sending events, and (later) functions
 *
 * Port of the JS `Inngest` class (components/Inngest.ts).
 *
 * Usage:
 *
 * ```php
 * $inngest = new Client('my-app');
 *
 * $inngest->send(new Event(name: 'app/user.signup', data: ['id' => 1]));
 * ```
 *
 * Edge cases:
 *
 * - Configuration is resolved once, here; changing env vars afterwards
 *   has no effect.
 * - In cloud mode, sending events requires an event key.
 *
 * @package DealNews\Inngest
 */
class Client {

    /**
     * Event key placeholder used in the URL when none is configured
     *
     * @var string
     */
    public const DUMMY_EVENT_KEY = 'NO_EVENT_KEY_SET';

    /**
     * Attempts made when sending events
     *
     * @var int
     */
    public const SEND_MAX_ATTEMPTS = 5;

    /**
     * Base retry delay when sending events, in milliseconds
     *
     * @var int
     */
    public const SEND_BASE_DELAY_MS = 100;

    /**
     * Resolved configuration
     *
     * @var ClientConfig
     */
    protected ClientConfig $config;

    /**
     * Logger for SDK messages; also passed to functions
     *
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Guzzle client for all outbound requests
     *
     * @var ClientInterface
     */
    protected ClientInterface $http_client;

    /**
     * SDK header builder
     *
     * @var Headers
     */
    protected Headers $headers;

    /**
     * SDK version source
     *
     * @var Version
     */
    protected Version $version;

    /**
     * Inngest REST API client
     *
     * @var ApiClient
     */
    protected ApiClient $api;

    /**
     * Retry helper for event sends
     *
     * @var Retry
     */
    protected Retry $retry;

    /**
     * Functions created with this client, keyed by every ID they register
     *
     * @var array<string, InngestFunction>
     */
    protected array $functions = [];

    /**
     * Creates a client
     *
     * @param string               $app_id      Your app's ID; keep it stable
     * @param ClientOptions|null   $options     Settings that override env
     *                                          vars
     * @param LoggerInterface|null $logger      PSR-3 logger; defaults to a
     *                                          NullLogger
     * @param ClientInterface|null $http_client Guzzle client; defaults to a
     *                                          new one
     * @param Env|null             $env         Env var source; defaults to
     *                                          getenv()
     * @param Version|null         $version     SDK version source
     * @param Retry|null           $retry       Retry helper (for tests)
     *
     * @throws \InvalidArgumentException When the app ID is empty
     */
    public function __construct(
        string $app_id,
        ?ClientOptions $options = null,
        ?LoggerInterface $logger = null,
        ?ClientInterface $http_client = null,
        ?Env $env = null,
        ?Version $version = null,
        ?Retry $retry = null
    ) {
        $this->logger      = $logger ?? new NullLogger();
        $this->config      = ClientConfig::resolve(
            $app_id,
            $options,
            $env,
            $this->logger
        );
        $this->http_client = $http_client ?? new \GuzzleHttp\Client();
        $this->version     = $version     ?? new Version();
        $this->headers     = new Headers($this->version);
        $this->retry       = $retry ?? new Retry();
        $this->api         = new ApiClient(
            $this->config,
            $this->http_client,
            $this->headers
        );
    }

    /**
     * Returns the resolved configuration
     *
     * @return ClientConfig
     */
    public function getConfig(): ClientConfig {
        return $this->config;
    }

    /**
     * Returns the app ID
     *
     * @return string
     */
    public function getAppId(): string {
        return $this->config->app_id;
    }

    /**
     * Returns the logger
     *
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface {
        return $this->logger;
    }

    /**
     * Returns the Inngest REST API client
     *
     * @return ApiClient
     */
    public function getApi(): ApiClient {
        return $this->api;
    }

    /**
     * Returns the retry helper used for outbound requests
     *
     * @return Retry
     */
    public function getRetry(): Retry {
        return $this->retry;
    }

    /**
     * Returns the Guzzle client used for outbound HTTP
     *
     * @return ClientInterface
     */
    public function getHttpClient(): ClientInterface {
        return $this->http_client;
    }

    /**
     * Returns the SDK header builder
     *
     * @return Headers
     */
    public function getHeaders(): Headers {
        return $this->headers;
    }

    /**
     * Returns the SDK version source
     *
     * @return Version
     */
    public function getVersion(): Version {
        return $this->version;
    }

    /**
     * Creates a function and registers it with this client
     *
     * Usage:
     *
     * ```php
     * $inngest->createFunction(
     *     new FunctionOptions(
     *         id: 'hello-world',
     *         triggers: [new EventTrigger('test/hello.world')],
     *     ),
     *     fn (Context $ctx) => 'Hello, ' . $ctx->event->data['name']
     * );
     * ```
     *
     * @param FunctionOptions $options Function options
     * @param callable        $handler Handler, called with a Context
     *
     * @throws \InvalidArgumentException When the function's ID (or its
     *                                   failure handler's ID) is already
     *                                   used (code 9101)
     *
     * @return InngestFunction
     */
    public function createFunction(
        FunctionOptions $options,
        callable $handler
    ): InngestFunction {
        $function = new InngestFunction(
            $this->config->app_id,
            $options,
            $handler
        );

        foreach ($function->configIds() as $id) {
            if (isset($this->functions[$id])) {
                throw new \InvalidArgumentException(
                    "Duplicate function ID \"$id\"; please change a " .
                    "function's name or provide an explicit ID to avoid " .
                    'conflicts.',
                    9101
                );
            }
        }

        foreach ($function->configIds() as $id) {
            $this->functions[$id] = $function;
        }

        return $function;
    }

    /**
     * Returns the functions created with this client, in creation order
     *
     * @return list<InngestFunction>
     */
    public function getFunctions(): array {
        $result = [];
        foreach ($this->functions as $function) {
            if (!in_array($function, $result, true)) {
                $result[] = $function;
            }
        }

        return $result;
    }

    /**
     * Finds a function by any ID it registers (including `-failure` IDs)
     *
     * @param string $id Full function ID
     *
     * @return InngestFunction|null
     */
    public function getFunction(string $id): ?InngestFunction {
        return $this->functions[$id] ?? null;
    }

    /**
     * Sends one or more events to Inngest
     *
     * Accepts an Event, an event array (`['name' => ..., 'data' => ...]`),
     * or a list of either.
     *
     * @param Event|array<mixed> $events Event(s) to send
     * @param string|null        $env    Inngest environment to send to;
     *                                   defaults to the client's
     *
     * @throws ApiException              When Inngest rejects the events
     * @throws \RuntimeException         When no event key is set in cloud
     *                                   mode (code 8101)
     * @throws \InvalidArgumentException When an event is invalid
     *
     * @return SendEventResult
     */
    public function send(
        Event|array $events,
        ?string $env = null
    ): SendEventResult {
        $now_ms   = (int)floor(microtime(true) * 1000);
        $payloads = $this->preparePayloads(
            static::normalizeEvents($events),
            $now_ms
        );

        if ($payloads === []) {
            $this->logger->warning(
                'inngest.send() called with no events; no events have been ' .
                'sent'
            );
            $result = new SendEventResult([]);
        } else {
            if (
                $this->config->mode      === Mode::Cloud &&
                $this->config->event_key === null
            ) {
                throw new \RuntimeException(
                    'Failed to send event: Your event or events were not ' .
                    'sent to Inngest. We couldn\'t find an event key to use ' .
                    'to send events to Inngest. Set the `INNGEST_EVENT_KEY` ' .
                    'environment variable; Pass a key to the Client using ' .
                    'the `event_key` option',
                    8101
                );
            }

            $result = $this->postEvents($payloads, $env, $now_ms);
        }

        return $result;
    }

    /**
     * Sends a signal to the run waiting for it with `waitForSignal`
     *
     * Port of JS `inngest.sendSignal()`. Inside a function, prefer
     * `$ctx->step->sendSignal()` so the signal is sent only once.
     *
     * @param string      $signal Signal name
     * @param mixed       $data   Data for the waiting run
     * @param string|null $env    Inngest environment to send to; defaults
     *                            to the client's
     *
     * @throws ApiException When the signal can't be sent
     *
     * @return SendSignalResult `run_id` is null when no run was waiting
     */
    public function sendSignal(
        string $signal,
        mixed $data = null,
        ?string $env = null
    ): SendSignalResult {
        return $this->api->sendSignal($signal, $data, $env);
    }

    /**
     * Turns send() input into a list of Events
     *
     * @param Event|array<mixed> $events Input
     *
     * @throws \InvalidArgumentException When an item isn't an event
     *
     * @return list<Event>
     */
    protected static function normalizeEvents(Event|array $events): array {
        if ($events instanceof Event) {
            $events = [$events];
        } elseif (!array_is_list($events)) {
            $events = [$events];
        }

        $result = [];
        foreach ($events as $event) {
            if (is_array($event)) {
                $event = Event::fromArray($event);
            }

            if (!$event instanceof Event) {
                throw new \InvalidArgumentException(
                    'Events must be Event objects or arrays',
                    8102
                );
            }

            $result[] = $event;
        }

        return $result;
    }

    /**
     * Copies events and fills in defaults, like the JS `_send`
     *
     * Sets `ts` when missing and normalizes `meta`. Empty data already
     * encodes as `{}`.
     *
     * @param list<Event> $events Events to send
     * @param int         $now_ms Send time in milliseconds
     *
     * @throws \InvalidArgumentException When event meta is invalid
     *
     * @return list<Event>
     */
    protected function preparePayloads(array $events, int $now_ms): array {
        $payloads = [];

        foreach ($events as $event) {
            $payload       = clone $event;
            $payload->ts   = $event->ts ?: $now_ms;
            $payload->meta = $event->meta?->normalized();
            $payloads[]    = $payload;
        }

        return $payloads;
    }

    /**
     * POSTs events to the event API, with retries
     *
     * @param list<Event> $payloads Events, ready to send
     * @param string|null $env      Environment override
     * @param int         $now_ms   Send time in milliseconds
     *
     * @throws ApiException When every attempt fails
     *
     * @return SendEventResult
     */
    protected function postEvents(
        array $payloads,
        ?string $env,
        int $now_ms
    ): SendEventResult {
        $url = Url::resolve(
            $this->config->event_api_base_url,
            'e/' . rawurlencode(
                $this->config->event_key ?? self::DUMMY_EVENT_KEY
            )
        );

        $headers = $this->headers->sdkHeaders(
            $env ?? $this->config->env,
            [Headers::EVENT_ID_SEED => static::eventIdSeed($now_ms)]
        );

        $body = (string)json_encode(
            $payloads,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        );

        return $this->retry->run(
            fn () => $this->postEventsOnce($url, $headers, $body),
            self::SEND_MAX_ATTEMPTS,
            self::SEND_BASE_DELAY_MS
        );
    }

    /**
     * Makes one attempt at sending events
     *
     * @param string                $url     Event API URL
     * @param array<string, string> $headers Headers
     * @param string                $body    JSON body
     *
     * @throws ApiException When the attempt fails
     *
     * @return SendEventResult
     */
    protected function postEventsOnce(
        string $url,
        array $headers,
        string $body
    ): SendEventResult {
        try {
            $response = $this->http_client->request('POST', $url, [
                'headers'     => $headers,
                'body'        => $body,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new ApiException(
                'Failed to send event: ' . $e->getMessage(),
                6001,
                null,
                $e
            );
        }

        $raw  = (string)$response->getBody();
        $data = json_decode($raw, true);
        $ids  = is_array($data) ? ($data['ids'] ?? []) : null;

        if (
            !is_array($data) ||
            !is_array($ids)  ||
            array_filter($ids, 'is_string') !== $ids
        ) {
            throw $this->responseError($response, $raw, null);
        }

        $error = is_string($data['error'] ?? null) ? $data['error'] : null;
        if (($data['status'] ?? 0) !== 200 || $error !== null) {
            throw $this->responseError($response, $raw, $error);
        }

        return new SendEventResult(array_values($ids));
    }

    /**
     * Builds the error for a failed event send, like JS `getResponseError`
     *
     * @param ResponseInterface $response HTTP response
     * @param string            $raw      Raw response body
     * @param string|null       $error    Error from the response body
     *
     * @return ApiException
     */
    protected function responseError(
        ResponseInterface $response,
        string $raw,
        ?string $error
    ): ApiException {
        $status = $response->getStatusCode();

        $message = $error ?? match ($status) {
            401     => 'Event key Not Found',
            400     => 'Cannot process event payload',
            403     => 'Forbidden',
            404     => 'Event key not found',
            406     => static::jsonOrRaw($raw),
            409,
            412     => 'Event transformation failed',
            413     => 'Event payload too large',
            500     => 'Internal server error',
            default => $raw,
        };

        return new ApiException(
            "Inngest API Error: $status $message",
            6002,
            $status
        );
    }

    /**
     * Returns the body re-encoded as compact JSON, or raw if not JSON
     *
     * @param string $raw Raw body
     *
     * @return string
     */
    protected static function jsonOrRaw(string $raw): string {
        $decoded = json_decode($raw, true);

        return $decoded === null ?
            $raw :
            (string)json_encode($decoded, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Builds the `X-Inngest-Event-Id-Seed` header value
     *
     * The event API derives event IDs from this seed, so retries of the
     * same send produce the same IDs.
     *
     * @param int $now_ms Send time in milliseconds
     *
     * @return string `<ms>,<base64 of 10 random bytes>`
     */
    protected static function eventIdSeed(int $now_ms): string {
        return $now_ms . ',' . base64_encode(random_bytes(10));
    }
}
