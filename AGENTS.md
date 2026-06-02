# Inngest PHP SDK

## Project Overview

An unofficial PHP SDK for [Inngest](https://www.inngest.com) — a platform for building event-driven workflows with durable execution, step functions, and automatic retries. Follows the [official Inngest SDK Specification](https://github.com/inngest/inngest/blob/main/docs/SDK_SPEC.md).

## Key Commands

- Install: `composer install`
- Lint: `vendor/bin/phpcs`
- Fix: `vendor/bin/phpcbf`
- Test all: `vendor/bin/phpunit`
- Test single file: `vendor/bin/phpunit path/to/TestFile.php`

## Project Structure

```
src/
├── Client/       # Inngest client — event sending, function registration
├── Config/       # Configuration — env vars, dev/prod mode, API URLs
├── Error/        # Exception hierarchy (NonRetriableError, RetryAfterError, StepError)
├── Event/        # Event model and serialization
├── Function/     # InngestFunction, triggers, concurrency, checkpoint, and related config
├── Http/         # ServeHandler, signature verification, header constants
├── Middleware/   # AbstractMiddleware base class
└── Step/         # Step execution engine, memoization, AiStep
tests/Unit/       # PHPUnit tests
examples/         # Working examples for each feature
```

## Code Style

- 1TBS bracing style
- snake_case variables
- Protected visibility by default
- Single return point preference
- Class-based API (no bare functions)
- Dependency injection is handled by optional parameters passed to class constructors (unless specified, otherwise)
- Complete PHPDoc coverage

## Non-Obvious Patterns

- **Steps use PHP Fibers.** `_executeStep()` in `Step.php` calls `Fiber::suspend()` to pause execution and report a step to Inngest. The fiber is resumed by `ServeHandler` with memoized data on subsequent requests.
- **Step IDs are SHA1-hashed.** Duplicate step IDs within a function get suffixed (`:1`, `:2`, etc.) before hashing. The first occurrence has no suffix.
- **`step->ai` is a magic property**, not a method call. Access it as `$step->ai->infer(...)`. Implemented via `__get()` in `Step.php`.
- **`sendEvent()` uses `StepRun` opcode**, not a native `SendEvent` opcode (which doesn't exist in the spec). It wraps `$this->run()` and requires `setSendCallback()` to be called first — wired in `ServeHandler`.
- **`AIGateway` opcode has a capital I** — the correct wire value is `"AIGateway"`, not `"AiGateway"`. This matches the Inngest spec enum value.
- **CEL expressions in `waitForEvent`:** `event` refers to the original trigger event; `async` refers to the newly arrived event. Step results are not accessible in CEL — validate those in PHP code after the wait resolves.
- **Checkpoint config is a `Checkpoint` object**, not a boolean. Pass `new Checkpoint()` or use the static presets (`Checkpoint::safe()`, `Checkpoint::performant()`, `Checkpoint::blended()`).
- **Dev mode event key fallback.** When `INNGEST_EVENT_KEY` is not set and dev mode is active, `'local'` is used as the event key. Production mode throws if the key is missing.
- **`AIGateway` (AI inference) requires Inngest Cloud.** The `AIGateway` opcode routes through Inngest's cloud infrastructure, which makes the actual HTTP call to the AI provider. It does not work with the local dev server's test signing key.

## Workflow

- All tests must pass after code changes — run `vendor/bin/phpunit` to verify.
- Run `vendor/bin/phpcbf` at the end of code changes to apply lint fixes.

## Key Files

- `src/Http/ServeHandler.php` — handles all HTTP requests (sync, call, introspection); wires step context and send callback
- `src/Step/Step.php` — step execution engine; `_executeStep()` is the core method
- `src/Client/Inngest.php` — main client; event sending and function registration
- `src/Function/InngestFunction.php` — function definition and serialization to Inngest API format
- `src/Config/Config.php` — reads all env vars; controls dev/prod mode and API URLs
