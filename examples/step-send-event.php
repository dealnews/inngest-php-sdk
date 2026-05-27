<?php

/**
 * Step: Send Event example
 *
 * This example demonstrates:
 * - Sending events as a retriable step
 * - Triggering downstream workflows
 * - Handling event sending failures with automatic retries
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;
use DealNews\Inngest\Http\ServeHandler;

// Create the Inngest client
$client = new Inngest('send-event-app');

// Define a function that sends events to trigger downstream workflows
$payment_processor_function = new InngestFunction(
    id: 'process-payment',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();
        $order_id = $event->getData()['order_id'];

        echo "Processing payment for order: {$order_id}\n";

        // Step 1: Charge the customer
        $charge = $step->run('charge-customer', function () use ($order_id) {
            echo "  Charging customer for order: {$order_id}\n";
            return [
                'charge_id' => uniqid('CHG-'),
                'amount' => 99.99,
                'status' => 'succeeded',
            ];
        });

        if ($charge['status'] !== 'succeeded') {
            return ['status' => 'payment_failed', 'order_id' => $order_id];
        }

        // Step 2: Send notification event (retriable)
        $step->sendEvent('send-notification', new Event(
            name: 'notification/send',
            data: [
                'order_id' => $order_id,
                'type' => 'payment_confirmed',
                'customer_email' => $event->getData()['customer_email'] ?? 'customer@example.com',
                'charge_id' => $charge['charge_id'],
                'amount' => $charge['amount'],
            ]
        ));

        // Step 3: Send fulfillment event (retriable)
        $step->sendEvent('send-fulfillment', [
            new Event(
                name: 'order/fulfill',
                data: [
                    'order_id' => $order_id,
                    'charge_id' => $charge['charge_id'],
                ]
            ),
        ]);

        return [
            'status' => 'payment_processed',
            'order_id' => $order_id,
            'charge_id' => $charge['charge_id'],
        ];
    },
    triggers: [new TriggerEvent('order/payment-needed')],
    retries: 3
);

// Register the function
$client->registerFunction($payment_processor_function);

// Create the serve handler
$handler = new ServeHandler($client, '/api/inngest');

// Example: Handle HTTP request
// In a real application, this would be integrated with your framework
if (php_sapi_name() === 'cli') {
    echo "=== Inngest Send Event Step Example ===\n\n";

    // Simulate sending an event
    echo "Sending order/payment-needed event...\n";
    try {
        $result = $client->send(new Event(
            name: 'order/payment-needed',
            data: [
                'order_id' => 'ORD-98765',
                'customer_email' => 'john@example.com',
                'amount' => 99.99,
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
    echo "4. Send an order/payment-needed event\n";
    echo "5. The function charges the customer in the first step\n";
    echo "6. If successful, it sends notification/send and order/fulfill events\n";
    echo "7. These steps are retriable - if sending fails, they'll be retried\n";
    echo "8. Downstream functions can listen for notification/send and order/fulfill\n";
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
