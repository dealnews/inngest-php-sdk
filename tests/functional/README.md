# Functional tests

End-to-end tests that run real functions on real Inngest servers. The unit
suite (`composer test`) proves we send the same bytes as the JS SDK; this
suite proves the Inngest executor agrees.

They're in the `functional` PHPUnit group, which `composer test` skips.

## Running

You need Docker with the compose plugin, plus a `composer install`
(the containers use your checkout's `vendor/`).

```bash
tests/functional/run.sh                      # start stack, test, tear down
tests/functional/run.sh --filter SignedTest  # extra args go to PHPUnit
KEEP=1 tests/functional/run.sh               # leave the stack running
```

With `KEEP=1` the dev server UI stays up at <http://localhost:8288>
(self-hosted server at <http://localhost:8290>), handy for poking at runs.
`FUNCTIONAL_DEV_PORT` / `FUNCTIONAL_CLOUD_PORT` move those ports.

## What's in the stack

`docker-compose.yml` starts:

| Service | What it is |
|---|---|
| `dev` | `inngest dev` (pinned image), syncs `app` on start with `-u` |
| `selfhosted` | `inngest start --signing-key ...`; signs every request (cloud mode) |
| `app` | `app/server.php` under `php -S` (8 workers), dev mode |
| `app-cloud` | the same app in cloud mode, pointed at `selfhosted` |
| `phpunit` | runs `--group functional` against all of the above |

The app is plain PHP wiring, the way a user without a framework would do
it: `TestApp` builds a `Client`, creates the functions from
`DevFunctions` / `CloudFunctions`, and hands each request to
`ServeHandler`.

## How the tests see inside runs

- **Dev server API** (`DevServer`): the PHP port of the JS SDK's test
  helpers. It finds a run from an event ID, waits for it to end, walks
  its trace spans, and reads span outputs over `/v0/gql`.
- **State store** (`StateStore`): the JS tests keep run state in memory
  because the app runs in the test process. Ours runs in another
  container, so functions append what they saw (attempt numbers, request
  counts, log lines) to JSON files in a shared Docker volume, keyed by
  run ID. `TestApp` also records every op it returns (`ops-<run id>`),
  every sync, and every request.

## Things that look odd but are on purpose

- **Twin functions.** This dev server version leaves checkpointed steps
  out of run traces (the JS SDK gets the exact same traces; we checked
  side by side). Checkpointing is on by default, so each example function
  is registered twice by `DevFunctions::twin()`: as-is and as `<id>-nocp`
  with checkpointing off. Both copies listen to the same event, so one
  send starts both runs. Tests check the output of both runs and the step
  spans of the `-nocp` run.
- **No `stepType` checks.** The JS helpers match `stepType: "RUN"`; this
  server leaves it empty, so spans are matched by name and status.
- **Attempts.** A step that fails twice shows `attempts: 2` with one
  child span per attempt (the JS tests expect `attempts: 1`, from an
  older server).

## Adding a scenario

1. Add the function to `app/DevFunctions.php` (or `CloudFunctions.php`).
   Use a unique event name. Record anything the test needs in the state
   store, keyed by `$ctx->run_id`.
2. Add a test that extends `FunctionalTestCase` with
   `#[CoversNothing]` and `#[Group('functional')]`. Use `start()` (or
   `startTwins()`), then `assertRunCompletes()` / `assertRunFails()` /
   `assertSpan()`.
3. The function count check picks up new functions by itself.
