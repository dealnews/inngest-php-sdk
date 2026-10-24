// Lets Node run the inngest-js TypeScript sources directly:
// - supplies the generated src/version.ts
// - maps "./x.js" imports onto "./x.ts" files
export async function resolve(specifier, context, next) {
  try {
    return await next(specifier, context);
  } catch (err) {
    if (/(^|\/)version\.ts$/.test(specifier)) {
      return { url: "virtual:inngest-version", shortCircuit: true };
    }
    if (specifier.startsWith(".")) {
      const base = specifier.endsWith(".js") ? specifier.slice(0, -3) : specifier;
      for (const candidate of [base + ".ts", base + "/index.ts"]) {
        try {
          return await next(candidate, context);
        } catch {}
      }
    }
    throw err;
  }
}
export async function load(url, context, next) {
  if (url === "virtual:inngest-version") {
    return { format: "module", source: 'export const version = "4.26.0";', shortCircuit: true };
  }
  return next(url, context);
}
