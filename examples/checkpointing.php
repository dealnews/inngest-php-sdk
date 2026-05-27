<?php

/**
 * Checkpointing example
 *
 * This example demonstrates:
 * - Enabling checkpointing on a function
 * - Combining synchronous and asynchronous steps
 * - Reducing round-trips with checkpointing
 * - Using waitForEvent steps to pause execution
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;
use DealNews\Inngest\Http\ServeHandler;

// Create the Inngest client
$client = new Inngest('checkpointing-app');

// Define a function with checkpointing enabled
$payment_workflow_function = new InngestFunction(
    id: 'payment-workflow',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();
        $order_id = $event->getData()['order_id'];

        echo "Starting payment workflow for order: {$order_id}\n";

        // Step 1: Synchronous - Validate order
        $validation = $step->run('validate-order', function () use ($order_id) {
            echo "  Validating order: {$order_id}\n";
            return ['valid' => true, 'total_amount' => 150.00];
        });

        if (!$validation['valid']) {
            return ['status' => 'validation_failed', 'order_id' => $order_id];
        }

        // Step 2: Asynchronous - Wait for payment to be initiated
        echo "  Waiting for payment initiation event...\n";
        $payment_init = $step->waitForEvent(
            id: 'wait-for-payment-init',
            event: 'payment/initiated',
            timeout: '30m',
            if: 'event.data.order_id == async.data.order_id'
        );

        if ($payment_init === null) {
            echo "  No payment initiation event received (first run)\n";
            return ['status' => 'awaiting_payment', 'order_id' => $order_id];
        }

        echo "  Payment initiated, processing...\n";

        // Step 3: Synchronous - Process payment
        $payment_result = $step->run('process-payment', function () use ($validation, $payment_init) {
            echo "  Processing payment with provider...\n";
            return [
                'payment_id' => uniqid('PAY-'),
                'amount' => $validation['total_amount'],
                'status' => 'processing',
            ];
        });

        // Step 4: Asynchronous - Wait for payment confirmation
        echo "  Waiting for payment confirmation...\n";
        $payment_confirm = $step->waitForEvent(
            id: 'wait-for-payment-confirm',
            event: 'payment/confirmed',
            timeout: '5m',
            if: 'event.data.payment_id == async.data.payment_id'
        );

        if ($payment_confirm === null) {
            echo "  No payment confirmation yet (will retry when event arrives)\n";
            return ['status' => 'awaiting_confirmation', 'order_id' => $order_id];
        }

        echo "  Payment confirmed!\n";

        // Step 5: Synchronous - Fulfill order
        $fulfillment = $step->run('fulfill-order', function () use ($order_id, $payment_result) {
            echo "  Fulfilling order: {$order_id}\n";
            return [
                'fulfillment_id' => uniqid('FUL-'),
                'status' => 'shipped',
            ];
        });

        return [
            'status' => 'completed',
            'order_id' => $order_id,
            'payment_id' => $payment_result['payment_id'],
            'fulfillment_id' => $fulfillment['fulfillment_id'],
            'completed_at' => date('c'),
        ];
    },
    triggers: [new TriggerEvent('order/process')],
    checkpointing: true  // Enable checkpointing to reduce round-trips
);

// Register the function
$client->registerFunction($payment_workflow_function);

// Create the serve handler
$handler = new ServeHandler($client, '/api/inngest');

// Example: Handle HTTP request
// In a real application, this would be integrated with your framework
if (php_sapi_name() === 'cli') {
    echo "=== Inngest Checkpointing Example ===\n\n";

    // Simulate sending an event
    echo "Sending order/process event...\n";
    try {
        $result = $client->send(new Event(
            name: 'order/process',
            data: [
                'order_id' => 'ORD-CHECKPOINT-001',
                'customer_id' => 'CUST-123',
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
    echo "4. Send an order/process event\n\n";
    echo "Execution flow with checkpointing:\n\n";
    echo "First run:\n";
    echo "  1. validate-order (sync step - runs)\n";
    echo "  2. wait-for-payment-init (async step - returns null, function exits)\n";
    echo "  3. Function returns 'awaiting_payment'\n\n";
    echo "When payment/initiated event arrives:\n";
    echo "  1. validate-order (skipped - cached from checkpoint)\n";
    echo "  2. wait-for-payment-init (returns event data - continues)\n";
    echo "  3. process-payment (sync step - runs)\n";
    echo "  4. wait-for-payment-confirm (async step - returns null, exits)\n";
    echo "  5. Function returns 'awaiting_confirmation'\n\n";
    echo "When payment/confirmed event arrives:\n";
    echo "  1. Previous steps skipped (cached)\n";
    echo "  2. wait-for-payment-confirm (returns event data - continues)\n";
    echo "  3. fulfill-order (sync step - runs)\n";
    echo "  4. Function returns 'completed'\n\n";
    echo "Benefits of checkpointing:\n";
    echo "- Sync steps run only once, then cached\n";
    echo "- No re-execution of expensive operations\n";
    echo "- Faster resumption when async events arrive\n";
    echo "- Better resource utilization\n";
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
