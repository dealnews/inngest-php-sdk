<?php

declare(strict_types=1);

namespace DealNews\Inngest\Client;

use DealNews\Inngest\Config\Config;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Http\Headers;
use DealNews\Inngest\Middleware\AbstractMiddleware;

/**
 * Main Inngest client
 */
class Inngest
{
    /** @var array<string, InngestFunction> */
    protected array $functions = [];

    /** @var array<AbstractMiddleware> */
    protected array $middleware = [];

    /**
     * @param string $app_id Application identifier
     * @param Config|null $config Configuration (uses environment if null)
     */
    public function __construct(
        protected string $app_id,
        protected ?Config $config = null
    ) {
        if ($this->config === null) {
            $this->config = new Config();
        }
    }

    public function getAppId(): string
    {
        return $this->app_id;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Register a function with the client
     */
    public function registerFunction(InngestFunction $function): void
    {
        $this->functions[$function->getId()] = $function;
    }

    /**
     * Get a registered function by ID
     */
    public function getFunction(string $id): ?InngestFunction
    {
        return $this->functions[$id] ?? null;
    }

    /**
     * @return array<string, InngestFunction>
     */
    public function getFunctions(): array
    {
        return $this->functions;
    }

    /**
     * Add middleware to the client
     */
    public function addMiddleware(AbstractMiddleware $mw): void
    {
        $this->middleware[] = $mw;
    }

    /**
     * Get registered middleware
     *
     * @return array<AbstractMiddleware>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Send one or more events to Inngest
     *
     * @param Event|array<Event> $events
     * @return array<string, mixed>
     * @throws \Exception
     */
    public function send(Event|array $events): array
    {
        $event_key = $this->config->getEventKey();
        if ($event_key === null) {
            throw new \Exception('No event key configured');
        }

        $events_array = is_array($events) ? $events : [$events];
        $events_payload = array_map(fn($e) => $e->toArray(), $events_array);

        // Allow middleware to mutate the events payload
        foreach ($this->middleware as $mw) {
            $mw->beforeSendEvents($events_payload);
        }

        $url = $this->config->getEventApiBaseUrl() . '/e/' . $event_key;

        $headers = [
            'Content-Type' => 'application/json',
            Headers::SDK => Headers::SDK_NAME . ':v' . Headers::SDK_VERSION,
        ];

        if ($this->config->getEnv() !== null) {
            $headers[Headers::ENV] = $this->config->getEnv();
        }

        $send_error = null;
        $result = [];

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($events_payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            ]);

            $response = curl_exec($ch);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status_code !== 200) {
                throw new \Exception("Failed to send events: HTTP {$status_code}");
            }

            $result = json_decode($response, true) ?? [];
        } catch (\Throwable $e) {
            $send_error = $e;
        }

        // Notify middleware of send result
        $event_ids = isset($result['ids']) ? $result['ids'] : [];
        foreach ($this->middleware as $mw) {
            $mw->afterSendEvents($event_ids, $send_error);
        }

        if ($send_error !== null) {
            throw $send_error;
        }

        return $result;
    }

    /**
     * Get SDK identifier string
     */
    public function getSdkIdentifier(): string
    {
        return Headers::SDK_NAME . ':v' . Headers::SDK_VERSION;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string>
     */
    protected function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }
        return $formatted;
    }
}
