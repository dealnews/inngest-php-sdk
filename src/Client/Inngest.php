<?php

declare(strict_types=1);

namespace DealNews\Inngest\Client;

use DealNews\Inngest\Config\Config;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Http\Headers;

/**
 * Main Inngest client
 */
class Inngest
{
    /** @var array<string, InngestFunction> */
    protected array $functions = [];

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
        $payload = array_map(fn($e) => $e->toArray(), $events_array);

        $url = $this->config->getEventApiBaseUrl() . '/e/' . $event_key;
        
        $headers = [
            'Content-Type' => 'application/json',
            Headers::SDK => Headers::SDK_NAME . ':v' . Headers::SDK_VERSION,
        ];

        if ($this->config->getEnv() !== null) {
            $headers[Headers::ENV] = $this->config->getEnv();
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
        ]);

        $response = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status_code !== 200) {
            throw new \Exception("Failed to send events: HTTP {$status_code}");
        }

        return json_decode($response, true) ?? [];
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
