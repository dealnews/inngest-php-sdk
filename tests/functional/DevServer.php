<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Queries an Inngest server's dev API and GraphQL API
 *
 * PHP port of the JS SDK's test helpers (`src/test/helpers.ts`
 * `eventRunWithName`, `runHasTimeline`, `receivedEventWithName`,
 * `checkIntrospection`, and test-harness `fetchRunResult`). Works against
 * `inngest dev` and `inngest start` (both serve `/v0/gql`).
 *
 * Every `wait*`/`find*` method polls until it succeeds or the timeout
 * passes, like the JS helpers.
 *
 * Usage:
 *
 * ```php
 * $dev    = new DevServer('http://dev:8288');
 * $run_id = $dev->waitForRun($event_id, 'hello-world');
 * $result = $dev->waitForRunEnd($run_id);
 * ```
 *
 * @package DealNews\Inngest\Tests\functional
 */
class DevServer {

    /**
     * Seconds between polls
     *
     * @var float
     */
    public const POLL_INTERVAL = 0.25;

    /**
     * Default seconds to wait for something to show up
     *
     * @var float
     */
    public const DEFAULT_TIMEOUT = 30.0;

    /**
     * Span fields fetched at every level of the trace tree
     *
     * @var string
     */
    protected const SPAN_FIELDS = 'name stepOp attempts outputID status';

    /**
     * HTTP client
     *
     * @var ClientInterface
     */
    protected ClientInterface $http;

