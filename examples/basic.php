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

        error_log("Processing event: {$event->getName()}");
        error_log("Hello, {$name}!");

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
    handler: function (\DealNews\Inngest\Function\FunctionContext $ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();
        $user_id = $event->getData()['user_id'];

        // Step 1: Create user account
        $user = $step->run('create-account', function () use ($user_id) {
            error_log("Creating account for user: {$user_id}");
            return [
                'id' => $user_id,
                'created_at' => time(),
            ];
        });

        // Step 2: Send welcome email
        $step->run('send-welcome-email', function () use ($user) {
            error_log("Sending welcome email to user: {$user['id']}");
            // In a real app, this would send an actual email
            return ['email_sent' => true];
        });

        // Step 3: Wait before sending follow-up
        $step->sleep('wait-for-follow-up', '1m');

        // Step 4: Send follow-up email
        $step->run('send-follow-up', function () use ($user) {
            error_log("Sending follow-up email to user: {$user['id']}");
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

// Demonstrates that inngest will pick up where it left off if a step fails
$first_attempt_fail_step = new InngestFunction(
    id: 'first-attempt-fail-step',
    handler: function (\DealNews\Inngest\Function\FunctionContext $ctx) {
        $step = $ctx->getStep();
        $attempt = $ctx->getAttempt();

        $step->run('initial-step', function () {
            error_log("Running initial-step");
            return [
                'ran' => true,
            ];
        });

        $failed_return = $step->run('first-attempt-fail', function () use ($attempt) {
            error_log("Running first-attempt-fail step");
            if ($attempt <= 0) {
                throw new \RuntimeException('First attempt fail step failed');
            }
            return [
                'attempt' => $attempt,
            ];
        });

        if (empty($failed_return) || !is_array($failed_return) || !array_key_exists('attempt', $failed_return) || $failed_return['attempt'] <= 0) {
            return [
                'status' => 'failed',
                'failed_return' => $failed_return ?? null,
            ];
        }

        $step->run('standard-step-1', function () use ($failed_return) {
            $attempt_value = 'Unknown';
            if (!empty($failed_return) && is_array($failed_return) && array_key_exists('attempt', $failed_return)) {
                $attempt_value = $failed_return['attempt'];
            }
            error_log("Running standard-step-1: {$attempt_value}");
            return [
                'ran' => true,
                'attempt_value' => $attempt_value,
            ];
        });

        $step->run('standard-step-2', function () use ($failed_return) {
            $attempt_value = 'Unknown';
            if (!empty($failed_return) && is_array($failed_return) && array_key_exists('attempt', $failed_return)) {
                $attempt_value = $failed_return['attempt'];
            }
            error_log("Running standard-step-2: {$attempt_value}");
            return [
                'ran' => true,
                'attempt_value' => $attempt_value,
            ];
        });

        $step->run('standard-step-3', function () use ($failed_return) {
            $attempt_value = 'Unknown';
            if (!empty($failed_return) && is_array($failed_return) && array_key_exists('attempt', $failed_return)) {
                $attempt_value = $failed_return['attempt'];
            }
            error_log("Running standard-step-3: {$attempt_value}");
            return [
                'ran' => true,
                'attempt_value' => $attempt_value,
            ];
        });

        $step->run('standard-step-4', function () use ($failed_return) {
            $attempt_value = 'Unknown';
            if (!empty($failed_return) && is_array($failed_return) && array_key_exists('attempt', $failed_return)) {
                $attempt_value = $failed_return['attempt'];
            }
            error_log("Running standard-step-4: {$attempt_value}");
            return [
                'ran' => true,
                'attempt_value' => $attempt_value,
            ];
        });

        $step->run('standard-step-5', function () use ($failed_return) {
            $attempt_value = 'Unknown';
            if (!empty($failed_return) && is_array($failed_return) && array_key_exists('attempt', $failed_return)) {
                $attempt_value = $failed_return['attempt'];
            }
            error_log("Running standard-step-5: {$attempt_value}");
            return [
                'ran' => true,
                'attempt_value' => $attempt_value,
            ];
        });

        return [
            'status' => 'complete',
            'failed_return' => $failed_return,
        ];
    },
    triggers: [
        new TriggerEvent('basic/first-attempt')
    ],
    name: 'First Attempt Fail Step'
);

// Define a cron-triggered function
$daily_report_function = new InngestFunction(
    id: 'daily-report',
    handler: function ($ctx) {
        $step = $ctx->getStep();

        // Gather data
        $data = $step->run('gather-data', function () {
            error_log("Gathering daily report data");
            return [
                'users' => 150,
                'orders' => 42,
                'revenue' => 12500.00,
            ];
        });

        // Generate report
        $report = $step->run('generate-report', function () use ($data) {
            error_log("Generating daily report");
            return [
                'report_id' => uniqid('report_'),
                'data' => $data,
                'generated_at' => date('c'),
            ];
        });

        // Send report
        $step->run('send-report', function () use ($report) {
            error_log("Sending daily report: {$report['report_id']}");
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
$client->registerFunction($first_attempt_fail_step);

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
