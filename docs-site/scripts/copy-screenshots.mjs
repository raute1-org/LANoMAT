#!/usr/bin/env node
// Copy the app's screenshot set (docs/screenshots) into the docs site's public
// folder. The app writes light/dark pairs plus the native client walkthrough;
// the docs embed them under /docs/screens/... in the built site.
//
// Usage: npm run screenshots   (from docs-site/)
//        node scripts/copy-screenshots.mjs --clean

import { cp, mkdir, readdir, rm, stat } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const siteRoot = resolve(here, "..");
const source = resolve(siteRoot, "..", "docs", "screenshots");
const target = join(siteRoot, "public", "screens");

const clean = process.argv.includes("--clean");

const exists = async (path) => {
  try {
    await stat(path);
    return true;
  } catch {
    return false;
  }
};

if (!(await exists(source))) {
  console.error(`No screenshot source at ${source}`);
  process.exit(1);
}

if (clean) {
  await rm(target, { recursive: true, force: true });
}

await mkdir(target, { recursive: true });
await cp(source, target, { recursive: true, force: true });

const walk = async (dir) => {
  const entries = await readdir(dir, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) files.push(...(await walk(path)));
    else if (entry.name.endsWith(".png")) files.push(path);
  }
  return files;
};

const files = await walk(target);
const bytes = (
  await Promise.all(files.map(async (file) => (await stat(file)).size))
).reduce((sum, size) => sum + size, 0);

console.log(
  `Copied ${files.length} screenshots (${(bytes / 1024 / 1024).toFixed(1)} MB) to public/screens/`,
);
console.log("Run `npm run build` to embed them at /docs/screens/.");
