<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

use DealNews\Inngest\Client;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Tests\functional\app\DevFunctions;
use DealNews\Inngest\Tests\functional\app\TestApp;
use GuzzleHttp\Client as Guzzle;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Syncing and introspection against a real dev server
 *
 * Ports the JS `checkIntrospection()` helper (run by every
 * `src/test/functions/*` test) for all of the app's functions at once.
 *
 * @package DealNews\Inngest\Tests\functional
 */
#[CoversNothing]
#[Group('functional')]
class SyncTest extends FunctionalTestCase {

    /**
     * Serve URL the dev server knows the app by
     *
     * @var string
     */
    public const SERVE_URL = 'http://app:8000/api/inngest';

    /**
     * Every function config shows up in the dev server with the same
     * name, triggers, step URL, and retries
     *
     * @return void
     */
    public function testFunctionsRegistered(): void {
        $client = new Client(
            TestApp::APP_IDS['dev'],
            new ClientOptions(is_dev: true)
        );
        DevFunctions::register($client, $this->store());

        $expected = [];
        foreach ($client->getFunctions() as $function) {
            foreach ($function->getConfigs(self::SERVE_URL) as $config) {
                $data = $config->toArray();
                $step = $data['steps']['step'];
                $item = [
                    'name'     => $data['name'],
                    'slug'     => $data['id'],
                    'triggers' => $data['triggers'],
                    'steps'    => [
                        [
                            'id'   => 'step',
                            'name' => 'step',
                            'uri'  => $step['runtime']['url'],
                        ],
                    ],
                ];
                if (isset($step['retries']['attempts'])) {
                    $item['steps'][0]['retries'] = $step['retries']['attempts'];
                }
                $expected[$data['id']] = $item;
            }
        }

        $actual = [];
        foreach ($this->dev()->functions() as $function) {
            if (str_starts_with($function['slug'], 'php-functional-')) {
                unset($function['id'], $function['fv']);
                $actual[$function['slug']] = $function;
            }
        }

        ksort($expected);
        ksort($actual);
        $this->assertJsonDataSame(
            (string)json_encode($expected),
            (string)json_encode($actual)
        );
    }

    /**
     * The dev server sees the app's SDK details
     *
     * @return void
     */
    public function testAppDetails(): void {
        $app = $this->dev()->waitForApp(TestApp::APP_IDS['dev']);

        $this->assertSame(self::SERVE_URL, $app['url']);
        $this->assertSame('php', $app['framework']);
        $this->assertSame('dealnews/inngest-php-sdk', $app['sdkLanguage']);
        $this->assertNull($app['error']);
    }

    /**
     * The dev server's own sync (`-u`) worked, out of band; syncing again
     * with nothing changed reports `modified: false`
     *
     * @return void
     */
    public function testOutOfBandSync(): void {
        $first = $this->store()->read('sync-dev')[0] ?? null;
        $this->assertNotNull($first, 'The dev server never synced the app');
        $this->assertSame(200, $first['status']);
        $this->assertSame('out_of_band', $first['kind']);
        $this->assertNull($first['incoming']);

        static::sync(static::env('FUNCTIONAL_APP_URL'));

        $entries = $this->store()->read('sync-dev');
        $last    = end($entries);
        $this->assertSame(200, $last['status']);
        $this->assertSame(
            ['message' => 'Successfully registered', 'modified' => false],
            json_decode($last['body'], true)
        );
    }

    /**
     * `GET` introspection, like the JS "registered in SDK UI" check
     *
     * @return void
     */
    public function testIntrospection(): void {
        $res = (new Guzzle())->request(
            'GET',
            static::env('FUNCTIONAL_APP_URL')
        );

        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode((string)$res->getBody(), true);
        $this->assertSame('dev', $body['mode']);
        $this->assertFalse($body['has_signing_key']);
        $this->assertFalse($body['has_event_key']);
        $this->assertSame(
            count($this->dev()->functions()) -
                count($this->otherAppFunctions()),
            $body['function_count']
        );
    }

    /**
     * Returns dev server functions that belong to other apps
     *
     * @return list<array<string, mixed>>
     */
    protected function otherAppFunctions(): array {
        return array_values(array_filter(
            $this->dev()->functions(),
            static fn (array $f) => !str_starts_with(
                $f['slug'],
                'php-functional-'
            )
        ));
    }
}
