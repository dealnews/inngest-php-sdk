/**
 * Generates js-golden.json from the official Inngest JS SDK's hash.js
 * usage, so PHP signing/hashing can be checked against real JS output.
 *
 * Usage (from this directory):
 *   node generate.cjs /path/to/inngest-js/packages/inngest > js-golden.json
 */
const path = require("path");
const base = process.argv[2];
const hashjs = require(require.resolve("hash.js", { paths: [base] }));
const { hmac, sha256 } = hashjs;
const strip = (k) => k.replace(/^signkey-[\w]+-/, "");
const sign = (body, key, ts) =>
  hmac(sha256, strip(key)).update(body).update(ts).digest("hex");
const hashSigningKey = (k) => {
  if (!k) return "";
  const prefix = k.match(/^signkey-[\w]+-/)?.shift() || "";
  return prefix + sha256().update(strip(k), "hex").digest("hex");
};
const hashEventKey = (k) => sha256().update(k).digest("hex");
const out = {
  sign: [
    ["hello world", "signkey-test-abc123", "1234567890"],
    ["", "signkey-test-abc123", "1234567890"],
    ["Hello 世界 🌍", "signkey-test-abc123", "1234567890"],
    ['{"event":{"name":"test"}}', "signkey-test-12345", "1700000000"],
    ["data", "signkey-prod-mykey", "123"],
    ["data", "mykey", "123"],
  ].map(([b, k, t]) => ({ body: b, key: k, ts: t, mac: sign(b, k, t) })),
  hashSigningKey: [
    "signkey-test-12345",
    "signkey-prod-0123456789abcdef",
    "signkey-branch-abc",
    "0123456789abcdef",
    "signkey-test-abc",
    "signkey-test-xyz1",
    "signkey-test-1g2h",
    "signkey-test-g1",
  ].map((k) => ({ key: k, hash: hashSigningKey(k) })),
  hashEventKey: ["test-event-key", "Ünïcødé"].map((k) => ({ key: k, hash: hashEventKey(k) })),
};
console.log(JSON.stringify(out, null, 2));
