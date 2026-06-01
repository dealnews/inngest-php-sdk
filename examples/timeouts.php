<?php

/**
 * Timeouts example
 *
 * This example demonstrates:
 * - Setting start timeout (how long until function must start executing)
 * - Setting finish timeout (how long function has to complete once started)
 * - Difference between start and finish timeouts
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\Timeouts;
use DealNews\Inngest\Function\TriggerEvent;
use DealNews\Inngest\Http\ServeHandler;

// Create the Inngest client
$client = new Inngest('timeouts-app');

// Define a function with custom timeouts
$long_running_function = new InngestFunction(
    id: 'long-running-task',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();

        error_log("Starting long-running task");

        // Step 1: Initial work
        $step->run('initial-work', function () {
            error_log("Doing initial work...");
            return ['work_id' => uniqid('WORK-')];
        });

        // Step 2: Long wait
        error_log("Sleeping for 36 minutes...");
        $step->sleep('long-sleep', '36m');

        // Step 3: Final work just before timeout
        $result = $step->run('final-work', function () {
            error_log("Completing final work before timeout");
            return ['completed' => true];
        });

        return [
            'status' => 'success',
            'completed_at' => date('c'),
            'result' => $result,
        ];
    },
    triggers: [new TriggerEvent('task/long-running')],
    timeouts: new Timeouts(
        start: '30m',      // Must start within 30 minutes of being scheduled
        finish: '35m'       // Must complete within 2 hours once started
    )
);

// Register the function
$client->registerFunction($long_running_function);

// Create the serve handler
$handler = new ServeHandler($client, '/api/inngest');

// Example: Handle HTTP request
// In a real application, this would be integrated with your framework
if (php_sapi_name() === 'cli') {
    echo "=== Inngest Timeouts Example ===\n\n";

    // Simulate sending an event
    echo "Sending task/long-running event...\n";
    try {
        $result = $client->send(new Event(
            name: 'task/long-running',
            data: [
                'task_type' => 'report-generation',
                'priority' => 'medium',
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
    echo "4. Send a task/long-running event\n\n";
    echo "Timeout behavior:\n";
    echo "- Start Timeout (30m): The function run must be picked up by a worker and start\n";
    echo "  executing within 30 minutes. If no worker is available, the run times out.\n";
    echo "- Finish Timeout (2h): Once the function starts executing, it has 2 hours to\n";
    echo "  complete all steps. If it takes longer, the run times out.\n";
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
