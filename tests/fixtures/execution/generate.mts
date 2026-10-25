/**
 * Dumps execution responses (POST requests that run functions) from the JS
 * SDK. Every case runs in dev mode without `ctx.fn_id`, so async
 * checkpointing stays off. EngineParityTest replays each case against the
 * PHP SDK. See ../js/README.md for how to run it.
 */
import { createHash } from "node:crypto";

const root = process.argv[2];

for (const key of Object.keys(process.env)) {
  if (key.startsWith("INNGEST_")) {
    delete process.env[key];
  }
}

const { Inngest } = await import(`${root}/src/components/Inngest.ts`);
const { InngestCommHandler } = await import(
  `${root}/src/components/InngestCommHandler.ts`
);
const { NonRetriableError } = await import(
  `${root}/src/components/NonRetriableError.ts`
);
const { RetryAfterError } = await import(
  `${root}/src/components/RetryAfterError.ts`
);
const { ConsoleLogger } = await import(`${root}/src/middleware/logger.ts`);
const { referenceFunction } = await import(
  `${root}/src/components/InngestFunctionReference.ts`
);

const logger = new ConsoleLogger({ level: "silent" });
const hash = (id: string) => createHash("sha1").update(id).digest("hex");

const apiCalls: string[] = [];
const client = new Inngest({
  id: "test-app",
  isDev: true,
  logger,
  fetch: async (url: string, init?: RequestInit) => {
    const path = new URL(String(url)).pathname;
    apiCalls.push(path);
    if (path.startsWith("/e/")) {
      return new Response(JSON.stringify({ ids: ["sent-1"], status: 200 }));
    }
    if (path === "/v1/signals") {
      const sent = JSON.parse(String(init?.body));
      if (sent.signal === "missing") {
        return new Response("{}", { status: 404 });
      }
      return new Response(JSON.stringify({ data: { run_id: "run-2" } }));
    }
    if (path.endsWith("/batch")) {
      return new Response(
        JSON.stringify([
          { name: "demo/event.sent", data: { n: 1 } },
          { name: "demo/event.sent", data: { n: 2 } },
        ]),
      );
    }
    if (path.endsWith("/actions")) {
      return new Response(JSON.stringify({ [hash("A")]: "from api" }));
    }
    return new Response("{}", { status: 404 });
  },
});

const trigger = { triggers: [{ event: "demo/event.sent" }] };
const until = new Date("2030-01-02T03:04:05.678Z");
const aiModel = {
  url: "https://ai.example.com/v1/chat",
  format: "openai-chat",
  authKey: "sk-1",
  headers: { "x-a": "1" },
};

const invokeTarget = client.createFunction(
  { id: "invoke-target", ...trigger },
  () => "target",
);

