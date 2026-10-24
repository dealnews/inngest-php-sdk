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
     * Sets query parameters like JS `URLSearchParams.set()`
     *
     * An existing parameter is replaced in place (later duplicates are
     * dropped); new ones are appended. The whole query is re-serialized
     * the way `URLSearchParams` does it: spaces become `+`, and only
     * `*-._` and alphanumerics are left unescaped.
     *
     * @param string                $url    Absolute URL
     * @param array<string, string> $params Parameters to set
     *
     * @return string
     */
    public static function withQuery(string $url, array $params): string {
        $uri   = new Uri($url);
        $pairs = [];

        $query = $uri->getQuery();
        if ($query !== '') {
            foreach (explode('&', $query) as $pair) {
                $parts   = explode('=', $pair, 2);
                $pairs[] = [
                    urldecode($parts[0]),
                    urldecode($parts[1] ?? ''),
                ];
            }
        }

        foreach ($params as $name => $value) {
            $found = false;
            $kept  = [];
            foreach ($pairs as $pair) {
                if ($pair[0] === $name) {
                    if (!$found) {
                        $kept[] = [$name, $value];
                        $found  = true;
                    }
                } else {
                    $kept[] = $pair;
                }
            }
            if (!$found) {
                $kept[] = [$name, $value];
            }
            $pairs = $kept;
        }

        $encoded = [];
        foreach ($pairs as $pair) {
            $encoded[] = static::formEncode($pair[0]) . '=' .
                         static::formEncode($pair[1]);
        }

        return (string)static::withRootPath(
            $uri->withQuery(implode('&', $encoded))
        );
    }

    /**
     * Encodes a query component like `application/x-www-form-urlencoded`
     *
     * @param string $value Raw value
     *
     * @return string
     */
    protected static function formEncode(string $value): string {
        return str_replace('%2A', '*', urlencode($value));
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
