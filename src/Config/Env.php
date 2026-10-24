<?php

declare(strict_types=1);

namespace DealNews\Inngest\Config;

/**
 * Reads Inngest environment variables
 *
 * By default values come from `getenv()`. Pass an array to read from a
 * fixed set instead (useful in tests, or when env vars arrive per request).
 *
 * Usage:
 *
 * ```php
 * $env = new Env();
 * $env->get(Env::SIGNING_KEY);   // "signkey-prod-..." or null
 * $env->getBool(Env::DEV);       // true, false, or null when unset
 * ```
 *
 * Edge cases:
 *
 * - Empty strings count as unset (JS reads these with `||`).
 * - Only the env vars listed as constants here are used by the SDK.
 *
 * @package DealNews\Inngest\Config
 */
class Env {

    public const SIGNING_KEY          = 'INNGEST_SIGNING_KEY';
    public const SIGNING_KEY_FALLBACK = 'INNGEST_SIGNING_KEY_FALLBACK';
    public const EVENT_KEY            = 'INNGEST_EVENT_KEY';
    public const ENV                  = 'INNGEST_ENV';
    public const BASE_URL             = 'INNGEST_BASE_URL';
    public const API_BASE_URL         = 'INNGEST_API_BASE_URL';
    public const EVENT_API_BASE_URL   = 'INNGEST_EVENT_API_BASE_URL';
    public const DEV                  = 'INNGEST_DEV';
    public const SERVE_ORIGIN         = 'INNGEST_SERVE_ORIGIN';
    public const SERVE_HOST           = 'INNGEST_SERVE_HOST';
    public const SERVE_PATH           = 'INNGEST_SERVE_PATH';
    public const ALLOW_IN_BAND_SYNC   = 'INNGEST_ALLOW_IN_BAND_SYNC';
    public const ENABLE_UNAUTHED_SYNC = 'INNGEST_ENABLE_UNAUTHED_SYNC';

    /**
     * Creates a reader
     *
     * @param array<string, mixed>|null $vars Fixed variables; null reads
     *                                        from getenv()
     */
    public function __construct(protected ?array $vars = null) {
    }

    /**
     * Returns a variable's value, or null when unset or empty
     *
     * @param string $name Variable name
     *
     * @return string|null
     */
    public function get(string $name): ?string {
        if ($this->vars !== null) {
            $value = $this->vars[$name] ?? null;
        } else {
            $value = getenv($name);
        }

        $result = null;
        if (is_scalar($value) && (string)$value !== '') {
            $result = (string)$value;
        }

        return $result;
    }

    /**
     * Returns a variable parsed as a boolean
     *
     * @param string $name Variable name
     *
     * @return bool|null Null when unset or not a recognized boolean
     */
    public function getBool(string $name): ?bool {
        return static::parseBool($this->get($name));
    }

    /**
     * Parses a loose boolean the way the JS SDK's `parseAsBoolean` does
     *
     * Accepts booleans, numbers (non-zero is true), and the strings
     * `true`/`1`/`false`/`0` (case-insensitive, trimmed).
     *
     * @param mixed $value Value to parse
     *
     * @return bool|null Null when the value isn't a recognized boolean
     */
    public static function parseBool(mixed $value): ?bool {
        $result = null;

        if (is_bool($value)) {
            $result = $value;
        } elseif (is_int($value) || is_float($value)) {
            $result = $value != 0;
        } elseif (is_string($value)) {
            $trimmed = strtolower(trim($value));
            if ($trimmed === 'true' || $trimmed === '1') {
                $result = true;
            } elseif ($trimmed === 'false' || $trimmed === '0') {
                $result = false;
            }
        }

        return $result;
    }
}
