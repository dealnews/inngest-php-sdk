/**
 * Dumps serve handler responses (introspection, in-band and out-of-band
 * sync, error responses) from the JS SDK. ServeHandlerParityTest compares
 * PHP output against the result. See ../js/README.md for how to run it.
 */
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
const { signDataWithKey } = await import(`${root}/src/helpers/net.ts`);
const { ConsoleLogger } = await import(`${root}/src/middleware/logger.ts`);

const signingKey =
  "signkey-test-0000000000000000000000000000000000000000000000000000000000000000";
const signingKeyFallback =
  "signkey-test-1111111111111111111111111111111111111111111111111111111111111111";
const eventKey = "event-key-123";
const logger = new ConsoleLogger({ level: "silent" });
const noop = () => null;

type Captured = { url: string; method: string; headers: Record<string, string>; body: unknown };

const makeClient = (opts: Record<string, unknown>, captured: Captured[]) =>
  new Inngest({
    id: "test-app",
    logger,
    fetch: async (url: string, init: RequestInit) => {
      captured.push({
        url: String(url),
        method: String(init.method),
        headers: Object.fromEntries(new Headers(init.headers).entries()),
        body: JSON.parse(String(init.body)),
      });
      return new Response(JSON.stringify({ status: 200, modified: true }), {
        status: 200,
      });
    },
    ...opts,
  });

const addFunctions = (client: any) => [
  client.createFunction(
    { id: "fn1", triggers: [{ event: "demo/event.sent" }], onFailure: noop },
    noop,
  ),
  client.createFunction(
    { id: "fn2", name: "Second", triggers: [{ cron: "0 * * * *" }], retries: 1 },
    noop,
  ),
];

const sign = async (body: string) => {
  const ts = Math.round(Date.now() / 1000).toString();
  return `t=${ts}&s=${await signDataWithKey(body, signingKey, ts, logger)}`;
};

const run = async ({
  client,
  serveOpts = {},
  method,
  url,
  headers = {},
  body,
}: {
  client: any;
  serveOpts?: Record<string, unknown>;
  method: string;
  url: string;
  headers?: Record<string, string>;
  body?: string;
}) => {
  const commHandler = new InngestCommHandler({
    client,
    frameworkName: "test",
    functions: addFunctions(client),
    ...serveOpts,
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
  const res: Response = await handler(
    new Request(url, { method, headers, body }),
  );
  const text = await res.text();
  const resHeaders = Object.fromEntries(res.headers.entries());
  delete resHeaders["server-timing"];
  let parsed: unknown = text;
  try {
    parsed = JSON.parse(text);
  } catch {
    // keep raw text
  }

  return { status: res.status, headers: resHeaders, body: parsed };
};

const out: Record<string, unknown> = {};

{
  const captured: Captured[] = [];
  out.introspection_dev = await run({
    client: makeClient({ isDev: true }, captured),
    method: "GET",
    url: "http://localhost:3000/api/inngest",
    headers: { "x-inngest-server-kind": "dev" },
  });
}

{
  const captured: Captured[] = [];
  out.introspection_cloud = await run({
    client: makeClient(
      { isDev: false, signingKey, signingKeyFallback, eventKey, env: "my-env" },
      captured,
    ),
    serveOpts: { serveOrigin: "https://example.com", servePath: "/custom/path" },
    method: "GET",
    url: "https://localhost:3000/api/inngest",
    headers: {
      "x-inngest-signature": await sign(""),
      traceparent: "00-abc-def-01",
    },
  });
}

{
  const captured: Captured[] = [];
  out.introspection_cloud_no_keys = await run({
    client: makeClient({ isDev: false, signingKey }, captured),
    method: "GET",
    url: "https://localhost:3000/api/inngest",
    headers: { "x-inngest-signature": await sign("") },
  });
}

{
  const captured: Captured[] = [];
  const body = JSON.stringify({ url: "https://example.com/api/inngest?x=1" });
  out.in_band_dev = await run({
    client: makeClient({ isDev: true }, captured),
    method: "PUT",
    url: "http://localhost:3000/api/inngest?deployId=dep-1",
    headers: { "x-inngest-sync-kind": "in_band" },
    body,
  });
}

{
  const captured: Captured[] = [];
  const body = JSON.stringify({ url: "https://example.com/api/inngest" });
  out.in_band_cloud = await run({
    client: makeClient(
      { isDev: false, signingKey, eventKey, appVersion: "2.0.0" },
      captured,
    ),
    method: "PUT",
    url: "https://localhost:3000/api/inngest",
    headers: {
      "x-inngest-sync-kind": "in_band",
      "x-inngest-signature": await sign(body),
    },
    body,
  });
}

{
  const captured: Captured[] = [];
  const body = JSON.stringify({ url: "https://example.com/api/inngest", extra: 1 });
  out.in_band_invalid_body = await run({
    client: makeClient({ isDev: true }, captured),
    method: "PUT",
    url: "http://localhost:3000/api/inngest",
    headers: { "x-inngest-sync-kind": "in_band" },
    body,
  });
}

{
  const captured: Captured[] = [];
  const res = await run({
    client: makeClient(
      { isDev: false, signingKey, signingKeyFallback, appVersion: "2.0.0" },
      captured,
    ),
    method: "PUT",
    url: "https://localhost:3000/api/inngest?deployId=dep-1",
    headers: {
      "x-inngest-signature": await sign(""),
      "x-inngest-server-kind": "cloud",
    },
  });
  out.out_of_band_cloud = { ...res, register: captured };
}

{
  const captured: Captured[] = [];
  const res = await run({
    client: makeClient({ isDev: true }, captured),
    serveOpts: { serveOrigin: "https://example.com", servePath: "/custom/path" },
    method: "PUT",
    url: "http://localhost:3000/api/inngest?deployId=undefined",
  });
  out.out_of_band_dev_origin_path = { ...res, register: captured };
}

{
  const captured: Captured[] = [];
  out.post_trust_probe = await run({
    client: makeClient({ isDev: true }, captured),
    method: "POST",
    url: "http://localhost:3000/api/inngest?probe=trust",
    body: "{}",
  });
  out.post_unknown_probe = await run({
    client: makeClient({ isDev: true }, captured),
    method: "POST",
    url: "http://localhost:3000/api/inngest?probe=nope",
    body: "{}",
  });
  out.post_missing_fn_id = await run({
    client: makeClient({ isDev: true }, captured),
    method: "POST",
    url: "http://localhost:3000/api/inngest",
    body: "{}",
  });
  out.post_unknown_fn = await run({
    client: makeClient({ isDev: true }, captured),
    method: "POST",
    url: "http://localhost:3000/api/inngest?fnId=nope",
    body: "{}",
  });
  out.post_missing_body = await run({
    client: makeClient({ isDev: true }, captured),
    method: "POST",
    url: "http://localhost:3000/api/inngest?fnId=test-app-fn1",
  });
  out.method_not_allowed = await run({
    client: makeClient({ isDev: true }, captured),
    method: "DELETE",
    url: "http://localhost:3000/api/inngest",
  });
}

{
  const captured: Captured[] = [];
  out.cloud_no_signing_key = await run({
    client: makeClient({ isDev: false }, captured),
    method: "GET",
    url: "https://localhost:3000/api/inngest",
  });
  out.cloud_unsigned_get = await run({
    client: makeClient({ isDev: false, signingKey, env: "my-env" }, captured),
    method: "GET",
    url: "https://localhost:3000/api/inngest",
    headers: { "x-inngest-server-kind": "cloud" },
  });
}

console.log(JSON.stringify(out, null, 2));
