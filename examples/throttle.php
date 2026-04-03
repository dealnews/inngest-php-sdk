<?php

/**
 * Throttling Example
 *
 * This example demonstrates how to use throttling to limit function runs
 * within a time period while enqueuing excess runs for future execution.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\Throttle;
use DealNews\Inngest\Function\TriggerEvent;

// Create Inngest client
$client = new Inngest('throttle-example');

// Example 1: Simple throttle (10 runs per hour)
// Excess runs are enqueued (FIFO) for future execution
$simple_throttle = new InngestFunction(
    id: 'send-notification',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $user_id = $event->getData()['user_id'];
        
        echo "Sending notification to user {$user_id}\n";
        
        // Send notification logic here
        
        return ['sent' => true];
    },
    triggers: [new TriggerEvent('notification/send')],
    throttle: new Throttle(limit: 10, period: '1h')
);

// Example 2: Throttle with burst (allows 15 runs per hour: 10 + 5 burst)
// Useful for handling traffic spikes while maintaining average rate
$burst_throttle = new InngestFunction(
    id: 'process-webhook',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $webhook_id = $event->getData()['webhook_id'];
        
        echo "Processing webhook {$webhook_id}\n";
        
        return ['processed' => true];
    },
    triggers: [new TriggerEvent('webhook/received')],
    throttle: new Throttle(
        limit: 10,
        period: '1h',
        burst: 5  // Allows 15 total runs per hour
    )
);

// Example 3: Per-user throttling (5 runs per user per 30 minutes)
// Each user has their own independent throttle limit
$per_user_throttle = new InngestFunction(
    id: 'user-action',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $user_id = $event->getData()['user_id'];
        $action = $event->getData()['action'];
        
        echo "User {$user_id} performed action: {$action}\n";
        
        return ['executed' => true];
    },
    triggers: [new TriggerEvent('user/action')],
    throttle: new Throttle(
        limit: 5,
        period: '30m',
        key: 'event.data.user_id'
    )
);

// Example 4: API rate limit compliance with burst
// Match external API's rate limit (100/hour) with burst allowance
$api_sync = new InngestFunction(
    id: 'sync-external-api',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();
        
        $customer_id = $event->getData()['customer_id'];
        
        // Fetch data from external API
        $data = $step->run('fetch-api-data', function () use ($customer_id) {
            echo "Fetching data for customer {$customer_id} from API\n";
            // API call here - respects their 100/hour rate limit
            return ['items' => []];
        });
        
        // Process the data
        $step->run('save-data', function () use ($data, $customer_id) {
            echo "Saving " . count($data['items']) . 
                 " items for customer {$customer_id}\n";
        });
        
        return ['synced' => true];
    },
    triggers: [new TriggerEvent('customer/sync')],
    throttle: new Throttle(
        limit: 100,        // Match API's 100/hour limit
        period: '1h',
        burst: 10,         // Allow small bursts
        key: 'event.data.customer_id'
    )
);

// Example 5: Complex key (per account and region)
$regional_throttle = new InngestFunction(
    id: 'regional-processing',
    handler: function ($ctx) {
        $event = $ctx->getEvent();
        $data = $event->getData();
        
        echo "Processing for account {$data['account_id']} ".
             "in region {$data['region']}\n";
        
        return ['processed' => true];
    },
    triggers: [new TriggerEvent('data/process')],
    throttle: new Throttle(
        limit: 50,
        period: '1h',
        burst: 5,
        key: 'event.data.account_id + "-" + event.data.region'
    )
);

// Register functions
$client->registerFunction($simple_throttle);
$client->registerFunction($burst_throttle);
$client->registerFunction($per_user_throttle);
$client->registerFunction($api_sync);
$client->registerFunction($regional_throttle);

echo "Throttling examples registered:\n";
echo "1. Simple throttle: 10 runs per hour\n";
echo "2. Burst throttle: 15 runs per hour (10 + 5 burst)\n";
echo "3. Per-user throttle: 5 per user per 30 minutes\n";
echo "4. API sync: 100 per hour with 10 burst (per customer)\n";
echo "5. Regional: 50 per hour per account/region\n";

echo "\n" . str_repeat("=", 70) . "\n";
echo "THROTTLE vs RATE LIMIT - Key Differences\n";
echo str_repeat("=", 70) . "\n\n";

echo "THROTTLE:\n";
echo "  • Excess events are ENQUEUED (FIFO)\n";
echo "  • No data loss - all events eventually processed\n";
echo "  • Supports burst parameter\n";
echo "  • Max period: 7 days\n";
echo "  • Use case: API rate limits, smooth traffic spikes\n\n";

echo "RATE LIMIT:\n";
echo "  • Excess events are SKIPPED\n";
echo "  • Data loss - events over limit are ignored\n";
echo "  • No burst parameter\n";
echo "  • Max period: 24 hours\n";
echo "  • Use case: Hard limits, preventing abuse\n\n";

echo str_repeat("=", 70) . "\n";
echo "FIFO (First In First Out) Behavior\n";
echo str_repeat("=", 70) . "\n\n";

// Example: Send multiple events for the same user
// Demonstrates FIFO queueing
echo "Sending 10 events for user-123...\n";
for ($i = 1; $i <= 10; $i++) {
    $client->send(new Event(
        name: 'user/action',
        data: [
            'user_id' => 'user-123',
            'action'  => "action-{$i}",
        ]
    ));
}

echo "\nWith throttle limit of 5 per 30 minutes:\n";
echo "  • First 5 events: Execute immediately\n";
echo "  • Next 5 events: Enqueued for future execution (FIFO)\n";
echo "  • Events execute in order: action-1, action-2, ..., action-10\n";
echo "  • No events are skipped or lost!\n\n";

echo "If this were rate limiting instead:\n";
echo "  • First 5 events: Execute immediately\n";
echo "  • Next 5 events: SKIPPED (lost)\n";
echo "  • Only actions 1-5 would execute\n\n";

// Example: Burst behavior
echo str_repeat("=", 70) . "\n";
echo "Burst Parameter Behavior\n";
echo str_repeat("=", 70) . "\n\n";

echo "Without burst (limit: 10, period: 1h):\n";
echo "  • GCRA admits 1 event every 6 minutes (60min / 10)\n";
echo "  • Steady rate over the hour\n\n";

echo "With burst (limit: 10, period: 1h, burst: 5):\n";
echo "  • GCRA admits up to 15 events per hour (10 + 5)\n";
echo "  • Allows handling traffic spikes\n";
echo "  • Example: 15 events arrive at once - all processed\n";
echo "  • Example: Steady flow of 1/6min still works\n\n";

// Send events for different customers
$client->send(new Event(
    name: 'customer/sync',
    data: ['customer_id' => 'customer-456']
));

$client->send(new Event(
    name: 'customer/sync',
    data: ['customer_id' => 'customer-789']
));

echo "Sent sync events for 2 different customers\n";
echo "Each customer has its own throttle limit of 100 per hour\n";
echo "They won't interfere with each other's rate!\n\n";

echo str_repeat("=", 70) . "\n";
echo "Use Cases\n";
echo str_repeat("=", 70) . "\n\n";

echo "1. API Rate Limit Compliance\n";
echo "   • External API allows 100 requests/hour\n";
echo "   • Set throttle: limit=100, period='1h'\n";
echo "   • Add burst for their burst allowance\n";
echo "   • All events eventually processed\n\n";

echo "2. Smooth Traffic Spikes\n";
echo "   • Prevent overwhelming downstream services\n";
echo "   • Evenly distribute execution over time\n";
echo "   • Queue excess for later processing\n\n";

echo "3. Background Job Processing\n";
echo "   • Control job processing rate\n";
echo "   • Prevent resource exhaustion\n";
echo "   • Maintain steady throughput\n\n";

echo str_repeat("=", 70) . "\n";
echo "Best Practices\n";
echo str_repeat("=", 70) . "\n\n";

echo "• Use throttle when you need to process ALL events\n";
echo "• Use rate limit when excess events should be dropped\n";
echo "• Set burst to handle legitimate traffic spikes\n";
echo "• Use key parameter for per-entity limits\n";
echo "• Configure start timeouts to prevent large backlogs\n";
echo "• Monitor queue depth in Inngest dashboard\n";
