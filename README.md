# Inngest PHP SDK

Unofficial PHP SDK for [Inngest](https://www.inngest.com) - Build event-driven workflows with durable execution.

## Installation

```bash
composer require dealnews/inngest-php
```

## Requirements

- PHP 8.1 or higher
- ext-json
- ext-hash

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