const fns = [
  client.createFunction({ id: "no-steps", ...trigger }, ({ event, events }) => ({
    ok: true,
    n: event.data.n,
    count: events.length,
  })),
  client.createFunction({ id: "returns-undefined", ...trigger }, () => undefined),
  client.createFunction({ id: "throws", ...trigger }, () => {
    throw new Error("boom");
  }),
  client.createFunction({ id: "throws-non-retriable", ...trigger }, () => {
    throw new NonRetriableError("nope");
  }),
  client.createFunction({ id: "throws-retry-after", ...trigger }, () => {
    throw new RetryAfterError("later", 5000);
  }),
  client.createFunction({ id: "a-to-b", ...trigger }, async ({ step }) => {
    await step.run("A", () => "A");
    await step.run("B", () => "B");
  }),
  client.createFunction({ id: "returns-step-data", ...trigger }, async ({ step }) => {
    const a = await step.run("A", () => ({ x: 1, nested: { y: [1, 2] } }));
    const b = await step.run("B", () => undefined);
    return { a, b };
  }),
  client.createFunction({ id: "step-throws", ...trigger }, async ({ step }) => {
    await step.run("A", () => {
      throw new Error("step failed");
    });
  }),
  client.createFunction({ id: "step-non-retriable", ...trigger }, async ({ step }) => {
    await step.run("A", () => {
      throw new NonRetriableError("bad step");
    });
  }),
  client.createFunction({ id: "step-retry-after", ...trigger }, async ({ step }) => {
    await step.run("A", () => {
      throw new RetryAfterError("slow down", 2500);
    });
  }),
  client.createFunction({ id: "catches-step-error", ...trigger }, async ({ step }) => {
    try {
      await step.run("A", () => "never");
    } catch (err) {
      return `caught: ${(err as Error).message}`;
    }
    return "not caught";
  }),
  client.createFunction({ id: "rethrows-other-error", ...trigger }, async ({ step }) => {
    try {
      await step.run("A", () => "never");
    } catch {
      throw new Error("different");
    }
  }),
  client.createFunction({ id: "input", ...trigger }, async ({ step }) => {
    return await step.run("A", (a: number, b: number) => a + b, 1, 2);
  }),
  client.createFunction({ id: "duplicate-ids", ...trigger }, async ({ step }) => {
    const results: unknown[] = [];
    for (let i = 0; i < 3; i++) {
      results.push(await step.run("A", () => i));
    }
    return results;
  }),
  client.createFunction({ id: "named-step", ...trigger }, async ({ step }) => {
    return await step.run({ id: "A", name: "Pretty A" }, () => "A");
  }),
  client.createFunction(
    {
      id: "with-failure",
      ...trigger,
      onFailure: ({ error, event }) =>
        `handled: ${error.message} (${error.name}) for ${event.data.function_id}`,
    },
    () => "main",
  ),
  invokeTarget,
  client.createFunction({ id: "sleeps", ...trigger }, async ({ step }) => {
    await step.sleep("S", "1h");
    await step.sleepUntil("U", until);
    return "slept";
  }),
  client.createFunction({ id: "sleep-variants", ...trigger }, async ({ step }) => {
    await step.sleep("A", 500);
    await step.sleep({ id: "B", name: "Nap" }, 90 * 60000);
    await step.sleepUntil("C", "2030-01-02T03:04:05Z");
  }),
  client.createFunction({ id: "waits", ...trigger }, async ({ step }) => {
    const evt = await step.waitForEvent("W", {
      event: "demo/other",
      match: "data.id",
      timeout: "1d",
    });
    return { evt };
  }),
  client.createFunction({ id: "waits-if", ...trigger }, async ({ step }) => {
    return await step.waitForEvent({ id: "W", name: "Wait" }, {
      event: "demo/other",
      if: "async.data.n > 1",
      timeout: until,
    });
  }),
  client.createFunction({ id: "invokes", ...trigger }, async ({ step }) => {
    return await step.invoke("I", { function: invokeTarget, data: { x: 1 } });
  }),
  client.createFunction({ id: "invokes-ref", ...trigger }, async ({ step }) => {
    return await step.invoke("I", {
      function: referenceFunction({ functionId: "other" }),
      v: "2",
      meta: { sessions: { conv: 1 } },
      timeout: "1h",
    });
  }),
  client.createFunction({ id: "invokes-ref-app", ...trigger }, async ({ step }) => {
    return await step.invoke({ id: "I", name: "Call other" }, {
      function: referenceFunction({ functionId: "other", appId: "other-app" }),
      data: {},
      timeout: 60000,
    });
  }),
  client.createFunction({ id: "sends", ...trigger }, async ({ step }) => {
    return await step.sendEvent("E", { name: "demo/sent", data: { a: 1 } });
  }),
  client.createFunction({ id: "sends-named", ...trigger }, async ({ step }) => {
    return await step.sendEvent({ id: "E", name: "Send it" }, [
      { name: "demo/sent", data: { a: 1 } },
      { name: "demo/sent", data: { a: 2 } },
    ]);
  }),
  client.createFunction({ id: "signals", ...trigger }, async ({ step }) => {
    return await step.sendSignal("SS", { signal: "sig-1", data: { ok: true } });
  }),
  client.createFunction({ id: "signals-missing", ...trigger }, async ({ step }) => {
    return await step.sendSignal("SS", { signal: "missing" });
  }),
  client.createFunction({ id: "waits-signal", ...trigger }, async ({ step }) => {
    return await step.waitForSignal("WS", {
      signal: "sig-1",
      timeout: "2h",
      onConflict: "replace",
    });
  }),
  client.createFunction({ id: "fetches", ...trigger }, async ({ step }) => {
    const res = await step.fetch("https://Example.com/path?q=1", {
      method: "POST",
      headers: { "X-Token": "abc" },
      body: '{"a":1}',
    });
    return {
      status: res.status,
      body: await res.text(),
      header: res.headers.get("x-r"),
    };
  }),
  client.createFunction({ id: "fetches-get", ...trigger }, async ({ step }) => {
    await step.fetch("https://example.com/");
    await step.fetch("https://example.com/again");
  }),
  client.createFunction({ id: "infers", ...trigger }, async ({ step }) => {
    return await step.ai.infer("AI", {
      model: aiModel,
      body: { model: "m", messages: [] },
    });
  }),
  client.createFunction({ id: "wraps", ...trigger }, async ({ step }) => {
    return await step.ai.wrap("AW", (p: string) => p.toUpperCase(), "hi");
  }),
];

