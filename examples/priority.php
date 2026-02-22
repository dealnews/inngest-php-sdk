<?php

declare(strict_types=1);

/**
 * Example: Using Priority
 *
 * This example demonstrates how to configure priority on Inngest functions
 * to dynamically control the execution order of function runs based on event data.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\Priority;
use DealNews\Inngest\Function\TriggerEvent;

// Create Inngest client
$client = new Inngest('priority-example');

// Example 1: Simple priority from event data
// Use a priority value directly from the event
$simple_priority = new InngestFunction(
    id: 'process-task-simple',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        $data = $ctx->getEvent()->getData();
        $priority = $data['priority'] ?? 0;
        
        $result = $step->run('execute-task', function () use ($data) {
            // Process the task
            return [
                'task_id'  => $data['task_id'] ?? 'unknown',
                'priority' => $data['priority'] ?? 0,
                'status'   => 'complete',
            ];
        });
        
        return $result;
    },
    triggers: [new TriggerEvent('task/process')],
    priority: new Priority(run: 'event.data.priority')
);

// Example 2: Conditional priority based on account type
// Enterprise accounts get higher priority (run up to 120 seconds ahead)
$account_priority = new InngestFunction(
    id: 'ai-generate-summary',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        $data = $ctx->getEvent()->getData();
        $account_type = $data['account_type'] ?? 'free';
        
        $summary = $step->run('generate-summary', function () use ($data) {
            // AI processing that should prioritize paying customers
            return [
                'summary'      => "Generated summary for: {$data['content_id']}",
                'account_type' => $data['account_type'] ?? 'free',
            ];
        });
        
        return $summary;
    },
    triggers: [new TriggerEvent('ai/summary.requested')],
    priority: new Priority(
        run: 'event.data.account_type == "enterprise" ? 120 : 0'
    )
);

// Example 3: Delayed priority for free tier
// Free plan users are delayed by 60 seconds
$delayed_priority = new InngestFunction(
    id: 'process-report',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        $data = $ctx->getEvent()->getData();
        $plan = $data['plan'] ?? 'free';
        
        $report = $step->run('generate-report', function () use ($data) {
            // Generate a report - free tier gets lower priority
            return [
                'report_id' => $data['report_id'] ?? 'unknown',
                'plan'      => $data['plan'] ?? 'free',
                'status'    => 'generated',
            ];
        });
        
        return $report;
    },
    triggers: [new TriggerEvent('report/generate')],
    priority: new Priority(
        run: 'event.data.plan == "free" ? -60 : 0'
    )
);

// Example 4: Multi-tier priority
// Different priority levels based on subscription tier
$tiered_priority = new InngestFunction(
    id: 'process-video',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        $data = $ctx->getEvent()->getData();
        
        $result = $step->run('process-video', function () use ($data) {
            // Video processing with tiered priority
            return [
                'video_id' => $data['video_id'] ?? 'unknown',
                'tier'     => $data['subscription_tier'] ?? 'free',
                'status'   => 'processed',
            ];
        });
        
        return $result;
    },
    triggers: [new TriggerEvent('video/process')],
    priority: new Priority(
        // Enterprise: +300, Pro: +120, Plus: +60, Free: 0
        run: 'event.data.subscription_tier == "enterprise" ? 300 : '.
             '(event.data.subscription_tier == "pro" ? 120 : '.
             '(event.data.subscription_tier == "plus" ? 60 : 0))'
    )
);

// Example 5: Priority based on urgency flag
// Critical tasks get maximum priority
$urgent_priority = new InngestFunction(
    id: 'process-alert',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        $data = $ctx->getEvent()->getData();
        $is_critical = $data['critical'] ?? false;
        
        $result = $step->run('handle-alert', function () use ($data) {
            // Alert processing
            return [
                'alert_id' => $data['alert_id'] ?? 'unknown',
                'critical' => $data['critical'] ?? false,
                'handled'  => true,
            ];
        });
        
        return $result;
    },
    triggers: [new TriggerEvent('alert/process')],
    priority: new Priority(
        run: 'event.data.critical == true ? 600 : 0'
    )
);

// Register all functions
$client->registerFunction($simple_priority);
$client->registerFunction($account_priority);
$client->registerFunction($delayed_priority);
$client->registerFunction($tiered_priority);
$client->registerFunction($urgent_priority);

// Example usage: Send events with different priorities
echo "Priority Examples\n";
echo "=================\n\n";

// Send high priority event
$client->send(new Event(
    name: 'task/process',
    data: [
        'task_id'  => 'task-001',
        'priority' => 300,
    ]
));
echo "✓ Sent high priority task (priority: 300)\n";

// Send enterprise account event
$client->send(new Event(
    name: 'ai/summary.requested',
    data: [
        'content_id'   => 'content-123',
        'account_type' => 'enterprise',
    ]
));
echo "✓ Sent enterprise summary request (priority: +120s)\n";

// Send free tier event (delayed)
$client->send(new Event(
    name: 'report/generate',
    data: [
        'report_id' => 'report-456',
        'plan'      => 'free',
    ]
));
echo "✓ Sent free tier report (priority: -60s delayed)\n";

// Send critical alert
$client->send(new Event(
    name: 'alert/process',
    data: [
        'alert_id' => 'alert-789',
        'critical' => true,
    ]
));
echo "✓ Sent critical alert (priority: +600s max priority)\n";

echo "\nPriority Notes:\n";
echo "- Positive values: Run ahead of jobs enqueued up to N seconds ago\n";
echo "- Negative values: Delay execution by N seconds\n";
echo "- Range: -600 to +600 seconds\n";
echo "- Most effective when combined with concurrency limits\n";
