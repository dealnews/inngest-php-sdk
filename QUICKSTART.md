# Quick Start Guide

Get up and running with the Inngest PHP SDK in minutes.

## Installation

```bash
composer require dealnews/inngest-php
```

## 1. Set Up Your Environment

Create a `.env` file or set environment variables:

```bash
# For development (uses local Inngest dev server)
INNGEST_DEV=1

# For production
INNGEST_EVENT_KEY=your-event-key-here
INNGEST_SIGNING_KEY=signkey-prod-xxxxxxxxxx
INNGEST_ENV=production
```

## 2. Create Your First Function

Create a file `inngest-functions.php`:

```php
<?php

require_once 'vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;

// Create the Inngest client
$inngest = new Inngest('my-app');

// Define a function
$hello_function = new InngestFunction(
    id: 'hello-world',
    handler: function ($ctx) {
        $name = $ctx->getEvent()->getData()['name'] ?? 'World';
        return ['message' => "Hello, {$name}!"];
    },
    triggers: [new TriggerEvent('app/hello')],
    name: 'Hello World'
);

// Register the function
$inngest->registerFunction($hello_function);

return $inngest;
```

## 3. Serve Your Functions

Create an HTTP endpoint `api/inngest.php`:

```php
<?php

require_once '../vendor/autoload.php';

use DealNews\Inngest\Http\ServeHandler;

// Load your functions
$inngest = require_once '../inngest-functions.php';

// Create the serve handler
$handler = new ServeHandler($inngest, '/api/inngest');

// Handle the request
$response = $handler->handle(
    method: $_SERVER['REQUEST_METHOD'],
    path: $_SERVER['REQUEST_URI'],
    headers: getallheaders() ?: [],
    body: file_get_contents('php://input') ?: '',
    query: $_GET
);

// Send the response
http_response_code($response['status']);
foreach ($response['headers'] as $key => $value) {
    header("{$key}: {$value}");
}
echo $response['body'];
```

## 4. Start the Inngest Dev Server

In a terminal, start the Inngest dev server:

```bash
npx inngest-cli@latest dev
```

This will start a local development server at `http://localhost:8288`.

## 5. Sync Your Functions

Start your PHP server and navigate to your endpoint:

```bash
# Using PHP's built-in server
php -S localhost:8000

# Then in another terminal, trigger a sync
curl -X PUT http://localhost:8000/api/inngest
```

Or just visit `http://localhost:8000/api/inngest` in your browser - the Inngest dev server will automatically discover and sync your functions!

## 6. Send Your First Event

```php
<?php

require_once 'vendor/autoload.php';

use DealNews\Inngest\Event\Event;

$inngest = require_once 'inngest-functions.php';

// Send an event
$result = $inngest->send(new Event(
    name: 'app/hello',
    data: ['name' => 'PHP Developer']
));

print_r($result);
```

Or use the Inngest dev server UI at `http://localhost:8288` to send test events!

## Next Steps

### Use Steps for Reliability

```php
$workflow = new InngestFunction(
    id: 'user-signup',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        
        // Each step is independently retriable
        $user = $step->run('create-user', function () use ($ctx) {
            return createUser($ctx->getEvent()->getData());
        });
        
        $step->run('send-email', function () use ($user) {
            sendWelcomeEmail($user['email']);
        });
        
        return ['success' => true];
    },
    triggers: [new TriggerEvent('user/signup')]
);
```

### Add Delays

```php
// Sleep for a duration
$step->sleep('wait-24h', '24h');

// Or use seconds
$step->sleep('wait-5min', 300);
```

### Wait for Events

```php
$payment = $step->waitForEvent(
    id: 'wait-payment',
    event: 'payment/completed',
    timeout: '1h',
    if: 'event.data.order_id == async.data.order_id'
);
```

### Schedule with Cron

```php
use DealNews\Inngest\Function\TriggerCron;

$daily_task = new InngestFunction(
    id: 'daily-cleanup',
    handler: function ($ctx) {
        // Runs every day at midnight
        cleanupOldData();
        return ['cleaned' => true];
    },
    triggers: [new TriggerCron('0 0 * * *')]
);
```

### Control Concurrency

Limit how many steps run simultaneously across all function runs:

```php
use DealNews\Inngest\Function\Concurrency;

// Simple limit
$function = new InngestFunction(
    id: 'rate-limited-task',
    handler: function ($ctx) {
        // Your logic here
    },
    triggers: [new TriggerEvent('task/process')],
    concurrency: [
        new Concurrency(limit: 10) // Max 10 concurrent steps
    ]
);

// Per-user limit
$per_user = new InngestFunction(
    id: 'user-task',
    handler: function ($ctx) {
        // Process user task
    },
    triggers: [new TriggerEvent('user/task')],
    concurrency: [
        new Concurrency(
            limit: 2,
            key: 'event.data.user_id' // 2 concurrent per user
        )
    ]
);
```

**Heads-up:** Use concurrency to prevent overwhelming external APIs or to manage resource usage.

## Framework Integration

### Laravel

In `routes/web.php`:

```php
use DealNews\Inngest\Http\ServeHandler;

Route::any('/api/inngest', function (Request $request) {
    $inngest = app(Inngest::class);
    $handler = new ServeHandler($inngest, '/api/inngest');
    
    $response = $handler->handle(
        method: $request->method(),
        path: $request->path(),
        headers: $request->headers->all(),
        body: $request->getContent(),
        query: $request->query->all()
    );
    
    return response($response['body'], $response['status'])
        ->withHeaders($response['headers']);
});
```

### Symfony

```php
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

public function inngest(Request $request): Response
{
    $handler = new ServeHandler($this->inngest, '/api/inngest');
    
    $result = $handler->handle(
        method: $request->getMethod(),
        path: $request->getPathInfo(),
        headers: $request->headers->all(),
        body: $request->getContent(),
        query: $request->query->all()
    );
    
    return new Response(
        content: $result['body'],
        status: $result['status'],
        headers: $result['headers']
    );
}
```

## Troubleshooting

### Functions Not Syncing

1. Ensure the Inngest dev server is running
2. Check that `INNGEST_DEV=1` is set
3. Verify your endpoint is accessible
4. Look for errors in the Inngest dev server logs

### Events Not Triggering Functions

1. Check that the event name matches the trigger
2. Verify the event was sent successfully
3. Look at the Inngest dev server UI to see event history
4. Check your function's trigger configuration

### Signature Verification Errors

1. In dev mode, ensure `INNGEST_DEV=1` is set
2. In production, verify `INNGEST_SIGNING_KEY` is correct
3. Check that request bodies aren't being modified by middleware

## Resources

- [Full Documentation](./README.md)
- [API Reference](./docs/api.md)
- [Examples](./examples/)
- [Inngest Documentation](https://www.inngest.com/docs)
- [SDK Specification](https://github.com/inngest/inngest/blob/main/docs/SDK_SPEC.md)
