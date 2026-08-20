<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

/**
 * Transport used by ServeHandler to make outbound requests to an Inngest Server.
 */
interface HttpTransportInterface
{
    /**
     * @param array<int, string> $headers Header lines, e.g. "Content-Type: application/json"
     * @return array{status: int, body: string}
     */
    public function post(string $url, string $body, array $headers): array;

    /**
     * @param array<int, string> $headers Header lines, e.g. "Content-Type: application/json"
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers): array;
}
