<?php

/**
 * Batch Events example
 *
 * This example demonstrates:
 * - Batching multiple events into a single function run
 * - Setting maximum batch size and timeout
 * - Partitioning batches by a key (customer_id in this case)
 * - Processing all events in the batch
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\BatchEvents;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;
use DealNews\Inngest\Http\ServeHandler;

// Create the Inngest client
$client = new Inngest('batch-app');

// Define a function that processes batched order events
$process_order_batch_function = new InngestFunction(
    id: 'process-order-batch',
    handler: function ($ctx) {
        $events = $ctx->getEvents();
        $count = count($events);

        error_log("Processing batch of {$count} orders");

        $processed = [];
        foreach ($events as $event) {
            $order_id = $event->getData()['order_id'] ?? 'unknown';
            $customer_id = $event->getData()['customer_id'] ?? 'unknown';
            error_log("  - Order ID: {$order_id}, Customer: {$customer_id}");

            $processed[] = [
                'order_id' => $order_id,
                'customer_id' => $customer_id,
                'status' => 'processed',
            ];
        }

        return [
            'batch_size' => $count,
            'processed_orders' => $processed,
            'processed_at' => date('c'),
        ];
    },
    triggers: [new TriggerEvent('order/created')],
    batch_events: new BatchEvents(
        max_size: 50,
        timeout: '1m',
        // key groups events into separate batches per unique value — events with the same
        // customer_id batch together; events with different customer_ids each get their own run.
        // Omit key to batch all matching events together regardless of their data.
        key: 'event.data.customer_id'
    )
);

// Register the function
$client->registerFunction($process_order_batch_function);

// Create the serve handler
$handler = new ServeHandler($client, '/api/inngest');

// Example: Handle HTTP request
// In a real application, this would be integrated with your framework
if (php_sapi_name() === 'cli') {
    echo "=== Inngest Batch Events Example ===\n\n";

    // Simulate sending multiple events
    echo "Sending test events...\n";
    try {
        for ($i = 1; $i <= 5; $i++) {
            $result = $client->send(new Event(
                name: 'order/created',
                data: [
                    'order_id' => "ORD-{$i}",
                    'customer_id' => 'CUST-001',
                    'amount' => 100.00 + ($i * 10),
                ]
            ));
            echo "  Event {$i} sent\n";
        }
        echo "All events sent successfully!\n";
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
    echo "4. Send multiple order/created events via curl or the Inngest UI\n";
    echo "5. Watch the function batch and process up to 50 events within 10 seconds\n";
    echo "6. Events are grouped by customer_id, so each customer gets their own batch\n";
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
