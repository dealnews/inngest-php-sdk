<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Tests\functional\app\TestApp;
use GuzzleHttp\Client as Guzzle;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cloud mode against a self-hosted Inngest server with a signing key
 *
 * `inngest start --signing-key` signs every request it sends the app and
 * checks the app's API auth. A run that completes means the app accepted
 * the server's signatures over the raw body (D7), signed its own
 * responses, and authenticated its API calls (sync, checkpoints) with the
 * hashed key. The bodies include unicode and slashes, which re-encoding
 * JSON would change.
 *
 * @package DealNews\Inngest\Tests\functional
 */
#[CoversNothing]
#[Group('functional')]
class SignedTest extends FunctionalTestCase {

    /**
     * Self-hosted server helper
     *
     * @var DevServer|null
     */
    protected static ?DevServer $cloud = null;

    /**
     * SDK client (cloud mode) used to send events
     *
     * @var Client|null
     */
    protected static ?Client $cloud_sender = null;

    /**
     * Waits for the self-hosted server and syncs the cloud app
     *
     * It doesn't sync apps by itself, so this sends the PUT, which makes
     * the app register over the API with its signing key.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (self::$cloud === null) {
            $cloud = new DevServer(static::env('FUNCTIONAL_CLOUD_URL'));
            $cloud->waitUntilReady();
            static::sync(static::env('FUNCTIONAL_CLOUD_APP_URL'));
            $cloud->waitForApp(TestApp::APP_IDS['cloud'], 3);

            self::$cloud        = $cloud;
            self::$cloud_sender = new Client(
                'php-functional-tests',
                new ClientOptions(
                    event_key: static::env('FUNCTIONAL_EVENT_KEY'),
                    base_url: $cloud->getUrl(),
                    is_dev: false
                )
            );
        }
    }

    /**
     * A function with no steps
     *
     * @return void
     */
    public function testRunWithoutSteps(): void {
        $run_id = $this->startCloud('cloud/hello', 'cloud-hello', [
            'name' => 'php',
        ]);

        $this->assertSame(
            ['hello' => 'php'],
            $this->cloudResult($run_id)
        );
    }

    /**
     * Checkpointing modes
     *
     * @return array<string, array{string, int}>
     */
    public static function checkpointingProvider(): array {
        return [
            // 2 requests: steps + sleep, then the rest after the sleep
            'checkpointing'    => ['on', 2],
            // 4 requests: step a, sleep, step b, finish
            'no checkpointing' => ['off', 4],
        ];
    }

    /**
     * Steps and a sleep: several signed requests both ways
     *
     * @param string $mode     `on` or `off`
     * @param int    $requests Expected handler requests
     *
     * @return void
     */
    #[DataProvider('checkpointingProvider')]
    public function testRunWithSteps(string $mode, int $requests): void {
        $run_id = $this->startCloud("cloud/steps.$mode", "cloud-steps-$mode", [
            'x' => 'ünï/cødé "quoted"',
        ]);

        $this->assertSame(
            [
                'a' => 'héllo / wörld ✓',
                'b' => 'héllo / wörld ✓ <ünï/cødé "quoted">',
            ],
            $this->cloudResult($run_id)
        );
        $this->assertCount($requests, $this->store()->read($run_id));

        $failed = array_filter(
            $this->store()->read('requests-cloud'),
            static fn (array $r) => $r['status'] === 401
        );
        $this->assertSame([], array_values($failed));
    }

    /**
     * The app turns away unsigned and wrongly signed requests
     *
     * @return void
     */
    public function testRejectsUnsignedRequests(): void {
        $url  = static::env('FUNCTIONAL_CLOUD_APP_URL');
        $http = new Guzzle(['http_errors' => false]);
        $body = '{"event":{"name":"cloud/hello","data":{}},"events":[],' .
                '"steps":{},"ctx":{"run_id":"x","attempt":0}}';

        $get = $http->request('GET', $url);
        $this->assertSame(401, $get->getStatusCode());

        $post = $http->request(
            'POST',
            $url . '?fnId=php-functional-cloud-cloud-hello&stepId=step',
            ['body' => $body]
        );
        $this->assertSame(401, $post->getStatusCode());

        $wrong = $http->request(
            'POST',
            $url . '?fnId=php-functional-cloud-cloud-hello&stepId=step',
            [
                'body'    => $body,
                'headers' => [
                    'X-Inngest-Signature' => (new Signature())->signatureHeader(
                        $body . ' ',
                        static::env('FUNCTIONAL_SIGNING_KEY')
                    ),
                ],
            ]
        );
        $this->assertSame(401, $wrong->getStatusCode());
    }

    /**
     * A correctly signed GET gets the authenticated introspection body
     *
     * @return void
     */
    public function testSignedIntrospection(): void {
        $res = (new Guzzle())->request(
            'GET',
            static::env('FUNCTIONAL_CLOUD_APP_URL'),
            [
                'headers' => [
                    'X-Inngest-Signature' => (new Signature())->signatureHeader(
                        '',
                        static::env('FUNCTIONAL_SIGNING_KEY')
                    ),
                ],
            ]
        );

        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode((string)$res->getBody(), true);
        $this->assertTrue($body['authentication_succeeded']);
        $this->assertSame('cloud', $body['mode']);
        $this->assertTrue($body['has_signing_key']);
        $this->assertNotEmpty($res->getHeaderLine('X-Inngest-Signature'));
    }

    /**
     * Sends an event to the self-hosted server and waits for its run
     *
     * @param string       $event    Event name
     * @param string       $function Function name
     * @param array<mixed> $data     Event data
     *
     * @return string Run ID
     */
    protected function startCloud(
        string $event,
        string $function,
        array $data = []
    ): string {
        $result = self::$cloud_sender->send(new Event($event, $data));
        $this->assertCount(1, $result->ids);

        return self::$cloud->waitForRun($result->ids[0], $function);
    }

    /**
     * Waits for a run on the self-hosted server to complete
     *
     * @param string $run_id Run ID
     *
     * @return mixed Its output
     */
    protected function cloudResult(string $run_id): mixed {
        $result = self::$cloud->waitForRunEnd($run_id);
        $this->assertSame(
            'COMPLETED',
            $result->status,
            'Run failed: ' . json_encode($result->output->error)
        );

        return $result->output->data;
    }
}
