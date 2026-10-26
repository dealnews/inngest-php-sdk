# Changelog

Notable changes to this project. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions
follow [Semantic Versioning](https://semver.org/).

## [1.0.0] - Unreleased

A ground-up rewrite as a port of the official Inngest JS SDK (`inngest`
v4.26.0). It isn't compatible with 0.x: class names, namespaces, and the
function API all changed. See the [README](README.md) for the new API.

### Added

- Wire-level parity with the JS SDK: requests, responses, headers,
  signatures, step ID hashing (with `:N` collision indexing), op codes,
  and retry rules. Checked against output generated from the JS SDK's own
  sources, and against real Inngest servers.
- `Client`: config from `ClientOptions`, then `INNGEST_*` env vars, then
  defaults; dev/cloud mode; signing key rotation (fallback key); event
  sending with retries; `sendSignal()`; optional PSR-3 logger and Guzzle
  client.
- Functions: event triggers (with `if`) and cron triggers (with
  `jitter`), concurrency, throttle, rate limit, debounce, batching,
  priority, singleton, timeouts, idempotency, `cancel_on`, retries, and
  `on_failure` handlers.
- `ServeHandler`: introspection, out-of-band and in-band sync, trust
  probes, signature checks, signed responses, and function execution.
  Works with plain PHP (`Request::fromGlobals()`) or any PSR-7 framework.
- Step tools: `run`, `sleep`, `sleepUntil`, `waitForEvent`, `invoke`,
  `sendEvent`, `waitForSignal`, `sendSignal`, `fetch`, `ai->infer`,
  `ai->wrap`.
- `step->parallel()` (like `Promise.all`) and `step->race()`, built on
  PHP Fibers.
- Async checkpointing, on by default like JS v4, with `max_runtime`,
  `buffered_steps`, and `max_interval` settings.
- Middleware with the JS v4 hook set, a built-in logger middleware
  (`$ctx->logger` stays quiet during replay), and `DependencyInjection`.
- Errors: `NonRetriableError`, `RetryAfterError`, `StepError`, and
  `RemoteError` for errors rebuilt from the wire.
- Functional test suite against real Inngest servers in Docker
  (`tests/functional/run.sh`).

### Changed

- Requires PHP 8.4 or later.
- The SDK identifies itself as `dealnews/inngest-php-sdk:v<version>`, with
  the version read from Composer's installed-package data instead of a
  constant.

### Removed

- The 0.x API and everything not ported (see "Differences from the JS
  SDK" in the README): Connect, Realtime, framework adapters, streaming,
  Durable Endpoints, OpenTelemetry, and event schema validation.

[1.0.0]: https://github.com/dealnews/inngest-php-sdk/releases/tag/1.0.0
