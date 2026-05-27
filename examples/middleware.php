<?php

/**
 * Middleware example
 *
 * This example demonstrates:
 * - Creating custom middleware by extending AbstractMiddleware
 * - Hooking into the function execution lifecycle
 * - Global middleware (registered on client)
 * - Function-scoped middleware
 * - Logging, monitoring, and transforming data through middleware
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;
use DealNews\Inngest\Http\ServeHandler;
use DealNews\Inngest\Middleware\AbstractMiddleware;
use DealNews\Inngest\Function\FunctionContext;

/**
 * Custom middleware that logs execution details and measures performance
 */
class PerformanceLoggingMiddleware extends AbstractMiddleware
{
    private ?float $start_time = null;

    /**
     * Log incoming run details before execution
     */
    public function transformInput(FunctionContext $ctx, array &$steps): void
    {
        $event = $ctx->getEvent();
        $run_id = $ctx->getRunId();
        echo "[MW] transformInput: Run {$run_id} triggered by {$event->getName()}\n";
    }

    /**
     * Start performance timer before handler execution
     */
    public function beforeExecution(FunctionContext $ctx): void
    {
        $this->start_time = microtime(true);
        $run_id = $ctx->getRunId();
        echo "[MW] beforeExecution: Starting timer for run {$run_id}\n";
    }

    /**
     * Stop timer and log duration after handler execution
     */
    public function afterExecution(FunctionContext $ctx): void
    {
        if ($this->start_time !== null) {
            $duration = (microtime(true) - $this->start_time) * 1000;
            $run_id = $ctx->getRunId();
            echo "[MW] afterExecution: Run {$run_id} took {$duration}ms\n";
        }
    }

    /**
     * Log the final result or error
     */
    public function transformOutput(FunctionContext $ctx, mixed &$result, ?\Throwable &$error, ?array &$step_data): void
    {
        $run_id = $ctx->getRunId();
        if ($error !== null) {
            echo "[MW] transformOutput: Run {$run_id} had error: {$error->getMessage()}\n";
        } else {
            echo "[MW] transformOutput: Run {$run_id} succeeded with result\n";
        }
    }

    /**
     * Add metadata to outgoing events
     */
    public function beforeSendEvents(array &$events): void
    {
        echo "[MW] beforeSendEvents: Adding source metadata to " . count($events) . " event(s)\n";
        foreach ($events as &$event) {
            if (!isset($event['data'])) {
                $event['data'] = [];
            }
            $event['data']['_source'] = 'middleware-example';
            $event['data']['_sent_at'] = date('c');
        }
    }

    /**
     * Log event IDs after sending
     */
    public function afterSendEvents(array $event_ids, ?\Throwable $error = null): void
    {
        if ($error !== null) {
            echo "[MW] afterSendEvents: Error sending events: {$error->getMessage()}\n";
        } else {
            echo "[MW] afterSendEvents: Successfully sent " . count($event_ids) . " event(s)\n";
        }
    }
}

/**
 * Function-scoped middleware for detailed event logging
 */
class EventDetailLoggingMiddleware extends AbstractMiddleware
{
    public function beforeExecution(FunctionContext $ctx): void
    {
        $event = $ctx->getEvent();
        $event_data = json_encode($event->getData(), JSON_PRETTY_PRINT);
        echo "[DETAIL] Event data for {$event->getName()}:\n{$event_data}\n";
    }
}

// Create the Inngest client
$client = new Inngest('middleware-app');

// Register global middleware on the client
$client->addMiddleware(new PerformanceLoggingMiddleware());

// Define a function with per-function middleware
$user_signup_function = new InngestFunction(
    id: 'process-user-signup',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();
        $user_email = $event->getData()['email'] ?? 'unknown';

        echo "Processing user signup for: {$user_email}\n";

        // Step 1: Create user account
        $user = $step->run('create-account', function () use ($user_email) {
            echo "  Creating account...\n";
            return [
                'user_id' => uniqid('USER-'),
                'email' => $user_email,
                'created_at' => date('c'),
            ];
        });

        // Step 2: Send welcome email
        $step->run('send-welcome-email', function () use ($user) {
            echo "  Sending welcome email to {$user['email']}\n";
            return ['email_sent' => true];
        });

        // Step 3: Send events to trigger downstream workflows
        $step->sendEvent('trigger-onboarding', [
            new Event(
                name: 'user/created',
                data: [
                    'user_id' => $user['user_id'],
                    'email' => $user['email'],
                ]
            ),
        ]);

        return [
            'status' => 'signup_complete',
            'user_id' => $user['user_id'],
        ];
    },
    triggers: [new TriggerEvent('user/signup')]
);

// Add function-scoped middleware
$user_signup_function->addMiddleware(new EventDetailLoggingMiddleware());

// Register the function
$client->registerFunction($user_signup_function);

// Create the serve handler
$handler = new ServeHandler($client, '/api/inngest');

// Example: Handle HTTP request
// In a real application, this would be integrated with your framework
if (php_sapi_name() === 'cli') {
    echo "=== Inngest Middleware Example ===\n\n";

    // Simulate sending an event
    echo "Sending user/signup event...\n";
    try {
        $result = $client->send(new Event(
            name: 'user/signup',
            data: [
                'email' => 'newuser@example.com',
                'name' => 'Jane Doe',
                'signup_source' => 'website',
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
    echo "4. Send a user/signup event\n\n";
    echo "Middleware execution order (from this example):\n";
    echo "1. transformInput() - Log run ID and event name (global middleware)\n";
    echo "2. beforeExecution() - Start timer and log event details\n";
    echo "3. Handler execution (function logic runs)\n";
    echo "4. afterExecution() - Stop timer and log duration\n";
    echo "5. transformOutput() - Log result or error\n";
    echo "6. beforeSendEvents() - Add _source and _sent_at to outgoing events\n";
    echo "7. afterSendEvents() - Log event IDs after sending\n\n";
    echo "Middleware types:\n";
    echo "- Global middleware (on client): Applies to all functions\n";
    echo "- Function-scoped middleware: Applies only to specific function\n";
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
