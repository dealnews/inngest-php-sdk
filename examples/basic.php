<?php

/**
 * Basic example of using the Inngest PHP SDK
 *
 * This example demonstrates:
 * - Creating an Inngest client
 * - Defining functions with event triggers
 * - Using steps for retriable operations
 * - Sending events
 * - Serving functions via HTTP
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;
use DealNews\Inngest\Function\TriggerCron;
use DealNews\Inngest\Http\ServeHandler;

// Create the Inngest client
$client = new Inngest('dealnews');

// Define a simple function
$hello_function = new InngestFunction(
    id: 'hello-world',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $name = $event->getData()['name'] ?? 'World';

        echo "Processing event: {$event->getName()}\n";
        echo "Hello, {$name}!\n";

        return [
            'message' => "Hello, {$name}!",
            'processed_at' => date('c'),
        ];
    },
    triggers: [
        new TriggerEvent('demo/hello')
    ],
    name: 'Hello World Function'
);

// Define a function with steps
$workflow_function = new InngestFunction(
    id: 'user-onboarding',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();
        $user_id = $event->getData()['user_id'];

        // Step 1: Create user account
        $user = $step->run('create-account', function () use ($user_id) {
            echo "Creating account for user: {$user_id}\n";
            return [
                'id' => $user_id,
                'created_at' => time(),
            ];
        });

        // Step 2: Send welcome email
        $step->run('send-welcome-email', function () use ($user) {
            echo "Sending welcome email to user: {$user['id']}\n";
            // In a real app, this would send an actual email
            return ['email_sent' => true];
        });

        // Step 3: Wait before sending follow-up
        $step->sleep('wait-for-follow-up', '24h');

        // Step 4: Send follow-up email
        $step->run('send-follow-up', function () use ($user) {
            echo "Sending follow-up email to user: {$user['id']}\n";
            return ['follow_up_sent' => true];
        });

        return [
            'status' => 'onboarding_complete',
            'user_id' => $user_id,
        ];
    },
    triggers: [
        new TriggerEvent('user/signup')
    ],
    name: 'User Onboarding Workflow'
);

// Define a cron-triggered function
$daily_report_function = new InngestFunction(
    id: 'daily-report',
    handler: function ($ctx) {
        $step = $ctx->getStep();

        // Gather data
        $data = $step->run('gather-data', function () {
            echo "Gathering daily report data\n";
            return [
                'users' => 150,
                'orders' => 42,
                'revenue' => 12500.00,
            ];
        });

        // Generate report
        $report = $step->run('generate-report', function () use ($data) {
            echo "Generating daily report\n";
            return [
                'report_id' => uniqid('report_'),
                'data' => $data,
                'generated_at' => date('c'),
            ];
        });

        // Send report
        $step->run('send-report', function () use ($report) {
            echo "Sending daily report: {$report['report_id']}\n";
            return ['sent' => true];
        });

        return $report;
    },
    triggers: [
        new TriggerCron('0 9 * * *') // Every day at 9 AM
    ],
    name: 'Daily Report Generator'
);

// Register all functions
$client->registerFunction($hello_function);
$client->registerFunction($workflow_function);
$client->registerFunction($daily_report_function);

// Create the serve handler
$handler = new ServeHandler($client, '/api/inngest');

// Example: Handle HTTP request
// In a real application, this would be integrated with your framework
if (php_sapi_name() === 'cli') {
    echo "=== Inngest PHP SDK Example ===\n\n";

    // Simulate sending an event
    echo "Sending test event...\n";
    try {
        $result = $client->send(new Event(
            name: 'demo/hello',
            data: ['name' => 'PHP Developer']
        ));
        echo "Event sent successfully!\n";
        print_r($result);
    } catch (Exception $e) {
        echo "Error sending event: {$e->getMessage()}\n";
        echo "(This is expected if INNGEST_EVENT_KEY is not set)\n";
    }

    echo "\n=== Registered Functions ===\n";
    foreach ($client->getFunctions() as $fn) {
        echo "- {$fn->getId()} ({$fn->getName()})\n";
    }

    echo "\n=== To use this example ===\n";
    echo "1. Set up environment variables:\n";
    echo "   export INNGEST_EVENT_KEY=your-event-key\n";
    echo "   export INNGEST_SIGNING_KEY=your-signing-key\n";
    echo "   export INNGEST_DEV=1\n\n";
    echo "2. Start the Inngest dev server:\n";
    echo "   npx inngest-cli@latest dev\n\n";
    echo "3. Run this example with a web server\n";
    echo "4. Access http://localhost:8000/api/inngest to sync functions\n";
} else {
    // Handle actual HTTP request
    $response = $handler->handle(
        method: $_SERVER['REQUEST_METHOD'],
        path: $_SERVER['REQUEST_URI'],
        headers: getallheaders() ?: [],
        body: file_get_contents('php://input') ?: '',
        query: $_GET
    );
error_log(json_encode($response, JSON_PRETTY_PRINT));
    http_response_code($response['status']);
    foreach ($response['headers'] as $key => $value) {
        header("{$key}: {$value}");
    }
    echo $response['body'];
}
