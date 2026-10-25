/**
 * Dumps middleware hook calls from the JS SDK. Every function runs with a
 * client-level recording middleware ("c"); `fn-mw` also has a
 * function-level one ("f"). Each case POSTs one execution request and
 * records the response, every hook call (in order, with the details PHP
 * can match), what reached the logger, and events sent to the event API.
 * The client middleware also changes things for some functions (named
 * after the hook they exercise). MiddlewareParityTest replays each case
 * against the PHP SDK. See ../js/README.md for how to run it.
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
const { Middleware } = await import(
  `${root}/src/components/middleware/middleware.ts`
);

const hash = (id: string) => createHash("sha1").update(id).digest("hex");

type Entry = Record<string, unknown>;
const hooks: Entry[] = [];
const logs: [string, string][] = [];
const sent: unknown[] = [];

const LOGGED = new Set([
  "top",
  "in A",
  "after A",
  "end",
  "Inngest step error",
  "Inngest function error",
]);
const capture =
  (level: string) =>
  (...args: unknown[]) => {
    const message = args.find((a) => typeof a === "string") as string;
    if (LOGGED.has(message)) {
      logs.push([level, message]);
    }
  };
const logger = {
  info: capture("info"),
  warn: capture("warning"),
  error: capture("error"),
  debug: capture("debug"),
};

const json = (value: unknown) =>
  value === undefined ? null : JSON.parse(JSON.stringify(value));
const errorInfo = (err: unknown) => ({
  name: (err as Error).name,
  message: (err as Error).message,
});
const stepInfo = (info: any) => ({
  id: info.options.id,
  name: info.options.name,
  hashedId: info.hashedId,
  type: info.stepType,
  memoized: info.memoized,
  ...(info.input === undefined ? {} : { input: json(info.input) }),
});
const fnId = (fn: any) => fn?.opts?.id ?? null;

const makeRec = (tag: string) =>
  class Rec extends Middleware.BaseMiddleware {
    readonly id = `rec-${tag}`;

    log(hook: string, extra: Entry = {}) {
      hooks.push({ tag, hook, ...extra });
    }

    override transformFunctionInput(arg: any) {
      this.log("transformFunctionInput", { steps: Object.keys(arg.steps) });
      if (tag === "c" && fnId(arg.fn) === "tfi") {
        const steps = { ...arg.steps };
        if (steps[hash("A")]?.type === "data") {
          steps[hash("A")] = { type: "data", data: "A-changed" };
        }
        return {
          ...arg,
          ctx: {
            ...arg.ctx,
            event: { ...arg.ctx.event, data: { ...arg.ctx.event.data, n: 99 } },
            db: "db",
          },
          steps,
        };
      }
      return arg;
    }

    override onMemoizationEnd() {
      this.log("onMemoizationEnd");
    }

    override onRunStart() {
      this.log("onRunStart");
    }

    override async wrapFunctionHandler({ fn, next }: any) {
      this.log("wrapFunctionHandler:before");
      if (tag === "c" && fnId(fn) === "wfh-throws") {
        throw new Error("hook broke");
      }
      try {
        let out = await next();
        if (tag === "c" && fnId(fn) === "wfh-out") {
          out = { wrapped: out };
        }
        this.log("wrapFunctionHandler:after", { output: json(out) });
        return out;
      } catch (err) {
        this.log("wrapFunctionHandler:error", { error: errorInfo(err) });
        if (tag === "c" && fnId(fn) === "wfh-err") {
          throw new NonRetriableError(`converted: ${(err as Error).message}`);
        }
        throw err;
      }
    }

    override onRunComplete({ output }: any) {
      this.log("onRunComplete", { output: json(output) });
    }

    override onRunError({ error, isFinalAttempt }: any) {
      this.log("onRunError", { error: errorInfo(error), isFinalAttempt });
    }

    override transformStepInput(arg: any) {
      this.log("transformStepInput", {
        id: arg.stepOptions.id,
        type: arg.stepInfo.stepType,
        memoized: arg.stepInfo.memoized,
        input: json(arg.input),
      });
      if (tag !== "c") {
        return arg;
      }
      const id = fnId(arg.fn);
      const type = arg.stepInfo.stepType;
      if (id === "tsi-run" && type === "run") {
        return { ...arg, input: [2] };
      }
      if (id === "tsi-id" && arg.stepOptions.id === "B") {
        return { ...arg, stepOptions: { ...arg.stepOptions, id: "A" } };
      }
      if (id === "tsi-kinds") {
        if (type === "sleep") {
          return { ...arg, input: ["1s"] };
        }
        if (type === "waitForEvent") {
          return { ...arg, input: [{ ...arg.input[0], timeout: "1s" }] };
        }
        if (type === "invoke") {
          const opts = arg.input[0];
          return {
            ...arg,
            input: [{ ...opts, payload: { ...opts.payload, data: { value: 2 } } }],
          };
        }
      }
      return arg;
    }

    override async wrapStep({ ctx, fn, next, stepInfo: info }: any) {
      this.log("wrapStep:before", { step: stepInfo(info) });
      const id = tag === "c" ? fnId(fn) : null;
      if (id === "prepend" && info.options.id === "main") {
        await ctx.step.run("pre", () => "pre");
      }
      if (id === "ws-throws" && info.options.id === "A") {
        throw new Error("hook broke");
      }
      if (id === "ws-bookend" && info.options.id === "S") {
        await ctx.step.run("before", () => "before");
        const out = await next();
        await ctx.step.run("after", () => "after");
        this.log("wrapStep:after", { step: stepInfo(info), output: json(out) });
        return out;
      }
      try {
        let out = await next();
        if (tag === "c" && fnId(fn) === "serde" && out && out.enc !== undefined) {
          out = out.enc;
        }
        this.log("wrapStep:after", { step: stepInfo(info), output: json(out) });
        return out;
      } catch (err) {
        this.log("wrapStep:error", { step: stepInfo(info), error: errorInfo(err) });
        if (id === "ws-change-error") {
          throw new Error(`replaced: ${(err as Error).message}`);
        }
        throw err;
      }
    }

    override onStepStart({ stepInfo: info }: any) {
      this.log("onStepStart", { step: stepInfo(info) });
    }

    override async wrapStepHandler({ fn, next, stepInfo: info }: any) {
      this.log("wrapStepHandler:before", { step: stepInfo(info) });
      try {
        let out = await next();
        if (tag === "c" && fnId(fn) === "serde") {
          out = { enc: out };
        }
        this.log("wrapStepHandler:after", { output: json(out) });
        return out;
      } catch (err) {
        this.log("wrapStepHandler:error", { error: errorInfo(err) });
        throw err;
      }
    }

    override onStepComplete({ output, stepInfo: info }: any) {
      this.log("onStepComplete", { step: stepInfo(info), output: json(output) });
    }

    override onStepError({ error, isFinalAttempt, stepInfo: info }: any) {
      this.log("onStepError", {
        step: stepInfo(info),
        error: errorInfo(error),
        isFinalAttempt,
      });
    }

    override transformSendEvent(arg: any) {
      this.log("transformSendEvent", {
        fn: fnId(arg.fn),
        events: arg.events.map((e: any) => e.name),
      });
      if (tag === "f") {
        return {
          ...arg,
          events: arg.events.map((e: any) => ({
            ...e,
            data: { ...e.data, extra: 1 },
          })),
        };
      }
      return arg;
    }

    override async wrapSendEvent({ events, next }: any) {
      this.log("wrapSendEvent:before", { events: events.map((e: any) => e.name) });
      const out = await next();
      this.log("wrapSendEvent:after", { output: json(out) });
      return out;
    }

    override async wrapRequest({ fn, next, runId }: any) {
      this.log("wrapRequest:before", { fn: fnId(fn), runId });
      const res = await next();
      this.log("wrapRequest:after", { status: res.status });
      return res;
    }
  };

const RecC = makeRec("c");
const RecF = makeRec("f");

const client = new Inngest({
  id: "test-app",
  isDev: true,
  logger,
  middleware: [RecC],
  fetch: async (url: string, init?: RequestInit) => {
    const path = new URL(String(url)).pathname;
    if (path.startsWith("/v1/checkpoint/")) {
      return new Response("{}");
    }
    if (path.startsWith("/e/")) {
      const events = JSON.parse(String(init?.body)).map((e: any) => ({
        name: e.name,
        data: e.data,
      }));
      sent.push(...events);
      return new Response(JSON.stringify({ ids: ["sent-1"], status: 200 }));
    }
    return new Response("{}", { status: 404 });
  },
});

const trigger = { triggers: [{ event: "demo/event.sent" }] };
const make = (id: string, opts: Record<string, unknown>, handler: any) =>
  client.createFunction({ id, ...trigger, ...opts }, handler);

const invokeTarget = make("invoke-target", {}, () => "target");

const fns = [
  invokeTarget,
  make("hello", {}, () => "hi"),
  make("two-steps", {}, async ({ step }: any) => {
    const a = await step.run("A", () => "a");
    const b = await step.run("B", () => "b");
    return [a, b];
  }),
  make("with-input", {}, async ({ step }: any) =>
    step.run("A", (x: number) => x * 10, 1),
  ),
  make("sleeps", {}, async ({ step }: any) => {
    await step.run("A", () => "a");
    await step.sleep("S", "1h");
    return "done";
  }),
  make("step-fails", {}, async ({ step }: any) => {
    await step.run("A", () => {
      throw new Error("A broke");
    });
  }),
  make("catches", {}, async ({ step }: any) => {
    try {
      await step.run("A", () => "x");
    } catch (err) {
      return `caught ${(err as Error).message}`;
    }
  }),
  make("throws", {}, () => {
    throw new Error("boom");
  }),
  make("parallel", {}, async ({ step }: any) =>
    Promise.all([step.run("A", () => "a"), step.run("B", () => "b")]),
  ),
  make("fn-mw", { middleware: [RecF] }, async ({ step }: any) => {
    await step.run("A", () => "a");
    await step.sendEvent("send", { name: "x/y", data: { a: 1 } });
    return "ok";
  }),
  make("tsi-run", {}, async ({ step }: any) =>
    step.run("A", (n: number) => n * 10, 1),
  ),
  make("tsi-id", {}, async ({ step }: any) => {
    const a = await step.run("A", () => "a");
    const b = await step.run("B", () => "b");
    return [a, b];
  }),
  make("tsi-kinds", {}, async ({ step }: any) => {
    await step.sleep("S", "60s");
    await step.waitForEvent("W", { event: "x/never", timeout: "60s" });
    await step.invoke("I", { function: invokeTarget, data: { value: 1 } });
    return "ok";
  }),
  make("prepend", {}, async ({ step }: any) => step.run("main", () => "main")),
  make("tfi", {}, async ({ event, step, db }: any) => {
    const a = await step.run("A", () => "a");
    return { a, n: event.data.n, db };
  }),
  make("wfh-out", {}, async ({ step }: any) => {
    await step.run("A", () => "a");
    return "out";
  }),
  make("wfh-err", {}, () => {
    throw new Error("raw");
  }),
  make("serde", {}, async ({ step }: any) => step.run("A", () => "v")),
  make("ws-change-error", {}, async ({ step }: any) => {
    try {
      await step.run("A", () => {
        throw new Error("A broke");
      });
    } catch (err) {
      return `caught ${(err as Error).message}`;
    }
  }),
  make("ws-throws", {}, async ({ step }: any) => step.run("A", () => "a")),
  make("ws-bookend", {}, async ({ step }: any) => {
    await step.sleep("S", "1h");
    return "done";
  }),
  make("wfh-throws", {}, async ({ step }: any) => step.run("A", () => "a")),
  make("logs", {}, async ({ step, logger: log }: any) => {
    log.info("top");
    await step.run("A", () => {
      log.info("in A");
      return "a";
    });
    log.info("after A");
    await step.run("B", () => "b");
    log.info("end");
    return "ok";
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
const states = (...pairs: [string, unknown][]) => ({
  steps: Object.fromEntries(pairs.map(([id, v]) => [hash(id), v])),
});
const order = (...ids: string[]) => ({
  stack: { stack: ids.map(hash), current: Math.max(ids.length - 1, 0) },
});
const cp = { fn_id: "fn-uuid", qi_id: "qi-1" };
const lastAttempt = { attempt: 3, max_attempts: 4 };
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

type Case = { fnId: string; stepId?: string; body: unknown };

const cases: Record<string, Case> = {
  hello_first: { fnId: "hello", body: body() },
  hello_retry: { fnId: "hello", body: body({}, { attempt: 1 }) },
  two_first: { fnId: "two-steps", body: body() },
  two_after_a: {
    fnId: "two-steps",
    body: body(states(["A", { data: "a" }]), order("A")),
  },
  two_done: {
    fnId: "two-steps",
    body: body(states(["A", { data: "a" }], ["B", { data: "b" }]), order("A", "B")),
  },
  two_cp: { fnId: "two-steps", body: body({}, cp) },
  two_not_found: {
    fnId: "two-steps",
    stepId: hash("missing"),
    body: body(states(["A", { data: "a" }]), order("A")),
  },
  input_requested: {
    fnId: "with-input",
    stepId: hash("A"),
    body: body(states(["A", { input: [5] }]), { disable_immediate_execution: true }),
  },
  sleeps_after_a: {
    fnId: "sleeps",
    body: body(states(["A", { data: "a" }]), order("A")),
  },
  sleeps_done: {
    fnId: "sleeps",
    body: body(states(["A", { data: "a" }], ["S", { data: null }]), order("A", "S")),
  },
  step_fails: { fnId: "step-fails", body: body({}, { max_attempts: 4 }) },
  step_fails_final: { fnId: "step-fails", body: body({}, lastAttempt) },
  catches_error: {
    fnId: "catches",
    body: body(states(["A", { error: stepError("A broke") }]), order("A")),
  },
  throws_first: { fnId: "throws", body: body({}, { max_attempts: 4 }) },
  throws_final: { fnId: "throws", body: body({}, lastAttempt) },
  parallel_first: { fnId: "parallel", body: body() },
  parallel_done: {
    fnId: "parallel",
    body: body(states(["A", { data: "a" }], ["B", { data: "b" }]), order("B", "A")),
  },
  fn_mw_first: { fnId: "fn-mw", body: body() },
  fn_mw_send: {
    fnId: "fn-mw",
    body: body(states(["A", { data: "a" }]), order("A")),
  },
  tsi_run_first: { fnId: "tsi-run", body: body() },
  tsi_run_done: {
    fnId: "tsi-run",
    body: body(states(["A", { data: 20 }]), order("A")),
  },
  tsi_id_after_a: {
    fnId: "tsi-id",
    body: body(states(["A", { data: "a" }]), order("A")),
  },
  tsi_kinds_first: { fnId: "tsi-kinds", body: body() },
  tsi_kinds_after_sleep: {
    fnId: "tsi-kinds",
    body: body(states(["S", { data: null }]), order("S")),
  },
  tsi_kinds_after_wait: {
    fnId: "tsi-kinds",
    body: body(states(["S", { data: null }], ["W", { data: null }]), order("S", "W")),
  },
  prepend_first: { fnId: "prepend", body: body() },
  prepend_after_pre: {
    fnId: "prepend",
    body: body(states(["pre", { data: "pre" }]), order("pre")),
  },
  prepend_done: {
    fnId: "prepend",
    body: body(states(["pre", { data: "pre" }], ["main", { data: "main" }]), order("pre", "main")),
  },
  tfi_first: { fnId: "tfi", body: body() },
  tfi_done: {
    fnId: "tfi",
    body: body(states(["A", { data: "a" }]), order("A")),
  },
  wfh_out_done: {
    fnId: "wfh-out",
    body: body(states(["A", { data: "a" }]), order("A")),
  },
  wfh_err: { fnId: "wfh-err", body: body({}, { max_attempts: 4 }) },
  serde_first: { fnId: "serde", body: body() },
  serde_done: {
    fnId: "serde",
    body: body(states(["A", { data: { enc: "v" } }]), order("A")),
  },
  serde_cp: { fnId: "serde", body: body({}, cp) },
  ws_change_error_first: { fnId: "ws-change-error", body: body({}, lastAttempt) },
  ws_change_error_done: {
    fnId: "ws-change-error",
    body: body(states(["A", { error: stepError("A broke") }]), order("A")),
  },
  ws_change_error_cp: { fnId: "ws-change-error", body: body({}, { ...cp, ...lastAttempt }) },
  ws_throws_first: { fnId: "ws-throws", body: body() },
  ws_bookend_first: { fnId: "ws-bookend", body: body() },
  ws_bookend_after_before: {
    fnId: "ws-bookend",
    body: body(states(["before", { data: "before" }]), order("before")),
  },
  ws_bookend_after_sleep: {
    fnId: "ws-bookend",
    body: body(states(["before", { data: "before" }], ["S", { data: null }]), order("before", "S")),
  },
  ws_bookend_done: {
    fnId: "ws-bookend",
    body: body(
      states(["before", { data: "before" }], ["S", { data: null }], ["after", { data: "after" }]),
      order("before", "S", "after"),
    ),
  },
  ws_bookend_cp: { fnId: "ws-bookend", body: body({}, cp) },
  wfh_throws: { fnId: "wfh-throws", body: body({}, { max_attempts: 4 }) },
  logs_first: { fnId: "logs", body: body() },
  logs_after_a: {
    fnId: "logs",
    body: body(states(["A", { data: "a" }]), order("A")),
  },
  logs_done: {
    fnId: "logs",
    body: body(states(["A", { data: "a" }], ["B", { data: "b" }]), order("A", "B")),
  },
  logs_cp: { fnId: "logs", body: body({}, cp) },
};

const out: Record<string, unknown> = {};
for (const [name, c] of Object.entries(cases)) {
  hooks.length = 0;
  logs.length = 0;
  sent.length = 0;
  if (process.env.TRACE) console.error("case", name);
  const query = new URLSearchParams({ fnId: `test-app-${c.fnId}`, stepId: c.stepId ?? "step" });
  const raw = JSON.stringify(c.body);
  const res: Response = await handler(
    new Request(`http://localhost:3000/api/inngest?${query}`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: raw,
    }),
  );
  const text = await res.text();
  out[name] = {
    request: { fnId: c.fnId, stepId: c.stepId ?? "step", body: raw },
    status: res.status,
    body: text === "" ? "" : JSON.parse(text),
    hooks: [...hooks],
    logs: [...logs],
    sent: [...sent],
  };
}

console.log(JSON.stringify(out, null, 2));
