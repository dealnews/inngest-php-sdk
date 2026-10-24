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

const logger = new ConsoleLogger({ level: "silent" });
const hash = (id: string) => createHash("sha1").update(id).digest("hex");

const apiCalls: string[] = [];
const client = new Inngest({
  id: "test-app",
  isDev: true,
  logger,
  fetch: async (url: string) => {
    const path = new URL(String(url)).pathname;
    apiCalls.push(path);
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
