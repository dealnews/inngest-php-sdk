<?php

declare(strict_types=1);

/**
 * Example: Using Concurrency Limits
 *
 * This example demonstrates how to configure concurrency limits on Inngest functions
 * to control how many steps can run simultaneously across all function runs.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\Concurrency;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;

// Create Inngest client
$client = new Inngest('concurrency-example');

// Example 1: Simple concurrency limit
// Limit to 10 concurrent steps across all runs of this function
$simple_function = new InngestFunction(
    id: 'process-simple',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        $result = $step->run('heavy-processing', function () use ($ctx) {
            // This might be a CPU-intensive or rate-limited operation
            return process_data($ctx->getEvent()->getData());
        });
        
        return $result;
    },
    triggers: [new TriggerEvent('data/process')],
    concurrency: [new Concurrency(limit: 10)]
);

// Example 2: Per-user concurrency limits
// Limit to 2 concurrent runs per user_id
$per_user_function = new InngestFunction(
    id: 'process-per-user',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        $data = $ctx->getEvent()->getData();
        $user_id = $data['user_id'] ?? 'unknown';
        
        $result = $step->run('user-specific-task', function () use ($user_id) {
            // Process user-specific data
            return process_user_data($user_id);
        });
        
        return $result;
    },
    triggers: [new TriggerEvent('user/task')],
    concurrency: [
        new Concurrency(
            limit: 2,
            key: 'event.data.user_id'
        )
    ]
);

// Example 3: Multi-level concurrency with scope
// Set both account-wide and per-region limits
$multi_level_function = new InngestFunction(
    id: 'process-orders',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        $order = $step->run('fetch-order', function () use ($ctx) {
            return fetch_order($ctx->getEvent()->getData()['order_id']);
        });
        
        $step->run('process-payment', function () use ($order) {
            return charge_payment($order);
        });
        
        $step->run('send-confirmation', function () use ($order) {
            return send_email($order);
        });
        
        return ['status' => 'complete'];
    },
    triggers: [new TriggerEvent('order/created')],
    concurrency: [
        // Limit to 5 concurrent orders per region
        new Concurrency(
            limit: 5,
            key: 'event.data.region',
            scope: 'fn'
        ),
        // Overall account limit of 100
        new Concurrency(
            limit: 100,
            scope: 'account'
        ),
    ]
);

// Example 4: Complex key expression for grouping
$complex_key_function = new InngestFunction(
    id: 'process-tiered',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        return $step->run('process', function () use ($ctx) {
            return process_with_priority($ctx->getEvent()->getData());
        });
    },
    triggers: [new TriggerEvent('task/process')],
    concurrency: [
        // Different limits based on user's plan
        new Concurrency(
            limit: 10,
            key: 'event.data.user_id + "-" + event.data.plan',
            scope: 'env'
        )
    ]
);

// Example 5: Zero limit (unlimited concurrency)
$unlimited_function = new InngestFunction(
    id: 'process-unlimited',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        return $step->run('task', fn() => ['result' => 'done']);
    },
    triggers: [new TriggerEvent('task/unlimited')],
    concurrency: [new Concurrency(limit: 0)] // 0 = unlimited
);

// Register all functions
$client->registerFunction($simple_function);
$client->registerFunction($per_user_function);
$client->registerFunction($multi_level_function);
$client->registerFunction($complex_key_function);
$client->registerFunction($unlimited_function);

// Helper functions (mock implementations)
function process_data($data) {
    return ['processed' => true, 'data' => $data];
}

function process_user_data($user_id) {
    return ['user_id' => $user_id, 'processed' => true];
}

function fetch_order($order_id) {
    return ['id' => $order_id, 'amount' => 100];
}

function charge_payment($order) {
    return ['charged' => true, 'amount' => $order['amount']];
}

function send_email($order) {
    return ['sent' => true, 'order_id' => $order['id']];
}

function process_with_priority($data) {
    return ['processed' => true, 'priority' => $data['plan'] ?? 'free'];
}

echo "Concurrency examples registered!\n";
echo "\n";
echo "Function configurations:\n";
echo "========================\n\n";

foreach ($client->getFunctions() as $function) {
    echo "Function: " . $function->getId() . "\n";
    $concurrency = $function->getConcurrency();
    if ($concurrency !== null && count($concurrency) > 0) {
        echo "Concurrency:\n";
        foreach ($concurrency as $i => $config) {
            echo "  [" . $i . "] limit=" . $config->getLimit();
            if ($config->getKey()) {
                echo ", key=" . $config->getKey();
            }
            if ($config->getScope()) {
                echo ", scope=" . $config->getScope();
            }
            echo "\n";
        }
    } else {
        echo "Concurrency: None (unlimited)\n";
    }
    echo "\n";
}

echo "\nKey Concepts:\n";
echo "=============\n";
echo "• limit: Maximum concurrent steps (0 = unlimited)\n";
echo "• key: Expression to group concurrency (e.g., per user, per region)\n";
echo "• scope: 'fn' (default), 'env', or 'account' - where limit applies\n";
echo "• Maximum of 2 concurrency configurations per function\n";
