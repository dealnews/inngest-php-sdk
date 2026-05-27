<?php

/**
 * Cancellation example
 *
 * This example demonstrates:
 * - Defining a function that can be cancelled
 * - Using a CEL expression to match cancellation conditions
 * - Long-running operations that can be interrupted
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\Cancel;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;
use DealNews\Inngest\Http\ServeHandler;

// Create the Inngest client
$client = new Inngest('cancel-app');

// Define a long-running order processing function that can be cancelled
$process_order_function = new InngestFunction(
    id: 'process-order',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();
        $order_id = $event->getData()['order_id'];

        echo "Starting order processing for: {$order_id}\n";

        // Step 1: Validate order
        $validation = $step->run('validate-order', function () use ($order_id) {
            echo "  Validating order: {$order_id}\n";
            return ['valid' => true];
        });

        if (!$validation['valid']) {
            return ['status' => 'validation_failed', 'order_id' => $order_id];
        }

        // Step 2: Long wait for payment processing
        echo "  Waiting for payment processing (can be cancelled during this step)...\n";
        $step->sleep('wait-for-payment', '30m');

        // Step 3: Process payment
        $payment = $step->run('process-payment', function () use ($order_id) {
            echo "  Processing payment for: {$order_id}\n";
            return ['payment_id' => uniqid('PAY-')];
        });

        // Step 4: Fulfill order
        $step->run('fulfill-order', function () use ($order_id) {
            echo "  Fulfilling order: {$order_id}\n";
            return ['fulfilled' => true];
        });

        return [
            'status' => 'completed',
            'order_id' => $order_id,
            'payment_id' => $payment['payment_id'],
        ];
    },
    triggers: [new TriggerEvent('order/process')],
    cancel: [
        new Cancel(
            event: 'order/cancelled',
            if: 'event.data.order_id == async.data.order_id',
            timeout: '35m'
        )
    ]
);

// Register the function
$client->registerFunction($process_order_function);

// Create the serve handler
$handler = new ServeHandler($client, '/api/inngest');

// Example: Handle HTTP request
// In a real application, this would be integrated with your framework
if (php_sapi_name() === 'cli') {
    echo "=== Inngest Cancellation Example ===\n\n";

    // Simulate sending events
    echo "Sending order/process event...\n";
    try {
        $result = $client->send(new Event(
            name: 'order/process',
            data: [
                'order_id' => 'ORD-12345',
                'customer_id' => 'CUST-001',
                'amount' => 299.99,
            ]
        ));
        echo "Order process event sent!\n";
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
    echo "4. Send an order/process event\n";
    echo "5. The function will start executing and wait for payment (30 minute sleep)\n";
    echo "6. While waiting, send an order/cancelled event with the same order_id\n";
    echo "7. The function run will be cancelled and won't continue to fulfillment\n";
    echo "8. The cancellation is only valid for 35 minutes from when the run started\n";
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
