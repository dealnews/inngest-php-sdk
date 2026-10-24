<?php

declare(strict_types=1);

namespace DealNews\Inngest\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * An incoming request to the serve endpoint
 *
 * The serve handler only needs the method, the full URL, the headers, and
 * the raw body. Build one from PHP globals, from a PSR-7 request, or by
 * hand from whatever your framework gives you.
 *
 * Usage:
 *
 * ```php
 * // Plain PHP
 * $request = Request::fromGlobals();
 *
 * // PSR-7 (Slim, Laravel/Symfony via their PSR-7 bridges, ...)
 * $request = Request::fromPsr7($psr_request);
 *
 * // By hand
 * $request = new Request(
 *     'POST',
 *     'https://example.com/api/inngest?fnId=my-app-hello',
 *     ['X-Inngest-Signature' => $signature],
 *     $raw_body
 * );
 * ```
 *
 * Edge cases:
 *
 * - Always pass the **raw** body. Signatures are checked against the exact
 *   bytes Inngest sent, so a body that was decoded and re-encoded won't
 *   verify.
 * - The URL must be absolute. It's used to build the URLs reported to
 *   Inngest when syncing; set `serve_origin` / `serve_path` on the client
 *   when the URL PHP sees isn't the public one (proxies, load balancers).
 * - Header names are case-insensitive; they're stored lowercase.
 *
 * @package DealNews\Inngest\Http
 */
class Request {

    /**
     * Request method, uppercase
     *
     * @var string
     */
    public readonly string $method;

    /**
     * Header values keyed by lowercase name
     *
     * @var array<string, string>
     */
    public readonly array $headers;

    /**
     * Creates a request
     *
     * @param string                              $method  HTTP method
     * @param string                              $url     Absolute URL,
     *                                                     with query string
     * @param array<string, string|list<string>> $headers Headers; lists are
     *                                                     joined with `, `
     * @param string|null                         $body    Raw body; null when
     *                                                     there is none
     */
    public function __construct(
        string $method,
        public readonly string $url,
        array $headers = [],
        public readonly ?string $body = null
    ) {
        $this->method = strtoupper($method);

        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string)$name)] = is_array($value) ?
                implode(', ', $value) :
                (string)$value;
        }
        $this->headers = $normalized;
    }

    /**
     * Builds a request from PHP's globals
     *
     * The scheme comes from `X-Forwarded-Proto` when present, then
     * `HTTPS`; the host from `Host`, then `SERVER_NAME` and `SERVER_PORT`.
     *
     * @param array<string, mixed>|null $server Server vars; defaults to
     *                                          `$_SERVER`
     * @param string|null               $body   Raw body; defaults to
     *                                          `php://input`
     *
     * @return self
     */
    public static function fromGlobals(
        ?array $server = null,
        ?string $body = null
    ): self {
        $server ??= $_SERVER;
        $body   ??= static::readInput();

        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $key = (string)$key;
            if (str_starts_with($key, 'HTTP_')) {
                $headers[static::headerName(substr($key, 5))] = (string)$value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[static::headerName($key)] = (string)$value;
            }
        }

        return new self(
            (string)($server['REQUEST_METHOD'] ?? 'GET'),
            static::urlFromServer($server, $headers),
            $headers,
            $body
        );
    }

    /**
     * Builds a request from a PSR-7 server request
     *
     * @param ServerRequestInterface $request PSR-7 request
     *
     * @return self
     */
    public static function fromPsr7(ServerRequestInterface $request): self {
        return new self(
            $request->getMethod(),
            (string)$request->getUri(),
            $request->getHeaders(),
            (string)$request->getBody()
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
     * Returns a query string parameter, or null when it's missing
     *
     * Like JS `URLSearchParams.get()`, the first value wins.
     *
     * @param string $name Parameter name
     *
     * @return string|null
     */
    public function query(string $name): ?string {
        $query  = (string)parse_url($this->url, PHP_URL_QUERY);
        $result = null;

        if ($query !== '') {
            foreach (explode('&', $query) as $pair) {
                $parts = explode('=', $pair, 2);
                if (urldecode($parts[0]) === $name) {
                    $result = urldecode($parts[1] ?? '');
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Reads the raw request body
     *
     * @return string
     */
    protected static function readInput(): string {
        return (string)file_get_contents('php://input');
    }

    /**
     * Turns a `$_SERVER` key suffix into a header name (`CONTENT_TYPE` →
     * `content-type`)
     *
     * @param string $key Key without the `HTTP_` prefix
     *
     * @return string
     */
    protected static function headerName(string $key): string {
        return strtolower(str_replace('_', '-', $key));
    }

    /**
     * Rebuilds the absolute request URL from server vars
     *
     * @param array<string, mixed>  $server  Server vars
     * @param array<string, string> $headers Headers from the server vars
     *
     * @return string
     */
    protected static function urlFromServer(
        array $server,
        array $headers
    ): string {
        $https  = strtolower((string)($server['HTTPS'] ?? ''));
        $scheme = $https !== '' && $https !== 'off' ? 'https' : 'http';

        $forwarded = $headers['x-forwarded-proto'] ?? '';
        if ($forwarded !== '') {
            $scheme = strtolower(trim(explode(',', $forwarded)[0]));
        }

        $host = $headers['host'] ?? '';
        if ($host === '') {
            $host = (string)($server['SERVER_NAME'] ?? 'localhost');
            $port = (string)($server['SERVER_PORT'] ?? '');
            if (
                $port !== ''                            &&
                !($scheme === 'http' && $port === '80') &&
                !($scheme === 'https' && $port === '443')
            ) {
                $host .= ':' . $port;
            }
        }

        $uri = (string)($server['REQUEST_URI'] ?? '/');
        if (!str_starts_with($uri, '/')) {
            $uri = '/' . $uri;
        }

        return $scheme . '://' . $host . $uri;
    }
}
