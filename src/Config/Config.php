<?php

declare(strict_types=1);

namespace DealNews\Inngest\Config;

/**
 * Configuration for Inngest SDK
 */
class Config
{
    protected const DEFAULT_API_ORIGIN = 'https://api.inngest.com';
    protected const DEFAULT_EVENT_ORIGIN = 'https://inn.gs';
    protected const DEFAULT_DEV_SERVER_ORIGIN = 'http://localhost:8288';

    /**
     * @param string|null $event_key Event key for sending events
     * @param string|null $signing_key Signing key for request validation
     * @param string|null $signing_key_fallback Fallback signing key
     * @param string|null $env Environment name
     * @param string|null $api_base_url API base URL (overrides INNGEST_DEV)
     * @param string|null $event_api_base_url Event API base URL (overrides INNGEST_DEV)
     * @param bool $is_dev Whether to use dev server
     * @param string|null $serve_origin Origin for serving functions
     * @param string|null $serve_path Path for serving functions
     * @param string|null $log_level Logging level
     */
    public function __construct(
        protected ?string $event_key = null,
        protected ?string $signing_key = null,
        protected ?string $signing_key_fallback = null,
        protected ?string $env = null,
        protected ?string $api_base_url = null,
        protected ?string $event_api_base_url = null,
        protected bool $is_dev = false,
        protected ?string $serve_origin = null,
        protected ?string $serve_path = null,
        protected ?string $log_level = null
    ) {
        $this->loadFromEnvironment();
    }

    protected function loadFromEnvironment(): void
    {
        if ($this->event_key === null) {
            $this->event_key = getenv('INNGEST_EVENT_KEY') ?: null;
        }

        if ($this->signing_key === null) {
            $this->signing_key = getenv('INNGEST_SIGNING_KEY') ?: null;
        }

        if ($this->signing_key_fallback === null) {
            $this->signing_key_fallback = getenv('INNGEST_SIGNING_KEY_FALLBACK') ?: null;
        }

        if ($this->env === null) {
            $this->env = getenv('INNGEST_ENV') ?: null;
        }

        $inngest_dev = getenv('INNGEST_DEV');
        if ($inngest_dev !== false && $inngest_dev !== '') {
            $this->is_dev = true;
            
            if (filter_var($inngest_dev, FILTER_VALIDATE_URL)) {
                if ($this->api_base_url === null) {
                    $this->api_base_url = $inngest_dev;
                }
                if ($this->event_api_base_url === null) {
                    $this->event_api_base_url = $inngest_dev;
                }
            }
        }

        if ($this->api_base_url === null) {
            $api_url = getenv('INNGEST_API_BASE_URL');
            if ($api_url !== false) {
                $this->api_base_url = $api_url;
            }
        }

        if ($this->event_api_base_url === null) {
            $event_url = getenv('INNGEST_EVENT_API_BASE_URL');
            if ($event_url !== false) {
                $this->event_api_base_url = $event_url;
            }
        }

        if ($this->serve_origin === null) {
            $this->serve_origin = getenv('INNGEST_SERVE_ORIGIN') ?: null;
        }

        if ($this->serve_path === null) {
            $this->serve_path = getenv('INNGEST_SERVE_PATH') ?: null;
        }

        if ($this->log_level === null) {
            $this->log_level = getenv('INNGEST_LOG_LEVEL') ?: null;
        }
    }

    public function getEventKey(): ?string
    {
        return $this->event_key;
    }

    public function getSigningKey(): ?string
    {
        return $this->signing_key;
    }

    public function getSigningKeyFallback(): ?string
    {
        return $this->signing_key_fallback;
    }

    public function getEnv(): ?string
    {
        return $this->env;
    }

    public function getApiBaseUrl(): string
    {
        if ($this->api_base_url !== null) {
            return $this->api_base_url;
        }

        return $this->is_dev ? self::DEFAULT_DEV_SERVER_ORIGIN : self::DEFAULT_API_ORIGIN;
    }

    public function getEventApiBaseUrl(): string
    {
        if ($this->event_api_base_url !== null) {
            return $this->event_api_base_url;
        }

        return $this->is_dev ? self::DEFAULT_DEV_SERVER_ORIGIN : self::DEFAULT_EVENT_ORIGIN;
    }

    public function isDev(): bool
    {
        return $this->is_dev;
    }

    public function getServeOrigin(): ?string
    {
        return $this->serve_origin;
    }

    public function getServePath(): ?string
    {
        return $this->serve_path;
    }

    public function getLogLevel(): ?string
    {
        return $this->log_level;
    }
}
