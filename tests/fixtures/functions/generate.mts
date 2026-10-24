/**
 * Dumps function configs (`getConfig()` output) from the JS SDK for a set
 * of function definitions. FunctionConfigTest compares PHP output against
 * the result. See ../js/README.md for how to run it.
 */
const root = process.argv[2];
const { Inngest } = await import(`${root}/src/components/Inngest.ts`);

const noop = () => null;
const app = new Inngest({ id: "testclient" });

const cases: Record<string, { opts: any; failure?: boolean; baseUrl?: string }> = {
  basic: { opts: { id: "testfn", triggers: [{ event: "foo" }] } },
  name_and_description: {
    opts: { id: "testfn", name: "Test Function", description: "Does things", triggers: [{ event: "foo" }] },
  },
  triggers: {
    opts: {
      id: "triggers",
      triggers: [
        { event: "app/user.created", if: "event.data.plan == 'pro'" },
        { cron: "0 * * * *" },
        { cron: "TZ=Europe/Paris 0 12 * * 5", jitter: "30s" },
        { event: "inngest/function.invoked" },
      ],
    },
  },
  no_triggers: { opts: { id: "invoke-only" } },
  flow_control: {
    opts: {
      id: "flow",
      triggers: [{ event: "foo" }],
      concurrency: 5,
      idempotency: "event.data.id",
      batchEvents: { maxSize: 100, timeout: "5s", key: "event.data.user", if: "event.data.ok" },
      rateLimit: { key: "event.data.user", limit: 10, period: "1m" },
      throttle: { key: "event.data.user", limit: 3, period: "10s", burst: 2 },
      debounce: { key: "event.data.user", period: "5m", timeout: "1h" },
      priority: { run: "event.data.priority" },
      timeouts: { start: "10m", finish: "1h" },
      singleton: { key: "event.data.user", mode: "cancel" },
    },
  },
  flow_control_minimal: {
    opts: {
      id: "flow-min",
      triggers: [{ event: "foo" }],
      batchEvents: { maxSize: 10, timeout: "1s" },
      rateLimit: { limit: 1, period: "1h" },
      throttle: { limit: 1, period: "1s" },
      debounce: { period: "1s" },
      priority: {},
      timeouts: { finish: "30s" },
      singleton: { mode: "skip" },
    },
  },
  concurrency_object: {
    opts: { id: "conc-obj", triggers: [{ event: "foo" }], concurrency: { limit: 2, key: "event.data.user", scope: "env" } },
  },
  concurrency_array: {
    opts: {
      id: "conc-arr",
      triggers: [{ event: "foo" }],
      concurrency: [{ limit: 10, scope: "account", key: '"openai"' }, { limit: 1, key: "event.data.user" }],
    },
  },
  retries_zero: { opts: { id: "r0", triggers: [{ event: "foo" }], retries: 0 } },
  retries_five: { opts: { id: "r5", triggers: [{ event: "foo" }], retries: 5 } },
  cancel_on: {
    opts: {
      id: "cancel",
      triggers: [{ event: "foo" }],
      cancelOn: [
        { event: "baz", match: "data.title" },
        { event: "qux", if: "async.data.id == event.data.id" },
        { event: "with.timeout.string", timeout: "1h" },
        { event: "with.timeout.ms", timeout: 90000 },
        { event: "with.timeout.date", timeout: new Date("2030-01-02T03:04:05Z") },
        { event: "match.beats.if", match: "data.a", if: "ignored" },
      ],
    },
  },
  on_failure: {
    opts: { id: "testfn", name: "Pretty", triggers: [{ event: "foo" }], retries: 2, onFailure: noop },
  },
  on_failure_no_name: {
    opts: { id: "testfn", triggers: [{ event: "foo" }], onFailure: noop },
  },
  base_url_with_path_and_query: {
    opts: { id: "pathy", triggers: [{ event: "foo" }], onFailure: noop },
    baseUrl: "https://example.com/api/inngest?foo=bar&fnId=old&x=a+b",
  },
  id_encoding: {
    opts: { id: "my fn*~'()!/x", triggers: [{ event: "foo" }] },
  },
};

const out: Record<string, unknown> = {};
for (const [name, { opts, baseUrl }] of Object.entries(cases)) {
  const fn = app.createFunction(opts, noop);
  out[name] = {
    opts: Object.fromEntries(
      Object.entries(opts).map(([k, v]) => [k, typeof v === "function" ? "<fn>" : v]),
    ),
    baseUrl: baseUrl ?? "https://example.com",
    configs: fn["getConfig"]({ baseUrl: new URL(baseUrl ?? "https://example.com"), appPrefix: "testclient" }),
  };
}

console.log(JSON.stringify(out, null, 2));
