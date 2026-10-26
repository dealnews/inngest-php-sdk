<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional\app;

use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Http\Response;
use DealNews\Inngest\Http\ServeHandler;
use DealNews\Inngest\Tests\functional\StateStore;
use Psr\Log\AbstractLogger;

/**
 * The app the functional tests run against
 *
 * Plain PHP wiring, the same way a user would do it without a framework:
 * build a client, create functions, hand each request to `ServeHandler`.
 * Config comes from `INNGEST_*` env vars set in docker-compose.yml.
 *
 * Besides serving, it records what went over the wire so tests can check
 * the protocol (like the JS `recordResponseOps()` helper):
 *
 * - `ops-<run id>`: `{op, name, displayName}` of every op it returned
 * - `sync-<app>`:     status and sync kind of every PUT
 * - `requests-<app>`: method, query, and status of every request
 * - `log-<run id>`:  messages logged through `$ctx->logger`
 *
 * @package DealNews\Inngest\Tests\functional\app
 */
class TestApp {

    /**
     * Env var naming which app to build: `dev` or `cloud`
     *
     * @var string
     */
    public const ENV_APP = 'FUNCTIONAL_APP';

    /**
     * App IDs by app kind
     *
     * @var array<string, string>
     */
    public const APP_IDS = [
        'dev'   => 'php-functional',
        'cloud' => 'php-functional-cloud',
    ];

    /**
     * Creates the app
     *
     * @param Client     $client Client with every function created
     * @param StateStore $store  Shared state store
     * @param string     $kind   `dev` or `cloud`
     */
    public function __construct(
        protected Client $client,
        protected StateStore $store,
        protected string $kind = 'dev'
    ) {
    }

    /**
     * Builds the app named by the `FUNCTIONAL_APP` env var
     *
     * @return self
     */
    public static function fromEnv(): self {
        $kind  = getenv(self::ENV_APP) ?: 'dev';
        $store = StateStore::fromEnv();

        $client = new Client(
            self::APP_IDS[$kind] ?? self::APP_IDS['dev'],
            new ClientOptions(),
            static::logger($store)
        );

        if ($kind === 'cloud') {
            CloudFunctions::register($client, $store);
        } else {
            DevFunctions::register($client, $store);
        }

        return new self($client, $store, $kind);
    }

    /**
     * Handles the current request and sends the response
     *
     * @return void
     */
    public function serve(): void {
        $request  = Request::fromGlobals();
        $response = $this->handle($request);
        $response->send();
    }

    /**
     * Handles one request and records it
     *
     * @param Request $request Request
     *
     * @return Response
     */
    public function handle(Request $request): Response {
        $response = (new ServeHandler($this->client))->handle($request);

        $this->record($request, $response);

        return $response;
    }

    /**
     * Records a request and its response in the state store
     *
     * @param Request  $request  Request
     * @param Response $response Response
     *
     * @return void
     */
    protected function record(Request $request, Response $response): void {
        $this->store->append('requests-' . $this->kind, [
            'method' => $request->method,
            'fnId'   => $request->query('fnId'),
            'stepId' => $request->query('stepId'),
            'status' => $response->status,
        ]);

        if ($request->method === 'PUT') {
            $this->store->append('sync-' . $this->kind, [
                'status'   => $response->status,
                'kind'     => $response->header('x-inngest-sync-kind'),
                'incoming' => $request->header('x-inngest-sync-kind'),
                'body'     => $response->body,
            ]);
        }

        if ($request->method === 'POST' && $request->body !== null) {
            $body   = json_decode($request->body, true);
            $run_id = is_array($body) ? ($body['ctx']['run_id'] ?? null) : null;
            $ops    = json_decode($response->body, true);
            if (is_string($run_id) && is_array($ops) && array_is_list($ops)) {
                foreach ($ops as $op) {
                    $this->store->append('ops-' . $run_id, [
                        'op'          => $op['op']          ?? null,
                        'name'        => $op['name']        ?? null,
                        'displayName' => $op['displayName'] ?? null,
                    ]);
                }
            }
        }
    }

    /**
     * Returns a logger that writes to the PHP server's stderr
     *
     * Shows up in `docker compose logs app`. Records that carry a run ID
     * (everything logged through `$ctx->logger`) also go to the state
     * store.
     *
     * @param StateStore $store State store
     *
     * @return AbstractLogger
     */
    protected static function logger(StateStore $store): AbstractLogger {
        return new class($store) extends AbstractLogger {
            /**
             * Creates the logger
             *
             * @param StateStore $store State store
             */
            public function __construct(protected StateStore $store) {
            }

            /**
             * Writes a log line to stderr
             *
             * @param mixed               $level   Level
             * @param string|\Stringable  $message Message
             * @param array<mixed>        $context Context
             *
             * @return void
             */
            public function log(
                $level,
                string|\Stringable $message,
                array $context = []
            ): void {
                file_put_contents(
                    'php://stderr',
                    sprintf(
                        "[%s] %s %s\n",
                        (string)$level,
                        (string)$message,
                        json_encode($context, JSON_UNESCAPED_SLASHES)
                    )
                );

                $run_id = $context['runID'] ?? null;
                if (is_string($run_id)) {
                    $this->store->append(
                        'log-' . $run_id,
                        (string)$message
                    );
                }
            }
        };
    }
}
