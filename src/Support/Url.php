<?php

declare(strict_types=1);

namespace DealNews\Inngest\Support;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * URL helpers that mirror how the JS SDK builds URLs
 *
 * JS uses `new URL(path, base)`, which follows RFC 3986 reference
 * resolution: `"e/key"` is resolved relative to the base's directory and
 * `"/fn/register"` replaces the base's path. Guzzle's `UriResolver` does
 * the same.
 *
 * Usage:
 *
 * ```php
 * // https://inn.gs/e/my-key
 * Url::resolve('https://inn.gs/', 'e/my-key');
 *
 * // http://localhost:8288/fn/register
 * Url::resolve('http://localhost:8288', '/fn/register');
 * ```
 *
 * @package DealNews\Inngest\Support
 */
class Url {

    /**
     * Resolves a reference against a base URL, like JS `new URL(ref, base)`
     *
     * @param string $base      Absolute base URL
     * @param string $reference Relative or absolute reference
     *
     * @throws \InvalidArgumentException When either URL is malformed
     *
     * @return string
     */
    public static function resolve(string $base, string $reference): string {
        try {
            $result = UriResolver::resolve(
                new Uri($base),
                new Uri($reference)
            );
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                "Invalid URL: $base + $reference",
                2101,
                $e
            );
        }

        return (string)static::withRootPath($result);
    }

    /**
     * Normalizes an absolute URL like JS `new URL(url).href`
     *
     * Adds `http://` when there's no scheme (the JS SDK's `normalizeUrl`)
     * and a `/` path when the path is empty.
     *
     * @param string $url URL, with or without a scheme
     *
     * @return string|null Null when the URL is invalid or has no host
     */
    public static function normalize(string $url): ?string {
        if (!str_contains($url, '://')) {
            $url = 'http://' . $url;
        }

        $result = null;
        try {
            $uri = new Uri($url);
            if ($uri->getHost() !== '') {
                $result = (string)static::withRootPath($uri);
            }
        } catch (\InvalidArgumentException) {
            $result = null;
        }

        return $result;
    }

    /**
     * Gives a URL with an authority an explicit `/` path when empty
     *
     * @param Uri|\Psr\Http\Message\UriInterface $uri URL
     *
     * @return \Psr\Http\Message\UriInterface
     */
    protected static function withRootPath(
        \Psr\Http\Message\UriInterface $uri
    ): \Psr\Http\Message\UriInterface {
        if ($uri->getAuthority() !== '' && $uri->getPath() === '') {
            $uri = $uri->withPath('/');
        }

        return $uri;
    }
}
