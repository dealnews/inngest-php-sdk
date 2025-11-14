# Concurrency Feature Implementation

## Overview

The concurrency feature allows developers to control how many steps can run simultaneously across all runs of an Inngest function. This is essential for:
- Rate-limiting calls to external APIs
- Managing resource consumption
- Preventing overwhelming downstream services
- Ensuring fair usage across users/regions

## Implementation Details

### Files Added

1. **src/Function/Concurrency.php**
   - New class representing concurrency configuration
   - Validates limit (must be >= 0), key (optional expression), and scope (fn/env/account)
   - Provides `toArray()` method for serialization in sync payload

2. **tests/Unit/ConcurrencyTest.php**
   - Comprehensive tests for Concurrency class
   - Tests validation, serialization, and edge cases

3. **tests/Unit/InngestFunctionTest.php**
   - Tests for InngestFunction with concurrency configurations
   - Tests validation (max 2 concurrency configs, type checking)

4. **examples/concurrency.php**
   - Working examples demonstrating various concurrency scenarios
   - Shows simple limits, per-user limits, multi-level limits, and complex expressions

### Files Modified

1. **src/Function/InngestFunction.php**
   - Added `$concurrency` parameter to constructor (optional array of Concurrency objects)
   - Added validation: max 2 concurrency configs, must be Concurrency instances
   - Added `getConcurrency()` method
   - Updated `toArray()` to include concurrency in sync payload (only if non-empty)

2. **README.md**
   - Added "Concurrency Control" section with examples
   - Documented all concurrency options (limit, key, scope)
   - Added link to example file

3. **QUICKSTART.md**
   - Added "Control Concurrency" subsection under "Next Steps"
   - Showed simple and per-user concurrency examples

## SDK Specification Compliance

This implementation follows the [Inngest SDK Spec](https://github.com/inngest/inngest/blob/main/docs/SDK_SPEC.md) section 4.3.2 (Syncing), which defines concurrency as:

```typescript
concurrency?: number | Array<{
  limit: number;
  key?: string;
  scope?: "fn" | "env" | "account";
}>
```

Our implementation:
- ✅ Supports both single limit (via array with one item) and multiple configurations
- ✅ Validates maximum of 2 concurrency configurations
- ✅ Supports optional `key` for grouping (evaluated as expression on event data)
- ✅ Supports optional `scope` (fn, env, account)
- ✅ Serializes correctly for sync payload
- ✅ Limit of 0 means unlimited concurrency

## Usage Patterns

### Simple Limit
```php
concurrency: [new Concurrency(limit: 10)]
```
Limits to 10 concurrent steps across all runs.

### Per-User/Per-Key
```php
concurrency: [new Concurrency(limit: 5, key: 'event.data.user_id')]
```
Creates separate queues per user, each limited to 5 concurrent steps.

### Multi-Level
```php
concurrency: [
    new Concurrency(limit: 5, key: 'event.data.region', scope: 'fn'),
    new Concurrency(limit: 100, scope: 'account')
]
```
Limits per-region to 5, with overall account limit of 100.

### Complex Keys
```php
key: 'event.data.user_id + "-" + event.data.plan'
```
Groups by composite keys using expressions.

## Testing

All tests pass (42 tests, 122 assertions):
- ✅ Concurrency class validation and serialization
- ✅ InngestFunction integration with concurrency
- ✅ Edge cases (zero limit, empty array, invalid inputs)
- ✅ Existing functionality unchanged

## Design Decisions

1. **Array-only parameter**: Unlike Python SDK which accepts both single value and array, we require an array to keep type hints simple and consistent.

2. **Validation in constructor**: Throws exceptions early for invalid configurations rather than deferring to sync time.

3. **Protected visibility**: Following project standards, all properties use `protected` visibility.

4. **No auto-wrapping**: If user passes `concurrency: null` or `concurrency: []`, we don't include it in sync payload (omitted entirely).

5. **PHPDoc compliance**: All public methods have complete PHPDoc blocks with parameter and return type documentation.

## Comparison with Python SDK

Our implementation mirrors the Python SDK's approach:

**Python:**
```python
concurrency=[
    Concurrency(key="foo", limit=1, scope="account")
]
```

**PHP:**
```php
concurrency: [
    new Concurrency(limit: 1, key: 'foo', scope: 'account')
]
```

Key differences:
- PHP uses constructor with named parameters instead of kwargs
- PHP validates scope in constructor vs Pydantic validation in Python
- Both serialize identically for the sync payload

## Future Enhancements

Potential improvements (not currently implemented):
- Support for simplified syntax: `concurrency: 10` as shorthand for single limit
- Helper methods like `Concurrency::unlimited()` or `Concurrency::perUser(limit, key)`
- Validation against event schema to ensure keys exist
- Runtime warnings if concurrency limits are frequently hit

## References

- SDK Spec: https://github.com/inngest/inngest/blob/main/docs/SDK_SPEC.md
- Python Implementation: `/Users/brian/code/inngest-py/pkg/inngest/inngest/_internal/server_lib/registration.py`
- Example file: `examples/concurrency.php`
