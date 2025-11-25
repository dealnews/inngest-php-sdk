# JSON Canonicalization in Signature Verification

## Overview

The PHP SDK implements JSON Canonicalization Scheme (JCS) per RFC 8785 for request signature verification. This ensures that requests with differently formatted JSON (different whitespace, key ordering, etc.) produce consistent signatures.

## Why JCS Matters

When Inngest sends requests to your SDK endpoint, the signature is calculated on the request body. Without canonicalization, these two JSON bodies would produce different signatures:

```json
{"foo":"bar","baz":123}
```

```json
{
  "baz": 123,
  "foo": "bar"
}
```

Even though they're semantically identical, the raw bytes differ. JCS solves this by normalizing JSON to a standard format before hashing.

## Implementation Details

### Location

The canonicalization logic is in `src/Http/SignatureVerifier.php`:

```php
protected function canonicalizeBody(string $body): string
{
    if (empty($body)) {
        return $body;
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return $this->canonicalizer->canonicalize($decoded);
    } catch (\Throwable $e) {
        return $body;
    }
}
```

### How It Works

1. **Attempt to parse** - Try to decode the body as JSON
2. **Canonicalize** - If valid JSON, apply RFC 8785 canonicalization rules:
   - Sort object keys lexicographically by UTF-16 codepoints
   - Remove all insignificant whitespace
   - Use consistent number formatting (ES6 rules)
   - No escaping of unicode characters
3. **Fallback** - If parsing fails, use the raw body (for non-JSON payloads)

### RFC 8785 Rules Applied

The `root23/php-json-canonicalization` library implements these RFC 8785 rules:

- **Object keys**: Sorted lexicographically by UTF-16BE encoding
- **Whitespace**: Removed entirely (no spaces, tabs, newlines)
- **Numbers**: ES6 number formatting (no leading zeros, etc.)
- **Strings**: UTF-8 encoding with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`
- **Arrays**: Order preserved (not sorted)

## Compatibility with Other SDKs

### Python SDK

The Python SDK uses the `jcs` library:

```python
# transforms.py
def canonicalize(value: bytes) -> types.MaybeError[bytes]:
    try:
        loaded = json.loads(value)
        value_jcs = jcs.canonicalize(loaded)
        return value_jcs
    except Exception as err:
        return Exception("failed to canonicalize: " + str(err))
```

### JavaScript SDK

The JavaScript SDK uses the `canonicalize` library:

```javascript
// net.ts
const encoded = typeof data === "string" ? data : canonicalize(data);
```

All three implementations follow RFC 8785, ensuring cross-SDK compatibility.

## Testing

The test suite verifies that different JSON formats produce identical signatures:

```php
public function testJsonCanonicalizationProducesSameSignature(): void
{
    $body1 = '{"foo":"bar","baz":123}';
    $body2 = '{"baz": 123, "foo": "bar"}';
    $body3 = "{\"baz\":123,\"foo\":\"bar\"}";

    $signature1 = $verifier->signRequest($body1, self::SIGNING_KEY);
    $signature2 = $verifier->signRequest($body2, self::SIGNING_KEY);
    $signature3 = $verifier->signRequest($body3, self::SIGNING_KEY);

    // All three should produce the same signature
    $this->assertEquals($parts1['s'], $parts2['s']);
    $this->assertEquals($parts1['s'], $parts3['s']);
}
```

## Edge Cases

### Empty Bodies

Empty bodies are returned as-is without canonicalization:

```php
if (empty($body)) {
    return $body;
}
```

### Non-JSON Bodies

If the body isn't valid JSON, it's used as-is:

```php
try {
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    return $this->canonicalizer->canonicalize($decoded);
} catch (\Throwable $e) {
    return $body; // Use raw body if not valid JSON
}
```

### Deeply Nested Objects

The canonicalizer handles arbitrarily nested objects and arrays recursively.

## Performance Considerations

Canonicalization adds minimal overhead:

1. **Parse**: `json_decode()` - O(n) where n is body size
2. **Sort keys**: O(k log k) where k is number of keys at each level
3. **Serialize**: O(n) to rebuild the JSON string

For typical request sizes (< 100KB), this adds < 1ms of latency.

## Debugging Signature Mismatches

If you're getting signature validation errors:

1. **Check the raw body** - Ensure you're signing the exact bytes received
2. **Verify canonicalization** - Both sides must canonicalize (or both must not)
3. **Log the canonical form**:
   ```php
   $canonical = $this->canonicalizeBody($body);
   error_log("Canonical body: " . $canonical);
   ```
4. **Compare with Inngest** - Use the Inngest dev server to see what signature they expect

## References

- [RFC 8785 - JSON Canonicalization Scheme (JCS)](https://datatracker.ietf.org/doc/html/rfc8785)
- [Inngest SDK Spec - Section 4.1.3](https://github.com/inngest/inngest/blob/main/docs/SDK_SPEC.md#413-requirements-when-receiving-requests)
- [root23/php-json-canonicalization on GitHub](https://github.com/root23/php-json-canonicalization)
