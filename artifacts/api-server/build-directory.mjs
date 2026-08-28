import { createRequire } from "node:module";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { build as esbuild } from "esbuild";
import esbuildPluginPino from "esbuild-plugin-pino";
import { rm } from "node:fs/promises";

globalThis.require = createRequire(import.meta.url);
const artifactDir = path.dirname(fileURLToPath(import.meta.url));

async function buildDirectory() {
  const distDir = path.resolve(artifactDir, "dist-directory");
  await rm(distDir, { recursive: true, force: true });

  await esbuild({
    entryPoints: [path.resolve(artifactDir, "src/directory-index.ts")],
    platform: "node",
    bundle: true,
    format: "esm",
    outfile: path.join(distDir, "directory-index.mjs"),
    logLevel: "info",
    sourcemap: "linked",
    external: ["*.node", "pg-native", "pino-pretty"],
    plugins: [esbuildPluginPino({ transports: ["pino-pretty"] })],
    banner: {
      js: `import { createRequire as __bannerCrReq } from 'node:module';
import __bannerPath from 'node:path';
import __bannerUrl from 'node:url';
globalThis.require = __bannerCrReq(import.meta.url);
globalThis.__filename = __bannerUrl.fileURLToPath(import.meta.url);
globalThis.__dirname = __bannerPath.dirname(globalThis.__filename);`,
    },
  });
}

buildDirectory().catch((error) => {
  console.error(error);
  process.exit(1);
});
