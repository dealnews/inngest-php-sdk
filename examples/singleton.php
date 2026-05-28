<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\Singleton;
use DealNews\Inngest\Function\TriggerEvent;

/**
 * Singleton Example - Ensure only one run executes at a time
 *
 * This example demonstrates how singleton prevents multiple concurrent
 * runs of the same function, useful for:
 * - Third-party API synchronization (prevent duplicate calls)
 * - Expensive operations (AI, heavy compute)
 * - Sequential processing requirements
 * - Race condition prevention
 */

$client = new Inngest('singleton-example');

/*
 * Example 1: Basic Skip Mode
 * Prevents duplicate work by skipping new runs while one is executing
 */
$basic_skip_function = new InngestFunction(
    id: 'sync-third-party-api',
    handler: function ($ctx) {
        error_log("Starting third-party API sync...");
        error_log("This takes 5 minutes. New sync requests will be skipped.");
        
        // Simulate long-running API sync
        sleep(2);
        
        error_log("API sync complete!");
        return ['status' => 'synced', 'timestamp' => time()];
    },
    triggers: [new TriggerEvent('api/sync.requested')],
    singleton: new Singleton(mode: 'skip')
);

/*
 * Example 2: Per-User Singleton with Skip Mode
 * Each user has their own singleton rule
 */
$per_user_function = new InngestFunction(
    id: 'process-user-export',
    handler: function ($ctx) {
        $user_id = $ctx->getEvent()->getData()['user_id'];
        
        error_log("Processing export for user {$user_id}...");
        error_log("While this runs, other export requests for user {$user_id} will be skipped.");
        error_log("But exports for OTHER users can run concurrently.");
        
        // Simulate export generation
        sleep(1);
        
        error_log("Export complete for user {$user_id}!");
        return ['user_id' => $user_id, 'export_url' => 'https://...'];
    },
    triggers: [new TriggerEvent('export/requested')],
    singleton: new Singleton(
        mode: 'skip',
        key: 'event.data.user_id'
    )
);

/*
 * Example 3: Cancel Mode - Always Process Latest
 * Cancels in-progress run and starts new one with latest data
 */
$cancel_mode_function = new InngestFunction(
    id: 'sync-user-profile',
    handler: function ($ctx) {
        $user_id = $ctx->getEvent()->getData()['user_id'];
        $version = $ctx->getEvent()->getData()['version'];
        
        error_log("Syncing profile for user {$user_id} (version {$version})...");
        error_log("If user updates profile again, THIS run will be cancelled.");
        error_log("The new run will start with the latest data.");
        
        // Simulate profile sync
        sleep(1);
        
        error_log("Profile synced for user {$user_id} (version {$version})!");
        return [
            'user_id' => $user_id,
            'version' => $version,
            'synced_at' => time(),
        ];
    },
    triggers: [new TriggerEvent('profile/updated')],
    singleton: new Singleton(
        mode: 'cancel',
        key: 'event.data.user_id'
    )
);

/*
 * Example 4: Complex Key Expression
 * Singleton per customer and region combination
 */
$complex_key_function = new InngestFunction(
    id: 'generate-regional-report',
    handler: function ($ctx) {
        $data = $ctx->getEvent()->getData();
        $customer = $data['customer_id'];
        $region = $data['region'];
        
        error_log("Generating report for customer {$customer} in {$region}...");
        error_log("One report per customer-region pair at a time.");
        
        // Simulate report generation
        sleep(1);
        
        error_log("Report complete for {$customer} in {$region}!");
        return [
            'customer_id' => $customer,
            'region' => $region,
            'report_url' => "https://reports.example.com/{$customer}/{$region}",
        ];
    },
    triggers: [new TriggerEvent('report/generate')],
    singleton: new Singleton(
        mode: 'skip',
        key: 'event.data.customer_id + "-" + event.data.region'
    )
);

/*
 * Example 5: AI Processing Workflow
 * Expensive computation that should only run once at a time
 */
