<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\Debounce;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;

/**
 * Debounce Example - Prevents wasted work from rapid event triggers
 *
 * This example demonstrates how debounce delays function execution until
 * events stop arriving for a specified period. Use cases include:
 * - User input that changes multiple times quickly
 * - Noisy webhook events that should be processed once they settle
 * - Ensuring functions use the latest event in a series of updates
 */

$client = new Inngest('debounce-example');

/*
 * Example 1: Basic debounce
 * Wait 30 seconds after the last event before running
 */
$basic_function = new InngestFunction(
    id: 'process-user-input',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $data = $event->getData();
        
        echo "Processing final user input: {$data['text']}\n";
        echo "User stopped typing!\n";
        
        return ['status' => 'processed', 'text' => $data['text']];
    },
    triggers: [new TriggerEvent('user/input')],
    debounce: new Debounce(period: '30s')
);

/*
 * Example 2: Debounce with key expression
 * Separate debounce window per user_id
 */
$per_user_function = new InngestFunction(
    id: 'sync-user-data',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $data = $event->getData();
        
        echo "Syncing data for user: {$data['user_id']}\n";
        echo "No more updates received, syncing now...\n";
        
        return ['status' => 'synced', 'user_id' => $data['user_id']];
    },
    triggers: [new TriggerEvent('user/updated')],
    debounce: new Debounce(
        period: '5m',
        key: 'event.data.user_id'
    )
);

/*
 * Example 3: Debounce with timeout
 * Maximum wait of 10 minutes before forcing execution
 */
$webhook_function = new InngestFunction(
    id: 'process-webhook',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $data = $event->getData();
        
        echo "Processing webhook for customer: {$data['customer_id']}\n";
        echo "Either events stopped or timeout reached\n";
        
        return ['status' => 'processed', 'customer_id' => $data['customer_id']];
    },
    triggers: [new TriggerEvent('webhook/received')],
    debounce: new Debounce(
        period: '1m',
        timeout: '10m'
    )
);

/*
 * Example 4: Complex key expression
 * Debounce per customer and region combination
 */
$complex_function = new InngestFunction(
    id: 'aggregate-metrics',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $data = $event->getData();
        
        echo "Aggregating metrics for customer {$data['customer_id']} ";
        echo "in region {$data['region']}\n";
        
        return [
            'status' => 'aggregated',
            'customer_id' => $data['customer_id'],
            'region' => $data['region'],
        ];
    },
    triggers: [new TriggerEvent('metrics/collected')],
    debounce: new Debounce(
        period: '2m',
        key: 'event.data.customer_id + "-" + event.data.region',
        timeout: '15m'
    )
);

// Register all functions
$client->registerFunction($basic_function);
$client->registerFunction($per_user_function);
$client->registerFunction($webhook_function);
$client->registerFunction($complex_function);

// Simulate sending events that will be debounced
echo "Sending rapid user input events...\n";
$client->send(new Event(
    name: 'user/input',
    data: ['text' => 'H', 'user_id' => 'user-123']
));
$client->send(new Event(
    name: 'user/input',
    data: ['text' => 'He', 'user_id' => 'user-123']
));
$client->send(new Event(
    name: 'user/input',
    data: ['text' => 'Hel', 'user_id' => 'user-123']
));
$client->send(new Event(
    name: 'user/input',
    data: ['text' => 'Hell', 'user_id' => 'user-123']
));
$client->send(new Event(
    name: 'user/input',
    data: ['text' => 'Hello', 'user_id' => 'user-123']
));
echo "Only the last event ('Hello') will be processed after 30s of silence\n\n";

echo "Sending user update events for multiple users...\n";
$client->send(new Event(
    name: 'user/updated',
    data: ['user_id' => 'user-123', 'name' => 'Alice']
));
$client->send(new Event(
    name: 'user/updated',
    data: ['user_id' => 'user-456', 'name' => 'Bob']
));
$client->send(new Event(
    name: 'user/updated',
    data: ['user_id' => 'user-123', 'name' => 'Alice Updated']
));
echo "Each user_id has its own debounce window (5m)\n\n";

echo "Sending webhook events...\n";
for ($i = 0; $i < 5; $i++) {
    $client->send(new Event(
        name: 'webhook/received',
        data: ['customer_id' => 'cust-789', 'update' => $i]
    ));
}
echo "Will process after 1m of silence OR 10m timeout (whichever comes first)\n\n";

echo "Debounce example setup complete!\n";
echo "\nTo test this example:\n";
echo "1. Start Inngest dev server: npx inngest-cli@latest dev\n";
echo "2. Serve this function endpoint\n";
echo "3. Send events and watch them debounce in the Inngest dashboard\n";
