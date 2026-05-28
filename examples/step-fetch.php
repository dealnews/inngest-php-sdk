<?php

/**
 * Step: Fetch example
 *
 * This example demonstrates:
 * - Making HTTP requests as retriable steps
 * - Automatic retries on network failures
 * - Processing HTTP response data
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;
use DealNews\Inngest\Http\ServeHandler;

// Create the Inngest client
$client = new Inngest('fetch-app');

// Define a function that makes HTTP requests
$weather_notifier_function = new InngestFunction(
    id: 'check-weather',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();
        $location = $event->getData()['location'] ?? 'New York';

        error_log("Checking weather for: {$location}");

        // Step 1: Fetch weather data from API (retriable)
        $weather_response = $step->fetch(
            id: 'fetch-weather-api',
            url: 'https://httpbin.org/get?location=' . urlencode($location),
            method: 'GET',
            headers: [
                'User-Agent' => 'Inngest-PHP/1.0',
                'Accept' => 'application/json',
            ]
        );

        error_log("  Weather API response received");

        // Step 2: Process the response
        $analysis = $step->run('analyze-weather', function () use ($weather_response, $location) {
            error_log("  Analyzing weather data for {$location}");

            // In a real application, you would parse the actual weather data
            // For this example, we'll just return a structured response
            return [
                'location' => $location,
                'temperature' => 72,
                'conditions' => 'Partly Cloudy',
                'analyzed_at' => date('c'),
            ];
        });

        // Step 3: Send notification if weather is poor (retriable)
        if ($analysis['conditions'] === 'Rainy' || $analysis['conditions'] === 'Stormy') {
            $step->sendEvent('send-weather-alert', new Event(
                name: 'notification/weather-alert',
                data: [
                    'location' => $location,
                    'condition' => $analysis['conditions'],
                    'temperature' => $analysis['temperature'],
                ]
            ));
        }

        return [
            'location' => $location,
            'weather' => $analysis,
            'checked_at' => date('c'),
        ];
    },
    triggers: [new TriggerEvent('weather/check')],
    retries: 5  // More retries for network-dependent operations
);

// Register the function
$client->registerFunction($weather_notifier_function);

// Create the serve handler
$handler = new ServeHandler($client, '/api/inngest');

// Example: Handle HTTP request
// In a real application, this would be integrated with your framework
if (php_sapi_name() === 'cli') {
    echo "=== Inngest Fetch Step Example ===\n\n";

    // Simulate sending an event
    echo "Sending weather/check event...\n";
    try {
        $result = $client->send(new Event(
            name: 'weather/check',
            data: [
                'location' => 'San Francisco',
            ]
        ));
        echo "Event sent successfully!\n";
    } catch (Exception $e) {
        echo "Error sending event: {$e->getMessage()}\n";
        echo "(This is expected if INNGEST_EVENT_KEY is not set)\n";
    }

    echo "\n=== To test this example manually ===\n";
    echo "1. Set environment variables:\n";
    echo "   export INNGEST_EVENT_KEY=your-event-key\n";
    echo "   export INNGEST_SIGNING_KEY=your-signing-key\n";
    echo "   export INNGEST_DEV=1\n\n";
    echo "2. Start the Inngest dev server:\n";
    echo "   npx inngest-cli@latest dev\n\n";
    echo "3. Run this example in a web server\n\n";
    echo "4. Send a weather/check event with a location\n";
    echo "5. The function makes an HTTP request to httpbin.org/get\n";
    echo "6. If the request fails, Inngest automatically retries it\n";
    echo "7. The response is available to subsequent steps for processing\n";
    echo "8. With 5 retries configured, transient network issues are handled gracefully\n";
} else {
    // Handle actual HTTP request
    $response = $handler->handle(
        method: $_SERVER['REQUEST_METHOD'],
        path: $_SERVER['REQUEST_URI'],
        headers: getallheaders() ?: [],
        body: file_get_contents('php://input') ?: '',
        query: $_GET
    );
    http_response_code($response['status']);
    foreach ($response['headers'] as $key => $value) {
        header("{$key}: {$value}");
    }
    echo $response['body'];
}