const commHandler = new InngestCommHandler({
  client,
  frameworkName: "test",
  functions: fns,
  handler: (req: Request) => ({
    body: () => req.text(),
    headers: (key: string) => req.headers.get(key),
    method: () => req.method,
    url: () => new URL(req.url),
    transformResponse: ({ body, headers, status }: any) =>
      new Response(body, { status, headers }),
  }),
});
const handler = commHandler["createHandler"]();

const event = { name: "demo/event.sent", data: { n: 1 }, id: "evt-1", ts: 1700000000000 };
const ctx = (extra: Record<string, unknown> = {}) => ({
  run_id: "run-1",
  attempt: 0,
  disable_immediate_execution: false,
  use_api: false,
  stack: { stack: [], current: 0 },
  ...extra,
});
const body = (extra: Record<string, unknown> = {}, ctxExtra: Record<string, unknown> = {}) => ({
  event,
  events: [event],
  steps: {},
  version: 2,
  ctx: ctx(ctxExtra),
  ...extra,
});
const stepError = (message: string) => ({
  name: "Error",
  message,
  stack: `Error: ${message}\n    at somewhere`,
  __serialized: true,
});

type Case = {
  fnId: string;
  stepId?: string;
  body: unknown;
  headers?: Record<string, string>;
  rawBody?: string;
};