$ai_processing_function = new InngestFunction(
    id: 'ai-generate-summary',
    handler: function ($ctx) {
        $data = $ctx->getEvent()->getData();
        $document_id = $data['document_id'];
        
        error_log("Starting AI processing for document {$document_id}...");
        error_log("This is expensive! Skip duplicate requests.");
        
        // Simulate AI processing
        sleep(2);
        
        error_log("AI processing complete for document {$document_id}!");
        return [
            'document_id' => $document_id,
            'summary' => 'AI-generated summary...',
            'cost' => 0.25,
        ];
    },
    triggers: [new TriggerEvent('ai/summary.requested')],
    singleton: new Singleton(
        mode: 'skip',
        key: 'event.data.document_id'
    )
);

// Register all functions
$client->registerFunction($basic_skip_function);
$client->registerFunction($per_user_function);
$client->registerFunction($cancel_mode_function);
$client->registerFunction($complex_key_function);
$client->registerFunction($ai_processing_function);

// Simulate sending events
echo "=== Singleton Examples ===\n\n";

echo "1. Basic Skip Mode:\n";
echo "   Sending 3 sync requests in quick succession...\n";
$client->send(new Event(name: 'api/sync.requested', data: []));
$client->send(new Event(name: 'api/sync.requested', data: []));
$client->send(new Event(name: 'api/sync.requested', data: []));
echo "   Only the first request runs. Others are skipped.\n\n";

echo "2. Per-User Singleton:\n";
echo "   User 123 requests export twice, User 456 requests once...\n";
$client->send(new Event(
    name: 'export/requested',
    data: ['user_id' => '123']
));
$client->send(new Event(
    name: 'export/requested',
    data: ['user_id' => '123']
));
$client->send(new Event(
    name: 'export/requested',
    data: ['user_id' => '456']
));
echo "   User 123: 2nd request skipped (first still running)\n";
echo "   User 456: Runs concurrently with User 123's export\n\n";

echo "3. Cancel Mode:\n";
echo "   User updates profile 3 times quickly...\n";
$client->send(new Event(
    name: 'profile/updated',
    data: ['user_id' => '789', 'version' => 1]
));
$client->send(new Event(
    name: 'profile/updated',
    data: ['user_id' => '789', 'version' => 2]
));
$client->send(new Event(
    name: 'profile/updated',
    data: ['user_id' => '789', 'version' => 3]
));
echo "   Version 1 starts, gets cancelled by version 2\n";
echo "   Version 2 starts, gets cancelled by version 3\n";
echo "   Version 3 completes (latest data wins)\n\n";

echo "4. Complex Key:\n";
echo "   Generate reports for different customer-region pairs...\n";
$client->send(new Event(
    name: 'report/generate',
    data: ['customer_id' => 'cust-1', 'region' => 'us-east']
));
$client->send(new Event(
    name: 'report/generate',
    data: ['customer_id' => 'cust-1', 'region' => 'eu-west']
));
$client->send(new Event(
    name: 'report/generate',
    data: ['customer_id' => 'cust-1', 'region' => 'us-east']
));
echo "   cust-1 / us-east: 2nd request skipped\n";
echo "   cust-1 / eu-west: Runs concurrently (different key)\n\n";

echo "Singleton example setup complete!\n\n";

echo "When to use Skip vs Cancel:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Skip Mode:\n";
echo "  • Prevent duplicate work\n";
echo "  • Expensive operations (AI, compute)\n";
echo "  • Sequential processing required\n";
echo "  • Third-party API rate limits\n\n";
echo "Cancel Mode:\n";
echo "  • Latest data matters most\n";
echo "  • Older data becomes stale\n";
echo "  • Real-time updates (user profiles)\n";
echo "  • Search queries (cancel old, run new)\n\n";

echo "To test this example:\n";
echo "1. Start Inngest dev server: npx inngest-cli@latest dev\n";
echo "2. Serve this function endpoint\n";
echo "3. Send events and watch singleton behavior in Inngest dashboard\n";
