# Inngest PHP SDK

Unofficial PHP SDK for [Inngest](https://www.inngest.com) - Build event-driven workflows with durable execution.

## Installation

```bash
composer require dealnews/inngest-php-sdk
```

## Requirements

- PHP 8.1 or higher
- ext-json
- ext-hash

## Features

- ✅ **Event-driven workflows** - Trigger functions from events
- ✅ **Durable execution** - Automatic retries and step memoization
- ✅ **Step functions** - Break work into retriable blocks
- ✅ **Sleep & delays** - Pause execution for minutes, hours, or days
- ✅ **Wait for events** - Coordinate across async workflows
- ✅ **Function invocation** - Call other Inngest functions
- ✅ **Cron triggers** - Schedule recurring tasks
- ✅ **Concurrency control** - Limit parallel execution
- ✅ **Priority queues** - Dynamic execution ordering
- ✅ **Debounce** - Delay execution until events settle
- ✅ **Singleton** - Ensure only one run executes at a time
- ✅ **Dev mode** - Local development with Inngest dev server
- ✅ **Type safety** - Full PHP 8.1+ type declarations

## Quick Start

### 1. Create an Inngest Client

```php
use DealNews\Inngest\Client\Inngest;

$client = new Inngest('my-app');
```

### 2. Define a Function

```php
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;

$function = new InngestFunction(
    id: 'hello-world',
    handler: function ($ctx) {
        $name = $ctx->getEvent()->getData()['name'] ?? 'World';
        return ['message' => "Hello, {$name}!"];
    },
    triggers: [
        new TriggerEvent('demo/hello')
    ],
    name: 'Hello World Function'
);

$client->registerFunction($function);
```

### 3. Serve Functions

```php
use DealNews\Inngest\Http\ServeHandler;

$handler = new ServeHandler($client, '/api/inngest');

// In your framework (e.g., Laravel, Symfony):
$response = $handler->handle(
    method: $_SERVER['REQUEST_METHOD'],
    path: $_SERVER['REQUEST_URI'],
    headers: getallheaders(),
    body: file_get_contents('php://input'),
    query: $_GET
);

http_response_code($response['status']);
foreach ($response['headers'] as $key => $value) {
    header("{$key}: {$value}");
}
echo $response['body'];
```

### 4. Send Events

```php
use DealNews\Inngest\Event\Event;

$client->send(new Event(
    name: 'demo/hello',
    data: ['name' => 'PHP Developer']
));
```

## Using Steps

Steps enable you to break your function into retriable blocks:

```php
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;

$function = new InngestFunction(
    id: 'process-order',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        // Each step is individually retriable
        $order = $step->run('fetch-order', function () use ($ctx) {
            return fetchOrder($ctx->getEvent()->getData()['order_id']);
        });
        
        $step->run('charge-customer', function () use ($order) {
            return chargeCustomer($order);
        });
        
        // Sleep for a duration
        $step->sleep('wait-for-fulfillment', '1h');
        
        $step->run('send-confirmation', function () use ($order) {
            return sendConfirmationEmail($order);
        });
        
        return ['status' => 'complete'];
    },
    triggers: [new TriggerEvent('order/created')]
);
```

### Wait for Events

```php
$payment = $step->waitForEvent(
    id: 'wait-for-payment',
    event: 'payment/completed',
    timeout: '1h',
    if: 'event.data.order_id == async.data.order_id'
);
```

### Invoke Other Functions

```php
$result = $step->invoke(
    id: 'call-function',
    function_id: 'my-app-other-function',
    payload: ['data' => ['foo' => 'bar']]
);
```

## Configuration

The SDK uses environment variables for configuration:

```bash
# Required for production
INNGEST_SIGNING_KEY=signkey-prod-xxxxx
INNGEST_EVENT_KEY=your-event-key

# Optional
INNGEST_SIGNING_KEY_FALLBACK=signkey-prod-yyyyy
INNGEST_ENV=production
INNGEST_DEV=1  # Enable dev server mode
INNGEST_API_BASE_URL=https://api.inngest.com
INNGEST_EVENT_API_BASE_URL=https://inn.gs
INNGEST_SERVE_ORIGIN=https://yourapp.com
INNGEST_SERVE_PATH=/api/inngest
INNGEST_LOG_LEVEL=debug
```

Or configure programmatically:

```php
use DealNews\Inngest\Config\Config;

$config = new Config(
    event_key: 'your-event-key',
    signing_key: 'signkey-prod-xxxxx',
    is_dev: false
);

$client = new Inngest('my-app', $config);
```

## Error Handling

### Non-Retriable Errors

```php
use DealNews\Inngest\Error\NonRetriableError;

$function = new InngestFunction(
    id: 'validate-data',
    handler: function ($ctx) {
        if (!isValid($ctx->getEvent()->getData())) {
            throw new NonRetriableError('Invalid data');
        }
        return ['status' => 'ok'];
    },
    triggers: [new TriggerEvent('data/received')]
);
```

### Retry After Specific Time

```php
use DealNews\Inngest\Error\RetryAfterError;

throw new RetryAfterError('Rate limited', retry_after: 60); // Retry after 60 seconds
```

## Cron Triggers

```php
use DealNews\Inngest\Function\TriggerCron;

$function = new InngestFunction(
    id: 'daily-report',
    handler: function ($ctx) {
        generateDailyReport();
        return ['status' => 'complete'];
    },
    triggers: [
        new TriggerCron('0 0 * * *') // Every day at midnight
    ]
);
```

## Concurrency Control

Limit how many steps can run simultaneously across all function runs. Useful for rate-limiting external APIs, managing resources, or preventing overwhelming downstream services.

### Basic Limit

```php
use DealNews\Inngest\Function\Concurrency;

$function = new InngestFunction(
    id: 'process-data',
    handler: function ($ctx) {
        // Function logic
    },
    triggers: [new TriggerEvent('data/process')],
    concurrency: [
        new Concurrency(limit: 10) // Max 10 concurrent steps
    ]
);
```

### Per-User Limits

```php
// Limit to 2 concurrent runs per user
$function = new InngestFunction(
    id: 'user-task',
    handler: function ($ctx) {
        // Process user-specific task
    },
    triggers: [new TriggerEvent('user/task')],
    concurrency: [
        new Concurrency(
            limit: 2,
            key: 'event.data.user_id' // Group by user ID
        )
    ]
);
```

### Multi-Level Limits

```php
// Set both regional and account-wide limits
$function = new InngestFunction(
    id: 'process-orders',
    handler: function ($ctx) {
        // Process order
    },
    triggers: [new TriggerEvent('order/created')],
    concurrency: [
        // Limit per region
        new Concurrency(
            limit: 5,
            key: 'event.data.region',
            scope: 'fn' // Per function (default)
        ),
        // Overall account limit
        new Concurrency(
            limit: 100,
            scope: 'account' // Across all environments
        )
    ]
);
```

### Concurrency Options

- **limit**: Maximum concurrent steps (0 = unlimited)
- **key**: Expression to group concurrency (e.g., `event.data.user_id`, `event.data.region`)
- **scope**: Where the limit applies
  - `fn` (default): Per function
  - `env`: Per environment (production, staging, etc.)
  - `account`: Across entire account

**Heads-up:** Maximum of 2 concurrency configurations per function.

See [examples/concurrency.php](examples/concurrency.php) for more examples.

## Priority

Dynamically prioritize function runs based on event data. Higher priority runs execute ahead of lower priority ones within the same function queue.

### Basic Priority

```php
use DealNews\Inngest\Function\Priority;

$function = new InngestFunction(
    id: 'process-task',
    handler: function ($ctx) {
        // Function logic
    },
    triggers: [new TriggerEvent('task/process')],
    priority: new Priority(
        run: 'event.data.priority' // Use priority from event
    )
);
```

### Conditional Priority

```php
// Prioritize enterprise customers
$function = new InngestFunction(
    id: 'ai-generate-summary',
    handler: function ($ctx) {
        // Generate AI summary
    },
    triggers: [new TriggerEvent('ai/summary.requested')],
    priority: new Priority(
        // Enterprise accounts run up to 120 seconds ahead
        run: 'event.data.account_type == "enterprise" ? 120 : 0'
    )
);
```

### Delayed Priority

```php
// Delay free tier users
$function = new InngestFunction(
    id: 'process-report',
    handler: function ($ctx) {
        // Generate report
    },
    triggers: [new TriggerEvent('report/generate')],
    priority: new Priority(
        // Free plan users delayed by 60 seconds
        run: 'event.data.plan == "free" ? -60 : 0'
    )
);
```

### Priority Options

- **run**: CEL expression that returns an integer priority factor
  - Range: `-600` to `600` seconds (enforced by Inngest)
  - Positive values: Run ahead of jobs enqueued up to N seconds ago
  - Negative values: Delay execution by N seconds
  - `0`: No priority (default queue position)

**How it works:** When a function run is enqueued, Inngest evaluates the expression using the event data. The result adjusts the run's position in the queue relative to other pending runs.

**Heads-up:** 
- Most useful when combined with concurrency limits (jobs wait in queue)
- Invalid expressions evaluate to `0` (no priority)
- Out-of-range values are automatically clipped by Inngest

See [Inngest Priority Documentation](https://www.inngest.com/docs/reference/functions/run-priority) for more details.

## Debounce

Delay function execution until events stop arriving for a specified period. Prevents wasted work when functions might be triggered rapidly in succession (user input, webhook floods, frequent updates).

The function runs once using the **last event** received as input data.

### Basic Debounce

```php
use DealNews\Inngest\Function\Debounce;

$function = new InngestFunction(
    id: 'process-user-input',
    handler: function ($ctx) {
        $text = $ctx->getEvent()->getData()['text'];
        // Process final input after user stops typing
        return saveUserInput($text);
    },
    triggers: [new TriggerEvent('user/input')],
    debounce: new Debounce(
        period: '30s' // Wait 30 seconds after last event
    )
);
```

### Per-Key Debounce

```php
// Separate debounce window for each user
$function = new InngestFunction(
    id: 'sync-user-data',
    handler: function ($ctx) {
        $user_id = $ctx->getEvent()->getData()['user_id'];
        // Sync data once updates stop for this user
        return syncUserData($user_id);
    },
    triggers: [new TriggerEvent('user/updated')],
    debounce: new Debounce(
        period: '5m',
        key: 'event.data.user_id' // Each user has own debounce
    )
);
```

### With Timeout

```php
// Process webhooks, but force execution after maximum wait
$function = new InngestFunction(
    id: 'process-webhook',
    handler: function ($ctx) {
        $data = $ctx->getEvent()->getData();
        // Process either when events stop OR timeout reached
        return processWebhook($data);
    },
    triggers: [new TriggerEvent('webhook/received')],
    debounce: new Debounce(
        period: '1m',    // Wait 1 minute after last event
        timeout: '10m'   // Force run after 10 minutes max
    )
);
```

### Complex Key Expression

```php
// Debounce per customer and region combination
$function = new InngestFunction(
    id: 'aggregate-metrics',
    handler: function ($ctx) {
        $data = $ctx->getEvent()->getData();
        return aggregateMetrics($data['customer_id'], $data['region']);
    },
    triggers: [new TriggerEvent('metrics/collected')],
    debounce: new Debounce(
        period: '2m',
        key: 'event.data.customer_id + "-" + event.data.region'
    )
);
```

### Debounce Options

- **period** (required): Time to wait after last event
  - Format: `<number><unit>` where unit is `s`, `m`, `h`, or `d`
  - Range: `1s` to `7d` (168 hours)
  - Examples: `30s`, `5m`, `2h`, `7d`
- **key** (optional): CEL expression to group debounce windows
  - Each unique key value gets its own debounce period
  - Examples: `event.data.user_id`, `event.data.region`
- **timeout** (optional): Maximum wait time before forcing execution
  - Same format and range as period
  - Ensures function eventually runs even if events keep arriving

**How it works:**
1. First event starts the debounce period
2. Each new matching event resets the period timer
3. Function runs when period expires with no new events
4. If timeout is set, function runs after timeout regardless of new events

**Use cases:**
- **User input**: Wait for user to stop typing before processing
- **Webhook processing**: Batch rapid webhook updates into single run
- **Data synchronization**: Use latest data after updates settle
- **Rate limiting**: Prevent overwhelming downstream services

**Heads-up:**
- Cannot combine debounce with batching
- Function receives only the last event, not all events
- Use rate limiting if you need the first event instead of last

See [examples/debounce.php](examples/debounce.php) for more examples and [Inngest Debounce Documentation](https://www.inngest.com/docs/guides/debounce) for details.

## Singleton

Ensure only a single run of a function (or per unique key) is executing at a time. Prevents duplicate work, race conditions, and ensures sequential processing of events.

### Basic Singleton - Skip Mode

```php
use DealNews\Inngest\Function\Singleton;

$function = new InngestFunction(
    id: 'data-sync',
    handler: function ($ctx) {
        // Sync data with third-party API
        // Only one sync can run at a time
        return syncDataWithAPI();
    },
    triggers: [new TriggerEvent('sync/start')],
    singleton: new Singleton(
        mode: 'skip' // Skip new runs if one is executing
    )
);
```

### Per-User Singleton

```php
// Each user has their own singleton rule
$function = new InngestFunction(
    id: 'process-user-data',
    handler: function ($ctx) {
        $user_id = $ctx->getEvent()->getData()['user_id'];
        // Process user data (only one run per user at a time)
        return processUserData($user_id);
    },
    triggers: [new TriggerEvent('user/data.updated')],
    singleton: new Singleton(
        mode: 'skip',
        key: 'event.data.user_id' // Separate singleton per user
    )
);
```

### Cancel Mode

```php
// Always process the latest event
$function = new InngestFunction(
    id: 'sync-latest-profile',
    handler: function ($ctx) {
        $user_id = $ctx->getEvent()->getData()['user_id'];
        // Cancel old sync and start new one with latest data
        return syncUserProfile($user_id);
    },
    triggers: [new TriggerEvent('profile/updated')],
    singleton: new Singleton(
        mode: 'cancel', // Cancel existing run, start new one
        key: 'event.data.user_id'
    )
);
```

### Complex Key Expression

```php
// Singleton per customer and region combination
$function = new InngestFunction(
    id: 'generate-report',
    handler: function ($ctx) {
        $data = $ctx->getEvent()->getData();
        return generateReport($data['customer_id'], $data['region']);
    },
    triggers: [new TriggerEvent('report/generate')],
    singleton: new Singleton(
        mode: 'skip',
        key: 'event.data.customer_id + "-" + event.data.region'
    )
);
```

### Singleton Options

- **mode** (required): Behavior when new run arrives
  - `"skip"`: Skip new runs if another is already executing
  - `"cancel"`: Cancel existing run and start the new one
- **key** (optional): CEL expression to group singleton behavior
  - Each unique key value gets its own singleton rule
  - Examples: `event.data.user_id`, `event.data.tenant_id`

### How It Works

**Skip Mode:**
1. First event starts the function run
2. While running, new matching events are skipped/discarded
3. Function completes with first event's data
4. Next event can then start a new run

**Cancel Mode:**
1. First event starts the function run
2. New matching event cancels the in-progress run
3. New run starts immediately with latest event
4. Rapid events may cause some to be skipped (debounce-like)

### When to Use Each Mode

**Use Skip Mode when:**
- Preventing duplicate work (only need to process once)
- Protecting expensive operations (AI, heavy compute)
- Sequential processing required (database migrations)
- Resource limits (third-party API rate limits)

**Use Cancel Mode when:**
- Latest data matters most (user profile updates)
- Older data becomes stale (real-time dashboards)
- Want to process most recent event (search queries)

### Use Cases

- **Data synchronization**: Third-party API syncs (skip mode)
- **AI processing**: Expensive computations (skip mode)
- **Profile updates**: Always use latest data (cancel mode)
- **Report generation**: One report at a time per customer (skip + key)
- **Database migrations**: Sequential execution required (skip mode)

### Compatibility

**Works with:**
- ✅ Debounce
- ✅ Priority
- ✅ Rate limiting
- ✅ Throttling

**Does not work with:**
- ❌ Batching (singleton incompatible)
- ⚠️ Concurrency (singleton implies concurrency=1)

**Heads-up:**
- Failed functions still skip new runs during retry
- Cancel mode with rapid events may skip some (not all are cancelled)
- Singleton ensures "at most one" run, not "exactly one"

See [examples/singleton.php](examples/singleton.php) for more examples and [Inngest Singleton Documentation](https://www.inngest.com/docs/guides/singleton) for details.

## Development

The SDK follows PSR standards and uses:
- snake_case for variables and properties
- camelCase for method names
- Protected visibility by default

## Testing

```bash
composer test
```

## Resources

- [Inngest Documentation](https://www.inngest.com/docs)
- [SDK Specification](https://github.com/inngest/inngest/blob/main/docs/SDK_SPEC.md)
- [Support](https://www.inngest.com/support)