const cases: Record<string, Case> = {
  no_steps: { fnId: "no-steps", body: body() },
  returns_undefined: { fnId: "returns-undefined", body: body() },
  throws: { fnId: "throws", body: body() },
  throws_last_attempt: { fnId: "throws", body: body({}, { attempt: 2, max_attempts: 3 }) },
  throws_not_last_attempt: { fnId: "throws", body: body({}, { attempt: 1, max_attempts: 3 }) },
  throws_non_retriable: { fnId: "throws-non-retriable", body: body() },
  throws_retry_after: { fnId: "throws-retry-after", body: body() },
  a_to_b_first: { fnId: "a-to-b", body: body() },
  a_to_b_second: {
    fnId: "a-to-b",
    body: body({ steps: { [hash("A")]: { type: "data", data: "A" } } }, { stack: { stack: [hash("A")], current: 0 } }),
  },
  a_to_b_done: {
    fnId: "a-to-b",
    body: body(
      { steps: { [hash("A")]: { data: "A" }, [hash("B")]: { data: "B" } } },
      { stack: { stack: [hash("A"), hash("B")], current: 1 } },
    ),
  },
  a_to_b_no_stack: {
    fnId: "a-to-b",
    body: body({ steps: { [hash("A")]: { data: "A" } } }, { stack: null }),
  },
  a_to_b_raw_state: {
    fnId: "a-to-b",
    body: body({ steps: { [hash("A")]: "A" } }),
  },
  a_to_b_disable_immediate: {
    fnId: "a-to-b",
    body: body({}, { disable_immediate_execution: true }),
  },
  a_to_b_requested_step: {
    fnId: "a-to-b",
    stepId: hash("A"),
    body: body({}, { disable_immediate_execution: true }),
  },
  a_to_b_requested_second: {
    fnId: "a-to-b",
    stepId: hash("B"),
    body: body({ steps: { [hash("A")]: { data: "A" } } }, { disable_immediate_execution: true }),
  },
  returns_step_data_first: { fnId: "returns-step-data", body: body() },
  returns_step_data_second: {
    fnId: "returns-step-data",
    body: body({ steps: { [hash("A")]: { data: { x: 1, nested: { y: [1, 2] } } } } }),
  },
  returns_step_data_done: {
    fnId: "returns-step-data",
    body: body({
      steps: {
        [hash("A")]: { data: { x: 1, nested: { y: [1, 2] } } },
        [hash("B")]: { data: null },
      },
    }),
  },
  step_throws: { fnId: "step-throws", body: body() },
  step_throws_last_attempt: { fnId: "step-throws", body: body({}, { attempt: 3, max_attempts: 4 }) },
  step_non_retriable: { fnId: "step-non-retriable", body: body() },
  step_retry_after: { fnId: "step-retry-after", body: body() },
  step_error_uncaught: {
    fnId: "step-throws",
    body: body({ steps: { [hash("A")]: { type: "error", error: stepError("step failed") } } }),
  },
  step_error_caught: {
    fnId: "catches-step-error",
    body: body({ steps: { [hash("A")]: { error: stepError("it broke") } } }),
  },
  step_error_rethrown_different: {
    fnId: "rethrows-other-error",
    body: body({ steps: { [hash("A")]: { error: stepError("it broke") } } }),
  },
  input_first: { fnId: "input", body: body() },
  input_replay: {
    fnId: "input",
    body: body({ steps: { [hash("A")]: { input: [5, 6] } } }),
  },
  input_done: {
    fnId: "input",
    body: body({ steps: { [hash("A")]: { data: 3 } } }),
  },
  duplicate_ids_third: {
    fnId: "duplicate-ids",
    body: body({ steps: { [hash("A")]: { data: 0 }, [hash("A:1")]: { data: 1 } } }),
  },
  duplicate_ids_done: {
    fnId: "duplicate-ids",
    body: body({
      steps: { [hash("A")]: { data: 0 }, [hash("A:1")]: { data: 1 }, [hash("A:2")]: { data: 2 } },
    }),
  },
  named_step: { fnId: "named-step", body: body() },
  named_step_disable_immediate: {
    fnId: "named-step",
    body: body({}, { disable_immediate_execution: true }),
  },
  step_not_found: {
    fnId: "a-to-b",
    stepId: hash("Z"),
    body: body({ steps: { [hash("A")]: { data: "A" } } }),
  },
  step_not_found_after_return: {
    fnId: "no-steps",
    stepId: hash("Z"),
    body: body(),
  },
  on_failure: {
    fnId: "with-failure-failure",
    body: body({
      event: {
        name: "inngest/function.failed",
        data: { function_id: "test-app-with-failure", run_id: "run-0", error: stepError("main broke") },
      },
      events: [],
    }),
  },
  use_api: {
    fnId: "a-to-b",
    body: body({ events: [], steps: {} }, { use_api: true }),
  },
  version_header_one: {
    fnId: "no-steps",
    headers: { "x-inngest-req-version": "1" },
    body: body(),
  },
  version_zero: { fnId: "no-steps", body: body({ version: 0 }) },
  version_missing: { fnId: "no-steps", body: { ...body(), version: undefined } },
  invalid_event: { fnId: "no-steps", body: body({ event: "nope" }) },
  sleep_first: { fnId: "sleeps", body: body() },
  sleep_until_next: {
    fnId: "sleeps",
    body: body({ steps: { [hash("S")]: { data: null } } }),
  },
  sleep_done: {
    fnId: "sleeps",
    body: body({ steps: { [hash("S")]: { data: null }, [hash("U")]: { data: null } } }),
  },
  sleep_variants_ms: { fnId: "sleep-variants", body: body() },
  sleep_variants_named: {
    fnId: "sleep-variants",
    body: body({ steps: { [hash("A")]: { data: null } } }),
  },
  sleep_variants_string_date: {
    fnId: "sleep-variants",
    body: body({ steps: { [hash("A")]: { data: null }, [hash("B")]: { data: null } } }),
  },
  wait_first: { fnId: "waits", body: body() },
  wait_received: {
    fnId: "waits",
    body: body({
      steps: { [hash("W")]: { data: { name: "demo/other", data: { id: 7 }, id: "e2", ts: 1 } } },
    }),
  },
  wait_timed_out: {
    fnId: "waits",
    body: body({ steps: { [hash("W")]: { data: null } } }),
  },
  wait_if: { fnId: "waits-if", body: body() },
  invoke_first: { fnId: "invokes", body: body() },
  invoke_done: {
    fnId: "invokes",
    body: body({ steps: { [hash("I")]: { data: "target" } } }),
  },
  invoke_failed: {
    fnId: "invokes",
    body: body({ steps: { [hash("I")]: { error: stepError("invoked broke") } } }),
  },
  invoke_ref: { fnId: "invokes-ref", body: body() },
  invoke_ref_app: { fnId: "invokes-ref-app", body: body() },
  send_first: { fnId: "sends", body: body() },
  send_done: {
    fnId: "sends",
    body: body({ steps: { [hash("E")]: { data: { ids: ["sent-1"] } } } }),
  },
  send_disable_immediate: {
    fnId: "sends-named",
    body: body({}, { disable_immediate_execution: true }),
  },
  send_requested: {
    fnId: "sends-named",
    stepId: hash("E"),
    body: body({}, { disable_immediate_execution: true }),
  },
  send_input_replay: {
    fnId: "sends",
    body: body({ steps: { [hash("E")]: { input: ["ignored"] } } }),
  },
  signal_first: { fnId: "signals", body: body() },
  signal_missing: { fnId: "signals-missing", body: body() },
  signal_disable_immediate: {
    fnId: "signals",
    body: body({}, { disable_immediate_execution: true }),
  },
  signal_done: {
    fnId: "signals",
    body: body({ steps: { [hash("SS")]: { data: { runId: "run-2" } } } }),
  },
  wait_signal_first: { fnId: "waits-signal", body: body() },
  wait_signal_done: {
    fnId: "waits-signal",
    body: body({ steps: { [hash("WS")]: { data: { signal: "sig-1", data: { y: 2 } } } } }),
  },
  fetch_first: { fnId: "fetches", body: body() },
  fetch_done: {
    fnId: "fetches",
    body: body({
      steps: {
        [hash("step.fetch: example.com")]: {
          data: { status_code: 201, headers: { "x-r": "yes" }, body: "hi" },
        },
      },
    }),
  },
  fetch_get_first: { fnId: "fetches-get", body: body() },
  fetch_get_second: {
    fnId: "fetches-get",
    body: body({
      steps: {
        [hash("step.fetch: example.com")]: {
          data: { status_code: 200, headers: {}, body: "" },
        },
      },
    }),
  },
  infer_first: { fnId: "infers", body: body() },
  infer_done: {
    fnId: "infers",
    body: body({ steps: { [hash("AI")]: { data: { choices: [{ text: "ok" }] } } } }),
  },
  wrap_first: { fnId: "wraps", body: body() },
  wrap_disable_immediate: {
    fnId: "wraps",
    body: body({}, { disable_immediate_execution: true }),
  },
  wrap_input_replay: {
    fnId: "wraps",
    body: body({ steps: { [hash("AW")]: { input: ["yo"] } } }),
  },
  missing_ctx: { fnId: "no-steps", body: { event, events: [event], steps: {}, version: 2 } },
};

const out: Record<string, unknown> = {};
for (const [name, c] of Object.entries(cases)) {
  apiCalls.length = 0;
  const query = new URLSearchParams({ fnId: `test-app-${c.fnId}`, stepId: c.stepId ?? "step" });
  const raw = c.rawBody ?? JSON.stringify(c.body);
  const res: Response = await handler(
    new Request(`http://localhost:3000/api/inngest?${query}`, {
      method: "POST",
      headers: { "content-type": "application/json", ...(c.headers ?? {}) },
      body: raw,
    }),
  );
  const text = await res.text();
  const headers = Object.fromEntries(res.headers.entries());
  delete headers["server-timing"];
  out[name] = {
    request: { fnId: c.fnId, stepId: c.stepId ?? "step", headers: c.headers ?? {}, body: raw },
    status: res.status,
    headers,
    body: text === "" ? "" : JSON.parse(text),
    apiCalls: [...apiCalls],
  };
}

console.log(JSON.stringify(out, null, 2));
