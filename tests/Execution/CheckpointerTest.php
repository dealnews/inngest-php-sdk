<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Execution;

use DealNews\Inngest\Api\ApiClient;
use DealNews\Inngest\Api\ApiException;
use DealNews\Inngest\Api\StaleDispatchError;
use DealNews\Inngest\Config\ClientConfig;
use DealNews\Inngest\Config\ClientOptions;
use DealNews\Inngest\Config\Env;
use DealNews\Inngest\Execution\CheckpointConfig;
use DealNews\Inngest\Execution\Checkpointer;
use DealNews\Inngest\Execution\ExecutionException;
use DealNews\Inngest\Execution\OpCode;
use DealNews\Inngest\Execution\OutgoingOp;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Http\Signature;
use DealNews\Inngest\Support\Retry;
use DealNews\Inngest\Support\Url;
use DealNews\Inngest\Tests\mock\FixedVersion;
use DealNews\Inngest\Tests\mock\MockHttp;
use DealNews\Inngest\Tests\TestCase;
use DealNews\Inngest\ValueObject\ValueObject;
use DealNews\Inngest\Version;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Tests for Checkpointer
 *
 * @package DealNews\Inngest\Tests\Execution
 */
#[CoversClass(Checkpointer::class)]
#[UsesClass(ApiClient::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(CheckpointConfig::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(ClientOptions::class)]
#[UsesClass(Env::class)]
#[UsesClass(ExecutionException::class)]
#[UsesClass(Headers::class)]
#[UsesClass(OutgoingOp::class)]
#[UsesClass(Retry::class)]
#[UsesClass(Signature::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValueObject::class)]
#[UsesClass(Version::class)]
class CheckpointerTest extends TestCase {

    /**
     * Sleeps requested by the retry helper
     *
     * @var \ArrayObject<int, float>
     */
    protected \ArrayObject $sleeps;

    /**
     * Builds a checkpointer on mock HTTP
     *
     * @param MockHttp    $http          Mock HTTP
     * @param string|null $qi_id         Queue item ID
     * @param string|null $request_id    Request ID
     * @param int|null    $generation_id Generation ID
     *
     * @return Checkpointer
     */
    protected function checkpointer(
        MockHttp $http,
        ?string $qi_id = 'qi-1',
        ?string $request_id = null,
        ?int $generation_id = null
    ): Checkpointer {
        $sleeps       = new \ArrayObject();
        $this->sleeps = $sleeps;

        return new Checkpointer(
            new CheckpointConfig(),
            new ApiClient(
                ClientConfig::resolve(
                    'app',
                    new ClientOptions(is_dev: true),
                    new Env([])
                ),
                $http->client,
                new Headers(new FixedVersion())
            ),
            new Retry(static function (float $ms) use ($sleeps): void {
                $sleeps[] = $ms;
            }),
            'run 1',
            'fn-uuid',
            $qi_id,
            $request_id,
            $generation_id,
            1700000000000
        );
    }

    /**
     * Posts the steps with the request's identifiers
     *
     * @return void
     */
    public function testCheckpoint(): void {
        $http = new MockHttp([MockHttp::json(200, new \stdClass())]);
        $op   = (new OutgoingOp(sha1('A'), OpCode::StepRun, 'A'))
            ->withData(['x' => 1]);

        $this->checkpointer($http, 'qi-1', 'req-1', 3)->checkpoint([$op]);

        $request = $http->requests[0];
        $body    = json_decode((string)$request->getBody(), true);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            'http://localhost:8288/v1/checkpoint/run%201/async',
            (string)$request->getUri()
        );
        $this->assertIsInt($body['ts']);
        unset($body['ts']);
        $this->assertSame(
            [
                'run_id'             => 'run 1',
                'fn_id'              => 'fn-uuid',
                'qi_id'              => 'qi-1',
                'request_id'         => 'req-1',
                'generation_id'      => 3,
                'request_started_at' => 1700000000000,
                'steps'              => [[
                    'id'   => sha1('A'),
                    'op'   => 'StepRun',
                    'name' => 'A',
                    'data' => ['x' => 1],
                ]],
            ],
            $body
        );
    }

    /**
     * Missing request and generation IDs are left out
     *
     * @return void
     */
    public function testOptionalIdsOmitted(): void {
        $http = new MockHttp([MockHttp::json(200, new \stdClass())]);

        $this->checkpointer($http)->checkpoint([]);

        $body = json_decode((string)$http->requests[0]->getBody(), true);
        $this->assertArrayNotHasKey('request_id', $body);
        $this->assertArrayNotHasKey('generation_id', $body);
    }

    /**
     * Without a queue item ID nothing is sent
     *
     * @return void
     */
    public function testMissingQueueItem(): void {
        $http = new MockHttp();

        try {
            $this->checkpointer($http, '')->checkpoint([]);
            $this->fail('Expected an exception');
        } catch (ExecutionException $e) {
            $this->assertSame(7104, $e->getCode());
        }

        $this->expectExceptionCode(7104);
        $this->checkpointer($http, null)->checkpoint([]);
    }

    /**
     * Failures retry with backoff, 5 attempts in all
     *
     * @return void
     */
    public function testRetries(): void {
        $http = new MockHttp(array_fill(0, 5, new Response(500, [], 'no')));

        try {
            $this->checkpointer($http)->checkpoint([]);
            $this->fail('Expected an exception');
        } catch (ApiException $e) {
            $this->assertSame(6005, $e->getCode());
        }

        $this->assertCount(5, $http->requests);
        $this->assertCount(4, $this->sleeps);
        $this->assertGreaterThanOrEqual(100, $this->sleeps[0]);
    }

    /**
     * A stale request isn't retried
     *
     * @return void
     */
    public function testStaleNotRetried(): void {
        $http = new MockHttp([MockHttp::json(409, new \stdClass())]);

        $this->expectException(StaleDispatchError::class);
        try {
            $this->checkpointer($http)->checkpoint([]);
        } finally {
            $this->assertCount(1, $http->requests);
        }
    }
}
