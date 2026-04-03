<?php

/**
 * Rate Limiting Example
 *
 * This example demonstrates how to use rate limiting to prevent
 * excessive function runs within a time period.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\RateLimit;
use DealNews\Inngest\Function\TriggerEvent;

// Create Inngest client
$client = new Inngest('rate-limit-example');

// Example 1: Simple rate limit (10 runs per hour)
$simple_rate_limit = new InngestFunction(
    id: 'send-notification',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $user_id = $event->getData()['user_id'];
        
        echo "Sending notification to user {$user_id}\n";
        
        // Send notification logic here
        
        return ['sent' => true];
    },
    triggers: [new TriggerEvent('notification/send')],
    rate_limit: new RateLimit(limit: 10, period: '1h')
);

// Example 2: Per-user rate limit (5 notifications per user per 30 minutes)
$per_user_rate_limit = new InngestFunction(
    id: 'send-user-notification',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $user_id = $event->getData()['user_id'];
        
        echo "Sending notification to user {$user_id}\n";
        
        return ['sent' => true];
    },
    triggers: [new TriggerEvent('user/notification')],
    rate_limit: new RateLimit(
        limit: 5,
        period: '30m',
        key: 'event.data.user_id'
    )
);

// Example 3: Per-customer API sync (100 syncs per customer per 24 hours)
$api_sync = new InngestFunction(
    id: 'sync-customer-data',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();
        
        $customer_id = $event->getData()['customer_id'];
        
        // Fetch data from external API
        $data = $step->run('fetch-data', function () use ($customer_id) {
            echo "Fetching data for customer {$customer_id}\n";
            // API call here
            return ['items' => []];
        });
        
        // Process the data
        $step->run('process-data', function () use ($data) {
            echo "Processing " . count($data['items']) . " items\n";
            // Process items
        });
        
        return ['synced' => true];
    },
    triggers: [new TriggerEvent('customer/sync')],
    rate_limit: new RateLimit(
        limit: 100,
        period: '24h',
        key: 'event.data.customer_id'
    )
);

// Example 4: Webhook rate limit with complex key
$webhook_processor = new InngestFunction(
    id: 'process-webhook',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $data = $event->getData();
        
        echo "Processing webhook for {$data['account_id']}/{$data['region']}\n";
        
        return ['processed' => true];
    },
    triggers: [new TriggerEvent('webhook/received')],
    rate_limit: new RateLimit(
        limit: 50,
        period: '1h',
        key: 'event.data.account_id + "-" + event.data.region'
    )
);

// Register functions
$client->registerFunction($simple_rate_limit);
$client->registerFunction($per_user_rate_limit);
$client->registerFunction($api_sync);
$client->registerFunction($webhook_processor);

echo "Rate limiting examples registered:\n";
echo "1. Simple rate limit: 10 runs per hour\n";
echo "2. Per-user rate limit: 5 per user per 30 minutes\n";
echo "3. API sync: 100 per customer per 24 hours\n";
echo "4. Webhook: 50 per account/region per hour\n";

// Example: Send events
echo "\nSending test events...\n";

// Send multiple events for the same user
// Only 5 will be processed within 30 minutes
for ($i = 1; $i <= 10; $i++) {
    $client->send(new Event(
        name: 'user/notification',
        data: [
            'user_id' => 'user-123',
            'message' => "Notification #{$i}",
        ]
    ));
}

echo "Sent 10 events for user-123\n";
echo "Rate limit: Only 5 will be processed within 30 minutes\n";
echo "The remaining 5 will be skipped\n";

// Send events for different customers
$client->send(new Event(
    name: 'customer/sync',
    data: ['customer_id' => 'customer-456']
));

$client->send(new Event(
    name: 'customer/sync',
    data: ['customer_id' => 'customer-789']
));

echo "\nSent sync events for 2 different customers\n";
echo "Each customer has its own rate limit of 100 per 24 hours\n";
