# Inngest PHP SDK

[![PHP](https://img.shields.io/badge/php-%5E8.4-777bb4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE)

Unofficial PHP SDK for [Inngest](https://www.inngest.com): durable,
event-driven functions with retries, steps, sleeps, and flow control,
served from your own PHP app.

It's a port of the official JS SDK (`inngest` v4), built to behave the same
way over the wire: same requests and responses, signatures, step IDs, op
codes, and retry rules. The PHP API is written for PHP, not copied from
TypeScript.

## Contents

- [Features](#features)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Writing functions](#writing-functions)
- [Steps](#steps)
- [Parallel steps](#parallel-steps)
- [Triggers and flow control](#triggers-and-flow-control)
- [Errors and retries](#errors-and-retries)
- [Checkpointing](#checkpointing)
- [Middleware](#middleware)
- [Serving from a framework](#serving-from-a-framework)
- [Configuration](#configuration)
- [How execution works](#how-execution-works)
- [Differences from the JS SDK](#differences-from-the-js-sdk)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

## Features

- ✅ **Durable functions:** each step runs once, and its result is saved
  and replayed on later requests
- ✅ **Step tools:** `run`, `sleep`, `sleepUntil`, `waitForEvent`,
  `invoke`, `sendEvent`, `waitForSignal`, `sendSignal`, `fetch`,
  `ai->infer`, `ai->wrap`
- ✅ **Parallel steps:** `parallel()` (like `Promise.all`) and `race()`
- ✅ **Triggers:** events (with `if` filters) and cron (with jitter)
- ✅ **Flow control:** concurrency, throttle, rate limit, debounce,
  batching, priority, singleton, timeouts, idempotency, cancellation
- ✅ **Error handling:** retries, `NonRetriableError`, `RetryAfterError`,
  catchable step errors, and `onFailure` handlers
- ✅ **Async checkpointing:** runs several steps per request (on by
  default, like JS v4)
- ✅ **Middleware:** the full JS v4 hook set, plus dependency injection
- ✅ **Plain PHP:** one framework-agnostic handler, with PSR-3 logging,
  PSR-7 support, and Guzzle for HTTP

## Installation

```bash
composer require dealnews/inngest-php-sdk
```

Requirements:

- PHP 8.4 or later
- `ext-json` and `ext-hash`
- Guzzle 8 (installed for you)

For local development, run the
[Inngest dev server](https://www.inngest.com/docs/dev-server):

```bash
npx inngest-cli@latest dev
# or
docker run -p 8288:8288 inngest/inngest inngest dev
```

## Quick start

### 1. Create a client and a function

```php
<?php
// inngest.php
use DealNews\Inngest\Client;
use DealNews\Inngest\Context;
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\Trigger\EventTrigger;

require __DIR__ . '/vendor/autoload.php';

$inngest = new Client('my-app');

$inngest->createFunction(
    new FunctionOptions(
        'hello-world',
        triggers: [new EventTrigger('demo/hello')]
    ),
    function (Context $ctx): string {
        $name = $ctx->step->run(
            'get-name',
            fn () => $ctx->event->data['name'] ?? 'World'
        );

        $ctx->step->sleep('wait-a-moment', '5s');

        return "Hello, $name!";
    }
);

return $inngest;
```

### 2. Serve it

Inngest calls your app over HTTP. Point one URL at `ServeHandler`:

```php
<?php
// public/api/inngest.php
use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Http\ServeHandler;

$inngest = require __DIR__ . '/../../inngest.php';

(new ServeHandler($inngest))
    ->handle(Request::fromGlobals())
    ->send();
```

### 3. Run it locally

```bash
INNGEST_DEV=1 php -S 0.0.0.0:3000 -t public
npx inngest-cli@latest dev -u http://localhost:3000/api/inngest.php
```

The dev server syncs your app and lists `hello-world` at
<http://localhost:8288>.

> ⚠️ Without `INNGEST_DEV=1` (or `is_dev: true`), the SDK runs in **cloud
> mode** and needs a signing key.

### 4. Send an event

```php
use DealNews\Inngest\Event\Event;

$inngest->send(new Event('demo/hello', ['name' => 'PHP']));
```

Watch the run in the dev server UI. It sleeps 5 seconds and returns
`"Hello, PHP!"`.

### 5. Go to production

Set `INNGEST_SIGNING_KEY` and `INNGEST_EVENT_KEY` (from the Inngest
dashboard), deploy, and sync your app's URL in the dashboard. Every
request from Inngest is then verified, and every response signed.

## Writing functions

```php
// Client::createFunction()
public function createFunction(
    FunctionOptions $options,
    callable $handler
): InngestFunction
```

The handler gets a `Context` and returns any JSON-encodable value:

| `$ctx->` | What it is |
|---|---|
| `event` | The triggering `Event` (`name`, `data`, `id`, `ts`, ...) |
| `events` | Every event in the batch (one, unless batching) |
| `run_id` | This run's ID |
| `attempt` | Zero-based attempt number |
| `max_attempts` | Total attempts allowed, when known |
| `step` | Step tools (see [Steps](#steps)) |
| `logger` | PSR-3 logger that stays quiet while old steps replay |
| `error` | The error, in `on_failure` handlers only |
| `extras` | Values added by middleware (e.g. dependency injection) |

Event data and step results are plain PHP arrays. JSON objects decode to
associative arrays, so `{}` and `[]` both come back as `[]`.

The function ID is prefixed with the app ID on the wire
(`my-app-hello-world`). The name defaults to the unprefixed ID.

## Steps

A step is a unit of work Inngest runs once and remembers. When a step
finishes, Inngest calls your function again from the top, and every step
that already ran returns its saved result instantly. Code outside steps
runs on every call, so keep side effects inside steps.

Every step needs an ID that's unique in the function. Reusing an ID is
allowed (it becomes `id`, `id:1`, `id:2`, ...), but stable, unique IDs
make runs easier to read. Pass `new StepOptions('id', name: 'Pretty
name')` instead of a string to set a display name.

### `run`: do some work

```php
$user = $ctx->step->run('load-user', fn () => $users->find($id));

// Arguments after the callable are saved with the step and passed in
$total = $ctx->step->run('add', fn (int $a, int $b) => $a + $b, 2, 3);
```

Returning nothing saves `null`. A thrown exception fails the step, and
Inngest retries it (see [Errors and retries](#errors-and-retries)).

### `sleep` and `sleepUntil`: pause the run

```php
$ctx->step->sleep('cool-off', '1h30m');      // or '90 minutes', a DateInterval
$ctx->step->sleep('short', 1500);            // integers are milliseconds
$ctx->step->sleepUntil('tomorrow', new DateTimeImmutable('tomorrow 9am'));
```

No PHP process waits. The request ends, and Inngest calls back later.

### `waitForEvent`: pause until something happens

```php
$approval = $ctx->step->waitForEvent(
    'wait-for-approval',
    event: 'invoice/approved',
    timeout: '3d',
    match: 'data.invoice_id',   // event.data.invoice_id == async.data.invoice_id
);

if ($approval === null) {
    // timed out
}
```

Use `if:` instead of `match:` for a full CEL expression.

### `invoke`: call another function and get its result

```php
use DealNews\Inngest\Functions\FunctionReference;

$result = $ctx->step->invoke('resize', $resize_fn, data: ['url' => $url]);

// A function in another app
$result = $ctx->step->invoke(
    'score',
    new FunctionReference('score-lead', app_id: 'ml-service'),
    data: ['lead' => $lead],
    timeout: '10m',
);
```

### `sendEvent`: send events exactly once

```php
$ctx->step->sendEvent('notify', [
    'name' => 'user/signed-up',
    'data' => ['id' => $user['id']],
]);
```

Inside functions, use this rather than `$inngest->send()`, so a retry
doesn't send the events again.

### Signals

```php
use DealNews\Inngest\Step\SignalConflict;

// One run waits...
$data = $ctx->step->waitForSignal(
    'wait-for-payment',
    signal: "payment-$order_id",
    timeout: '1d',
    on_conflict: SignalConflict::Replace,
);

// ...and another run (or $inngest->sendSignal()) wakes it
$ctx->step->sendSignal('paid', signal: "payment-$order_id", data: $receipt);
```

### `fetch`: an HTTP request made by Inngest

```php
$response = $ctx->step->fetch(
    'https://api.example.com/report',
    method: 'POST',
    headers: ['Content-Type' => 'application/json'],
    body: json_encode($payload),
);

$report = json_decode((string)$response->getBody(), true);
```

Inngest makes the request, so your PHP process doesn't wait on slow APIs.
It returns a PSR-7 response, and error statuses don't throw. Inside a
`step->run()` it makes the request directly with the client's Guzzle
instead.

### AI steps

```php
use DealNews\Inngest\Step\AiFormat;

// Inngest calls the model API for you (no PHP process waiting)
$reply = $ctx->step->ai->infer(
    'summarize',
    url: 'https://api.openai.com/v1/chat/completions',
    format: AiFormat::OpenAiChat,
    body: ['model' => 'gpt-4o', 'messages' => $messages],
    auth_key: getenv('OPENAI_API_KEY'),
);

// Or run your own client code as a step that shows up as an AI call
$reply = $ctx->step->ai->wrap('summarize', fn () => $my_llm->chat($messages));
```

Only the generic `infer` form exists. There are no provider-specific
model helpers.

## Parallel steps

JS gets parallelism from `Promise.all`. PHP has no promises, so the SDK
gives you explicit calls. Each branch is a callable that runs steps.

```php
// Like Promise.all: wait for every branch
[$user, $orders] = $ctx->step->parallel(
    fn () => $ctx->step->run('user', fn () => $users->find($id)),
    fn () => $ctx->step->run('orders', fn () => $orders->forUser($id)),
);

// Named arguments give string keys
$scores = $ctx->step->parallel(
    blue: fn () => $ctx->step->run('blue', fn () => 50),
    red: fn () => $ctx->step->run('red', fn () => 25),
);

// Branches can run several steps in a row
[$total, $fruits] = $ctx->step->parallel(
    function () use ($ctx) {
        $a = $ctx->step->run('a', fn () => 1);
        return $a + $ctx->step->run('b', fn () => 2);
    },
    fn () => $ctx->step->run('fruits', fn () => ['apple', 'banana']),
);

// Like Promise.race: the first branch to finish wins
$winner = $ctx->step->race(
    fn () => $ctx->step->run('fast-api', fn () => $fast->get()),
    fn () => $ctx->step->run('slow-api', fn () => $slow->get()),
);
```

Steps in different branches run as separate Inngest steps, possibly at
the same time on different requests. If a branch throws, `parallel()`
rethrows that error. Catch it in the branch if the others should carry
on.

## Triggers and flow control

All function settings live in `FunctionOptions`:

```php
use DealNews\Inngest\Functions\FunctionOptions;
use DealNews\Inngest\Functions\Options\Cancellation;
use DealNews\Inngest\Functions\Options\Concurrency;
use DealNews\Inngest\Functions\Options\Debounce;
use DealNews\Inngest\Functions\Options\Throttle;
use DealNews\Inngest\Functions\Trigger\CronTrigger;
use DealNews\Inngest\Functions\Trigger\EventTrigger;

new FunctionOptions(
    'sync-account',
    name: 'Sync account',
    triggers: [
        new EventTrigger('account/updated', if: 'event.data.plan != "free"'),
        new CronTrigger('0 */6 * * *', jitter: '10m'),
    ],
    concurrency: new Concurrency(5, key: 'event.data.account_id'),
    throttle: new Throttle(10, '1m', key: 'event.data.account_id'),
    debounce: new Debounce('30s', key: 'event.data.account_id'),
    cancel_on: [
        new Cancellation('account/deleted', match: 'data.account_id'),
    ],
    retries: 5,
);
```

| Option | Class / type | What it does |
|---|---|---|
| `triggers` | `EventTrigger`, `CronTrigger` | Events (optional CEL `if`) and schedules |
| `concurrency` | `int`, `Concurrency`, or up to 2 `Concurrency` | Limit runs in progress (per key, per scope) |
| `throttle` | `Throttle(limit, period, key, burst)` | Queue runs to a rate |
| `rate_limit` | `RateLimit(limit, period, key)` | Drop runs over a rate |
| `debounce` | `Debounce(period, key, timeout)` | Wait for events to settle |
| `batch_events` | `BatchEvents(max_size, timeout, key, if)` | Run once for many events (`$ctx->events`) |
| `priority` | `Priority(run: 'expression')` | Run some events sooner |
| `singleton` | `Singleton(SingletonMode::Skip\|Cancel, key)` | Only one run at a time |
| `timeouts` | `Timeouts(start, finish)` | Cancel runs that wait or run too long |
| `idempotency` | CEL `string` | Ignore repeat events for 24h |
| `cancel_on` | list of `Cancellation(event, if, match, timeout)` | Cancel runs when an event arrives |
| `retries` | `int` 0–20 (default 3) | Retries per step |
| `on_failure` | `callable(Context)` | Runs after the function fails for good |
| `checkpointing` | `bool` or `Checkpointing` | See [Checkpointing](#checkpointing) |
| `middleware` | list | See [Middleware](#middleware) |

Invalid options throw `InvalidArgumentException` when you build
`FunctionOptions`. See the
[Inngest docs](https://www.inngest.com/docs/guides/flow-control) for what
each setting does on the server.

## Errors and retries

A step that throws is retried (default: 3 retries, so 4 attempts). Once
its retries are used up, the step **fails**, and calling it throws a
`StepError` you can catch:

```php
use DealNews\Inngest\Error\StepError;

try {
    $ctx->step->run('charge', fn () => $stripe->charge($order));
} catch (StepError $e) {
    $ctx->step->run('notify-failure', fn () => $mailer->paymentFailed($e));
}
```

An uncaught `StepError` fails the run. Other errors thrown by the
handler retry the run, just like a failed step. Control retries with:

```php
use DealNews\Inngest\Error\NonRetriableError;
use DealNews\Inngest\Error\RetryAfterError;

throw new NonRetriableError('Account was deleted');   // no more retries
throw new RetryAfterError('Rate limited', '30s');     // retry after a delay
throw new RetryAfterError('Quota', new DateTimeImmutable('tomorrow'));
```

Run code after a function fails for good with `on_failure`:

```php
new FunctionOptions(
    'import-file',
    triggers: [new EventTrigger('file/uploaded')],
    on_failure: function (Context $ctx): void {
        // $ctx->event is the "inngest/function.failed" event;
        // $ctx->event->data['event'] is the original one
        $ctx->logger->error('Import failed', [
            'error' => $ctx->error->getMessage(),
        ]);
    },
);
```

Errors that come back from Inngest (step errors, `$ctx->error`) are
`RemoteError`s. `getErrorName()` holds the original class name, since PHP
can't rebuild arbitrary exception classes. `NonRetriableError` keeps its
class. Causes use PHP's `$previous`.

## Checkpointing

With checkpointing (on by default, like JS v4), a request doesn't end
after one step. The SDK runs the step, saves its result to Inngest's API,
and keeps going until it hits something that has to wait (a sleep, a
wait, parallel steps) or the time limit. Fewer round trips, same
durability.

```php
use DealNews\Inngest\Config\Checkpointing;

new Client('my-app', new ClientOptions(
    checkpointing: new Checkpointing(
        max_runtime: '10s',   // default 10s; hand back to Inngest after this
        buffered_steps: 1,    // steps per checkpoint call (default 1)
        max_interval: '2s',   // also flush buffered steps this often
    ),
));

// Per function; false turns it off
new FunctionOptions(
    'legacy',
    triggers: [new EventTrigger('legacy/run')],
    checkpointing: false,
);
```

Keep `max_runtime` well under your PHP and web server timeouts
(`max_execution_time`, FPM `request_terminate_timeout`, proxy timeouts).
PHP can't interrupt running code, so the limit is checked between steps.

## Middleware

Middleware hooks into runs, steps, and event sends. Extend
`Middleware\Middleware` and override what you need:

```php
use DealNews\Inngest\Middleware\Middleware;
use DealNews\Inngest\Middleware\StepErrorArgs;
use DealNews\Inngest\Middleware\WrapStepArgs;

class TimingMiddleware extends Middleware {
    public function wrapStepHandler(WrapStepArgs $args): mixed {
        $start = microtime(true);
        try {
            return $args->next();
        } finally {
            $this->client->getLogger()->info('step took', [
                'step' => $args->step_info->options->id,
                'ms'   => (microtime(true) - $start) * 1000,
            ]);
        }
    }

    public function onStepError(StepErrorArgs $args): void {
        // report to your error tracker
    }
}
```

Register a class name, or a closure that builds an instance (for
middleware that needs configuring):

```php
use DealNews\Inngest\Middleware\DependencyInjection;

$inngest = new Client('my-app', new ClientOptions(
    middleware: [
        TimingMiddleware::class,
        fn (Client $client) => new DependencyInjection($client, [
            'db' => $db,
        ]),
    ],
));

// In a handler: $ctx->extras['db']
```

Function-level middleware goes in `FunctionOptions::$middleware` and runs
after the client's.

Available hooks: `onRegister` (static), `transformFunctionInput`,
`onMemoizationEnd`, `onRunStart`, `wrapFunctionHandler`, `onRunComplete`,
`onRunError`, `transformStepInput`, `wrapStep`, `onStepStart`,
`wrapStepHandler`, `onStepComplete`, `onStepError`, `transformSendEvent`,
`wrapSendEvent`, `wrapRequest`. See the docblocks in
`src/Middleware/Middleware.php`.

Rules worth knowing:

- Each request gets fresh instances, so properties are safe for
  per-request state.
- `on*` and `transform*` hooks run in list order; `wrap*` hooks nest
  (first = outermost).
- Errors in `on*` hooks are logged and ignored; errors in `transform*` /
  `wrap*` hooks fail what they wrap.
- `wrapFunctionHandler` / `wrapStep` only get a value from `next()` when
  the handler or step finishes in this request. Otherwise `next()` never
  returns (see [How execution works](#how-execution-works)).

The built-in logger middleware is always on. It makes `$ctx->logger`
quiet while old steps replay, so each line is logged once per run, and
it adds `runID` and `eventName` to every record.

## Serving from a framework

`ServeHandler::handle()` takes a `Http\Request` and returns a
`Http\Response`. Build the request however your framework likes:

```php
use DealNews\Inngest\Http\Request;
use DealNews\Inngest\Http\ServeHandler;

// Plain PHP (honors X-Forwarded-Proto)
$response = $handler->handle(Request::fromGlobals());
$response->send();

// Any PSR-7 framework (Slim, Mezzio, ...)
$response = $handler->handle(Request::fromPsr7($psr_request));
return $response->toPsr7();

// Laravel
Route::any('/api/inngest', function (\Illuminate\Http\Request $req) use ($handler) {
    $res = $handler->handle(new Request(
        $req->method(),
        $req->fullUrl(),
        $req->headers->all(),
        $req->getContent(),
    ));

    return response($res->body, $res->status, $res->headers);
});
```

Accept `GET`, `PUT`, and `POST` on the route, and keep the raw body as
is. Signatures are checked against the exact bytes. Exclude the route
from CSRF protection.

Behind a proxy, or when the URL PHP sees isn't the public one, set
`serve_origin` / `serve_path` (or `INNGEST_SERVE_ORIGIN` /
`INNGEST_SERVE_PATH`), so Inngest gets the right URL when you sync.

## Configuration

Settings come from `ClientOptions`, then `INNGEST_*` environment variables,
then defaults, resolved once when the client is built. `null` in
`ClientOptions` means "not set".

```php
$inngest = new Client(
    'my-app',
    new ClientOptions(signing_key: $secrets['inngest_signing_key']),
    logger: $psr3_logger,        // default: NullLogger
    http_client: $guzzle,        // default: new GuzzleHttp\Client
);
```

| `ClientOptions` | Env var | Default | Purpose |
|---|---|---|---|
| `is_dev` | `INNGEST_DEV` | cloud mode | `1`/`true` for dev mode; a URL also sets the dev server address |
| `signing_key` | `INNGEST_SIGNING_KEY` | — | Verifies requests, signs responses, API auth (required in cloud mode) |
| `signing_key_fallback` | `INNGEST_SIGNING_KEY_FALLBACK` | — | Old key during rotation |
| `event_key` | `INNGEST_EVENT_KEY` | — | Sending events (required in cloud mode) |
| `env` | `INNGEST_ENV` | — | Branch environment name |
| `base_url` | `INNGEST_BASE_URL` | per mode | Base URL for the API and the event API (self-hosting) |
| `api_base_url` | `INNGEST_API_BASE_URL` | `https://api.inngest.com/` | API only; wins over `base_url` |
| `event_api_base_url` | `INNGEST_EVENT_API_BASE_URL` | `https://inn.gs/` | Event API only; wins over `base_url` |
| `serve_origin` | `INNGEST_SERVE_ORIGIN` | from the request | Public origin of your serve URL |
| `serve_path` | `INNGEST_SERVE_PATH` | from the request | Public path of your serve URL |
| `enable_unauthed_sync` | `INNGEST_ENABLE_UNAUTHED_SYNC` | `true` | Accept unsigned sync requests |
| `allow_in_band_sync` | `INNGEST_ALLOW_IN_BAND_SYNC` | `true` | Allow in-band syncs |
| `app_version` | — | — | Your app's version (e.g. git SHA), shown in Inngest |
| `checkpointing` | — | `true` | See [Checkpointing](#checkpointing) |
| `middleware` | — | `[]` | See [Middleware](#middleware) |

In dev mode both URLs default to `http://localhost:8288/`.
`INNGEST_SERVE_HOST` still works as a deprecated alias for
`INNGEST_SERVE_ORIGIN`.

## How execution works

Each time Inngest calls your app, the SDK runs your handler from the top
inside a PHP [Fiber](https://www.php.net/manual/en/language.fibers.php):

1. A step that already ran returns its saved result (or throws its saved
   `StepError`).
2. When the handler reaches a step that hasn't run, the fiber pauses. The
   SDK then runs that step now, or tells Inngest which steps it found.
3. The paused fiber is never resumed; the request ends. On the next
   request the handler replays from the top.

Things that follow from this:

- **Make code between steps deterministic.** Don't base step IDs or
  branching on `time()`, `rand()`, or database reads done outside a step.
- **`finally` blocks run when a paused fiber is cleaned up**, once per
  request. `catch` blocks don't. Don't put side effects in `finally`
  around steps.
- **Don't call step tools inside `step->run()`.** That step fails for
  good with a `NonRetriableError` (JS logs a warning and hangs).
- **Don't call step tools from your own fibers.** Use `parallel()`.

## Differences from the JS SDK

The wire protocol matches. These differ on purpose:

| Area | JS SDK | This SDK |
|---|---|---|
| Parallelism | `Promise.all` / `Promise.race` on steps | `step->parallel()` / `step->race()` |
| Step options | option objects | named parameters (`timeout:`, `match:`, ...) |
| Durations | `ms()` strings; numbers are ms | Same, plus `"1h30m"` compound strings; bad strings throw (JS: silent zero) |
| Errors from Inngest | rebuilt with original name | `RemoteError` with `getErrorName()`; `NonRetriableError` keeps its class |
| Nested step tools | warning, then hangs | step fails with `NonRetriableError` |
| Missing requested step | waits 10s | answers `step-not-found` immediately |
| Config | read per request | resolved once when the client is built; serve settings live in `ClientOptions` |
| SDK identity | `js:v4.x` | `dealnews/inngest-php-sdk:v<version>` |
| Logger | `flush()`, `child()` loggers | any PSR-3 logger, always gets `runID`/`eventName` context |

Not included:

- **Out of scope:** Connect (WebSocket workers), Realtime, framework
  adapters, platform detection, streaming responses, Durable Endpoints,
  OpenTelemetry, event schema validation (validate in your handler and
  throw `NonRetriableError`), `optimizeParallelism: false`.
- **Not yet:** `defer()`, `group.experiment`, step/run metadata,
  `step.score`, session propagation.

## Testing

```bash
composer install
composer test    # lint + unit tests (100% line coverage)
composer lint    # parallel-lint only
composer fix     # php-cs-fixer
```

Wire parity is checked against the real JS SDK: generators in
`tests/fixtures/*/generate.mts` run the inngest-js sources and save their
output, and the parity tests replay every case through the PHP code (see
`tests/fixtures/js/README.md`).

End-to-end tests run real functions on real Inngest servers in Docker:

```bash
tests/functional/run.sh
```

See [tests/functional/README.md](tests/functional/README.md).

To test your own functions end to end, run the dev server, send events,
and check the runs, as the functional tests do (`tests/functional/
DevServer.php` shows how to query runs over the dev server's API).

## Troubleshooting

**`500` with "no signing key" / every request is `401`.** You're in
cloud mode. Locally, set `INNGEST_DEV=1`. In production, check
`INNGEST_SIGNING_KEY` and make sure nothing (middleware, body parsers,
proxies) changes the request body.

**The dev server can't reach my app.** If the dev server runs in Docker,
`localhost` is the container itself. Use
`http://host.docker.internal:<port>/...` as the app URL, and set
`serve_origin` to match.

**A step ran twice / code ran more than once.** Code outside steps runs
on every request by design. Move side effects into `step->run()`.

**Requests time out.** Lower `Checkpointing::$max_runtime`, or move slow
HTTP calls to `step->fetch()` / `step->ai->infer()`, so Inngest waits
instead of PHP.

**`{}` came back as `[]`.** JSON objects decode to PHP arrays, so empty
ones look like empty lists.

## Contributing

Bug reports and pull requests are welcome on
[GitHub](https://github.com/dealnews/inngest-php-sdk). For changes that
touch the wire format, compare against the JS SDK (the fixture generators
make that easy), and keep `composer test` green with full coverage.

### Releasing

1. Move the changes under a version heading in
   [CHANGELOG.md](CHANGELOG.md) and update the link at the bottom.
2. Tag the release commit with the bare version (no `v`, matching the
   existing tags), and push the tag:

   ```bash
   git tag 1.0.0
   git push origin 1.0.0
   ```

There's no version constant to bump. The SDK reads its own version from
Composer's installed-package data (`Composer\InstalledVersions`), so the
tag is the version users see in `X-Inngest-SDK`
(`dealnews/inngest-php-sdk:v1.0.0`). A git checkout without a tag reports
the branch (e.g. `dev-main`).

## License

BSD 3-Clause. See [LICENSE](LICENSE).

## Credits

Built by [DealNews](https://www.dealnews.com). A port of the official
[Inngest JS SDK](https://github.com/inngest/inngest-js). This is not an
official Inngest product.
