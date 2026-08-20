<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

/**
 * Default HttpTransportInterface implementation, backed by a single reused
 * cURL handle so repeated requests (e.g. a run of checkpoint flushes) within
 * one ServeHandler's lifetime avoid paying for a fresh TCP/TLS handshake
 * every time.
 */
class CurlTransport implements HttpTransportInterface
{
    private ?\CurlHandle $handle = null;

    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, string $body, array $headers): array
    {
        $ch = $this->handle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        return $this->exec($ch);
    }

    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers): array
    {
        $ch = $this->handle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        return $this->exec($ch);
    }

    private function handle(): \CurlHandle
    {
        if ($this->handle === null) {
            $this->handle = curl_init();
            curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, true);
        }

        return $this->handle;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function exec(\CurlHandle $ch): array
    {
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'status' => (int) $status,
            'body' => $body === false ? '' : $body,
        ];
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
        }
    }
}
