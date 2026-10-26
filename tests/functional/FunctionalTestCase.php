<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Tests\functional\app\DevFunctions;
use DealNews\Inngest\Tests\functional\app\TestApp;
use DealNews\Inngest\Tests\TestCase;

/**
 * Base class for tests that run functions on a real Inngest dev server
 *
 * Talks to the stack from tests/functional/docker-compose.yml: the dev
 * server (`FUNCTIONAL_DEV_URL`) and the dev-mode test app
 * (`FUNCTIONAL_APP_URL`). Events go out through the SDK's own
 * `Client::send()`.
 *
 * Subclasses need `#[Group('functional')]` so `composer test` skips them.
 *
 * @package DealNews\Inngest\Tests\functional
 */
abstract class FunctionalTestCase extends TestCase {

    /**
     * Dev server helper
     *
     * @var DevServer|null
     */
    protected static ?DevServer $dev = null;

    /**
     * SDK client used to send events
     *
     * @var Client|null
     */
    protected static ?Client $sender = null;

    /**
     * Shared state store
     *
     * @var StateStore|null
     */
    protected static ?StateStore $store = null;

    /**
     * Waits for the dev server and makes sure the app is synced
     *
     * The dev server syncs the app itself (`-u` in docker-compose.yml);
     * if that hasn't happened, sync it the way the JS harness does (PUT to
     * the serve URL).
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (self::$dev === null) {
            $dev = new DevServer(static::env('FUNCTIONAL_DEV_URL'));
            $dev->waitUntilReady();
            $count = static::devFunctionCount();

            try {
                $dev->waitForApp(
                    TestApp::APP_IDS['dev'],
                    $count,
                    5
                );
            } catch (\RuntimeException) {
                static::sync(static::env('FUNCTIONAL_APP_URL'));
                $dev->waitForApp(
                    TestApp::APP_IDS['dev'],
                    $count
                );
            }

            self::$dev    = $dev;
            self::$store  = StateStore::fromEnv();
            self::$sender = new Client(
                'php-functional-tests',
                new ClientOptions(
                    is_dev: true,
                    base_url: $dev->getUrl()
                )
            );
        }
    }

    /**
     * Returns how many function configs the dev app registers
     *
     * Builds the app's functions here and counts them, `-failure`
     * companions included (each is its own config on the server).
     *
     * @return int
     */
    protected static function devFunctionCount(): int {
        $client = new Client(
            TestApp::APP_IDS['dev'],
            new ClientOptions(is_dev: true)
        );
        DevFunctions::register($client, StateStore::fromEnv());

        $count = 0;
        foreach ($client->getFunctions() as $function) {
            $count += count($function->configIds());
        }

        return $count;
    }

    /**
     * Returns the dev server helper
     *
     * @return DevServer
     */
    protected function dev(): DevServer {
        return self::$dev;
    }

    /**
     * Returns the shared state store
     *
     * @return StateStore
     */
    protected function store(): StateStore {
        return self::$store;
    }

    /**
     * Sends an event through the SDK client
     *
     * @param string       $name Event name
     * @param array<mixed> $data Event data
     *
     * @return string Event ID
     */
    protected function send(string $name, array $data = []): string {
        $result = self::$sender->send(new Event($name, $data));
        $this->assertCount(1, $result->ids);

        return $result->ids[0];
    }

    /**
     * Sends an event and waits for the run it starts
     *
     * @param string       $event    Event name
     * @param string       $function Function name
     * @param array<mixed> $data     Event data
     *
     * @return string Run ID
     */
    protected function start(
        string $event,
        string $function,
        array $data = []
    ): string {
        return $this->dev()->waitForRun($this->send($event, $data), $function);
    }

    /**
     * Sends an event that runs both copies of a twinned function
     *
     * See `DevFunctions::twin()`.
     *
     * @param string       $event    Event name
     * @param string       $function Function name (without suffix)
     * @param array<mixed> $data     Event data
     *
     * @return array{cp: string, nocp: string} Run IDs: with and without
     *                                         checkpointing
     */
    protected function startTwins(
        string $event,
        string $function,
        array $data = []
    ): array {
        $event_id = $this->send($event, $data);

        return [
            'cp'   => $this->dev()->waitForRun($event_id, $function),
            'nocp' => $this->dev()->waitForRun(
                $event_id,
                $function . DevFunctions::NO_CHECKPOINT_SUFFIX
            ),
        ];
    }

    /**
     * Waits for a run to complete and returns its output
     *
     * @param string $run_id  Run ID
     * @param float  $timeout Seconds
     *
     * @return mixed
     */
    protected function assertRunCompletes(
        string $run_id,
        float $timeout = DevServer::DEFAULT_TIMEOUT
    ): mixed {
        $result = $this->dev()->waitForRunEnd($run_id, $timeout);
        $this->assertSame(
            'COMPLETED',
            $result->status,
            'Run failed: ' . json_encode($result->output->error)
        );

        return $result->output->data;
    }

    /**
     * Waits for a run to fail and returns its error
     *
     * @param string $run_id  Run ID
     * @param float  $timeout Seconds
     *
     * @return array<string, mixed>
     */
    protected function assertRunFails(
        string $run_id,
        float $timeout = DevServer::DEFAULT_TIMEOUT
    ): array {
        $result = $this->dev()->waitForRunEnd($run_id, $timeout);
        $this->assertSame(
            'FAILED',
            $result->status,
            'Run completed: ' . json_encode($result->output->data)
        );
        $this->assertIsArray($result->output->error);

        return $result->output->error;
    }

    /**
     * Asserts a run's trace has a span and returns its output
     *
     * @param string      $run_id Run ID
     * @param string      $name   Step name
     * @param string|null $status Status to match
     *
     * @return SpanOutput
     */
    protected function assertSpan(
        string $run_id,
        string $name,
        ?string $status = 'COMPLETED'
    ): SpanOutput {
        $span = $this->dev()->findSpan($run_id, $name, $status);
        $this->assertNotNull(
            $span,
            "No span \"$name\" ($status) in run $run_id"
        );

        return $span->output_id === null
            ? new SpanOutput()
            : $this->dev()->output($span->output_id);
    }

    /**
     * Syncs an app by sending a PUT to its serve URL
     *
     * @param string $url Serve URL
     *
     * @throws \RuntimeException When the app doesn't answer 200
     *
     * @return void
     */
    protected static function sync(string $url): void {
        $res = (new \GuzzleHttp\Client())->request('PUT', $url, [
            'http_errors' => false,
        ]);
        if ($res->getStatusCode() !== 200) {
            throw new \RuntimeException(
                "Sync failed ({$res->getStatusCode()}): " . $res->getBody()
            );
        }
    }

    /**
     * Reads a required env var
     *
     * @param string $name Name
     *
     * @return string
     */
    protected static function env(string $name): string {
        $value = getenv($name);
        if ($value === false || $value === '') {
            self::markTestSkipped(
                "$name isn't set; run tests/functional/run.sh"
            );
        }

        return $value;
    }
}
