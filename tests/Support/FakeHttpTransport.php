<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\Support;

use DealNews\Inngest\Http\HttpTransportInterface;

/**
 * Test double recording every request made through it and returning
 * pre-queued canned responses (falling back to a default 200 when the
 * queue is empty).
 */
final class FakeHttpTransport implements HttpTransportInterface
{
    /** @var array<int, array{method: string, url: string, body: ?string, headers: array<int, string>}> */
    public array $calls = [];

    /** @var array<int, array{status: int, body: string}> */
    private array $responses = [];

    public function queueResponse(int $status, string $body = ''): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body];
    }

    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, string $body, array $headers): array
    {
        $this->calls[] = ['method' => 'POST', 'url' => $url, 'body' => $body, 'headers' => $headers];
        return array_shift($this->responses) ?? ['status' => 200, 'body' => '{}'];
    }

    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers): array
    {
        $this->calls[] = ['method' => 'GET', 'url' => $url, 'body' => null, 'headers' => $headers];
        return array_shift($this->responses) ?? ['status' => 200, 'body' => '{}'];
    }

    /**
     * @return array<int, array{method: string, url: string, body: ?string, headers: array<int, string>}>
     */
    public function checkpointCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => str_contains($call['url'], '/v1/checkpoint/')
        ));
    }
}