    /**
     * Creates the helper
     *
     * @param string               $url  Server base URL
     * @param ClientInterface|null $http HTTP client
     */
    public function __construct(
        protected string $url,
        ?ClientInterface $http = null
    ) {
        $this->url  = rtrim($url, '/');
        $this->http = $http ?? new Guzzle([
            RequestOptions::TIMEOUT     => 10,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * Returns the server's base URL
     *
     * @return string
     */
    public function getUrl(): string {
        return $this->url;
    }

    /**
     * Waits until the server answers
     *
     * @param float $timeout Seconds
     *
     * @throws \RuntimeException When it never does
     *
     * @return void
     */
    public function waitUntilReady(float $timeout = 60.0): void {
        $this->waitFor(
            function (): ?bool {
                try {
                    $res = $this->http->request('GET', $this->url . '/health');
                    $ok  = $res->getStatusCode() === 200 ? true : null;
                } catch (\Throwable) {
                    $ok = null;
                }

                return $ok;
            },
            $timeout,
            "Inngest server at {$this->url} never became ready"
        );
    }

    /**
     * Returns the functions the dev server knows about (`GET /dev`)
     *
     * Only `inngest dev` serves this.
     *
     * @return list<array<string, mixed>>
     */
    public function functions(): array {
        $res  = $this->http->request('GET', $this->url . '/dev');
        $data = json_decode((string)$res->getBody(), true);

        return is_array($data) ? ($data['functions'] ?? []) : [];
    }

    /**
     * Returns the apps the server knows about, with their functions
     *
     * Works on both servers (GraphQL).
     *
     * @return list<array<string, mixed>>
     */
    public function apps(): array {
        $data = $this->gql(
            'query { apps { id externalID name url sdkLanguage ' .
            'sdkVersion framework connected error ' .
            'functions { id slug name } } }'
        );

        return $data['apps'] ?? [];
    }

    /**
     * Waits for an app with at least the given number of functions
     *
     * @param string $app_id  App ID
     * @param int    $count   Minimum number of functions
     * @param float  $timeout Seconds
     *
     * @throws \RuntimeException When it never shows up
     *
     * @return array<string, mixed> The app
     */
    public function waitForApp(
        string $app_id,
        int $count = 1,
        float $timeout = 10.0
    ): array {
        return $this->waitFor(
            function () use ($app_id, $count): ?array {
                $found = null;
                foreach ($this->apps() as $app) {
                    if (
                        $app['externalID'] === $app_id &&
                        count($app['functions'] ?? []) >= $count
                    ) {
                        $found = $app;
                    }
                }

                return $found;
            },
            $timeout,
            "App $app_id was never registered"
        );
    }

    /**
     * Waits for the run an event started for a function
     *
     * @param string $event_id Event ID
     * @param string $function Function name
     * @param float  $timeout  Seconds
     *
     * @throws \RuntimeException When no such run shows up
     *
     * @return string Run ID
     */
    public function waitForRun(
        string $event_id,
        string $function,
        float $timeout = self::DEFAULT_TIMEOUT
    ): string {
        return $this->waitFor(
            fn () => $this->findRun($event_id, $function),
            $timeout,
            "No run of \"$function\" for event $event_id"
        );
    }

    /**
     * Returns the run an event started for a function, if any yet
     *
     * @param string $event_id Event ID
     * @param string $function Function name
     *
     * @return string|null Run ID
     */
    public function findRun(string $event_id, string $function): ?string {
        $data = $this->gql(
            'query { stream(query: {limit: 999, ' .
            'includeInternalEvents: true}) ' .
            '{ id runs { id function { name } } } }'
        );

        $run_id = null;
        foreach ($data['stream'] ?? [] as $item) {
            if (($item['id'] ?? null) !== $event_id) {
                continue;
            }
            foreach ($item['runs'] ?? [] as $run) {
                if (($run['function']['name'] ?? null) === $function) {
                    $run_id = $run['id'] ?? null;
                }
            }
        }

        return $run_id;
    }

    /**
     * Waits for a run to complete or fail
     *
     * @param string $run_id  Run ID
     * @param float  $timeout Seconds
     *
     * @throws \RuntimeException When it doesn't end in time
     *
     * @return RunResult
     */
    public function waitForRunEnd(
        string $run_id,
        float $timeout = self::DEFAULT_TIMEOUT
    ): RunResult {
        $trace = $this->waitFor(
            function () use ($run_id): ?array {
                $data  = $this->tryGql(
                    'query ($runId: String!) { run(runID: $runId) ' .
                    '{ trace { status outputID } } }',
                    ['runId' => $run_id]
                );
                $trace = $data['run']['trace'] ?? null;
                $ended = is_array($trace) && in_array(
                    $trace['status'] ?? null,
                    RunResult::END_STATUSES,
                    true
                );

                return $ended ? $trace : null;
            },
            $timeout,
            "Run $run_id didn't end"
        );

        $output = $trace['outputID'] === null
            ? new SpanOutput()
            : $this->output($trace['outputID']);

        return new RunResult($trace['status'], $output);
    }

    /**
     * Returns a run's trace tree (three levels, like the JS helper)
     *
     * @param string $run_id Run ID
     *
     * @return Span|null The root span
     */
    public function trace(string $run_id): ?Span {
        $fields = self::SPAN_FIELDS;
        $data   = $this->tryGql(
            'query ($runId: String!) { run(runID: $runId) { trace { ' .
            "$fields childrenSpans { $fields " .
            "childrenSpans { $fields } } } } }",
            ['runId' => $run_id]
        );
        $trace = $data['run']['trace'] ?? null;

        return is_array($trace) ? Span::fromArray($trace) : null;
    }

    /**
     * Waits for a span with a name (and status) in a run's trace
     *
     * @param string      $run_id  Run ID
     * @param string      $name    Span (step) name
     * @param string|null $status  Status to match, e.g. `COMPLETED`
     * @param float       $timeout Seconds; 0 checks once
     *
     * @return Span|null Null when it never shows up
     */
    public function findSpan(
        string $run_id,
        string $name,
        ?string $status = null,
        float $timeout = self::DEFAULT_TIMEOUT
    ): ?Span {
        $span = null;
        try {
            $span = $this->waitFor(
                fn () => $this->trace($run_id)?->find($name, $status),
                $timeout,
                ''
            );
        } catch (\RuntimeException) {
            $span = null;
        }

        return $span;
    }

    /**
     * Returns a span's output
     *
     * @param string $output_id Span output ID
     *
     * @return SpanOutput
     */
    public function output(string $output_id): SpanOutput {
        $data = $this->gql(
            'query ($id: String!) { runTraceSpanOutputByID(outputID: $id) ' .
            '{ data error { name message stack cause } } }',
            ['id' => $output_id]
        );

        return SpanOutput::fromArray($data['runTraceSpanOutputByID'] ?? []);
    }

    /**
     * Returns the received events with a name, newest first
     *
     * @param string $name Event name
     *
     * @return list<array<string, mixed>> `{id, name, payload}`; `payload`
     *                                    is the event data as JSON
     */
    public function events(string $name): array {
        $data = $this->gql(
            'query Events($query: EventsQuery!) { events(query: $query) ' .
            '{ id name payload } }',
            ['query' => new \stdClass()]
        );

        return array_values(array_filter(
            $data['events'] ?? [],
            static fn ($event) => ($event['name'] ?? null) === $name
        ));
    }

    /**
     * Runs a GraphQL query
     *
     * @param string               $query     Query
     * @param array<string, mixed> $variables Variables
     *
     * @throws \RuntimeException When the server answers with errors
     *
     * @return array<string, mixed> The `data` member
     */
    public function gql(string $query, array $variables = []): array {
        $res  = $this->http->request('POST', $this->url . '/v0/gql', [
            RequestOptions::JSON => [
                'query'     => $query,
                'variables' => $variables === []
                    ? new \stdClass()
                    : $variables,
            ],
        ]);
        $body = (string)$res->getBody();
        $data = json_decode($body, true);

        if (
            $res->getStatusCode() !== 200 ||
            !is_array($data)              ||
            !empty($data['errors'])
        ) {
            throw new \RuntimeException(
                "GraphQL query failed ({$res->getStatusCode()}): $body"
            );
        }

        return $data['data'] ?? [];
    }

    /**
     * Runs a GraphQL query, returning [] when it fails
     *
     * For polling: right after a run shows up in the event stream, run
     * queries can still fail with "no rows in result set".
     *
     * @param string               $query     Query
     * @param array<string, mixed> $variables Variables
     *
     * @return array<string, mixed>
     */
    protected function tryGql(string $query, array $variables = []): array {
        try {
            $data = $this->gql($query, $variables);
        } catch (\RuntimeException) {
            $data = [];
        }

        return $data;
    }

    /**
     * Calls a callback until it returns non-null
     *
     * @param callable $callback Returns null to keep waiting
     * @param float    $timeout  Seconds; 0 tries once
     * @param string   $message  Exception message on timeout
     *
     * @throws \RuntimeException When the timeout passes
     *
     * @return mixed What the callback returned
     */
    public function waitFor(
        callable $callback,
        float $timeout,
        string $message
    ): mixed {
        $deadline = microtime(true) + $timeout;

        $result = $callback();
        while ($result === null && microtime(true) < $deadline) {
            usleep((int)(self::POLL_INTERVAL * 1_000_000));
            $result = $callback();
        }

        if ($result === null) {
            throw new \RuntimeException("$message (waited {$timeout}s)");
        }

        return $result;
    }
}
