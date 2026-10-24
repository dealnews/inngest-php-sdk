<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

/**
 * A response from the serve endpoint, ready to send back to Inngest
 *
 * Usage:
 *
 * ```php
 * $response = $handler->handle(Request::fromGlobals());
 *
 * // Plain PHP
 * $response->send();
 *
 * // PSR-7
 * return $response->toPsr7();
 * ```
 *
 * Edge cases:
 *
 * - Send the body exactly as is: responses are signed, and the signature
 *   covers these bytes.
 * - Header names are stored lowercase.
 *
 * @package DealNews\Inngest\Http
 */
class Response {

    /**
     * Flags used for every JSON body (match JS `JSON.stringify()`)
     *
     * @var int
     */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE                       |
        JSON_THROW_ON_ERROR;

    /**
     * Header values keyed by lowercase name
     *
     * @var array<string, string>
     */
    public readonly array $headers;

    /**
     * Creates a response
     *
     * @param int                   $status  HTTP status code
     * @param array<string, string> $headers Headers
     * @param string                $body    Body
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = ''
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string)$name)] = $value;
        }
        $this->headers = $normalized;
    }

    /**
     * Creates a JSON response
     *
     * @param int                   $status  HTTP status code
     * @param mixed                 $data    Data to encode
     * @param array<string, string> $headers Extra headers
     *
     * @throws \JsonException When the data can't be encoded
     *
     * @return self
     */
    public static function json(
        int $status,
        mixed $data,
        array $headers = []
    ): self {
        return new self(
            $status,
            array_merge(['content-type' => 'application/json'], $headers),
            json_encode($data, self::JSON_FLAGS)
        );
    }

    /**
     * Returns a header value, or null when it's missing
     *
     * @param string $name Header name (any case)
     *
     * @return string|null
     */
    public function header(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Returns a copy with different headers
     *
     * @param array<string, string> $headers Headers
     *
     * @return self
     */
    public function withHeaders(array $headers): self {
        return new self($this->status, $headers, $this->body);
    }

    /**
     * Sends the status, headers, and body using PHP's output functions
     *
     * @return void
     */
    public function send(): void {
        if (!$this->headersSent()) {
            $this->emitStatus($this->status);
            foreach ($this->headers as $name => $value) {
                $this->emitHeader($name . ': ' . $value);
            }
        }

        echo $this->body;
    }

    /**
     * Converts to a PSR-7 response
     *
     * @return ResponseInterface
     */
    public function toPsr7(): ResponseInterface {
        return new Psr7Response($this->status, $this->headers, $this->body);
    }

    /**
     * Whether PHP has already sent headers
     *
     * @return bool
     */
    protected function headersSent(): bool {
        return headers_sent();
    }

    /**
     * Sets the HTTP status code
     *
     * @param int $status Status code
     *
     * @return void
     */
    protected function emitStatus(int $status): void {
        http_response_code($status);
    }

    /**
     * Sends one header line
     *
     * @param string $line `Name: value`
     *
     * @return void
     */
    protected function emitHeader(string $line): void {
        header($line);
    }
}
