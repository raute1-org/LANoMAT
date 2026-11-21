#!/usr/bin/env node
// Copy the app's screenshot set (docs/screenshots) into the docs site's public
// folder. The app writes light/dark pairs, 375 px pairs and the terminal
// captures; the docs embed them under /screens/... in the built site.
//
// The docs site has two kinds of images under public/screens:
//   - app screenshots, owned by ../../docs/screenshots (this script copies)
//   - docs-*.png, captured by capture-docs.mjs from the built docs site
//
// Copying never deletes a file. `--prune` removes app screenshots that no
// longer exist in the source, but always keeps docs-*.png and voice-app/ (the
// native client's own screenshot set, not part of the app capture pipeline).
//
// Usage: npm run screenshots            (from docs-site/)
//        node scripts/copy-screenshots.mjs --prune

import { copyFile, mkdir, readFile, readdir, rm, stat } from "node:fs/promises";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const siteRoot = resolve(here, "..");
const source = resolve(siteRoot, "..", "docs", "screenshots");
const target = join(siteRoot, "public", "screens");
const maxBytes = 250 * 1024;

const prune = process.argv.includes("--prune");

const exists = async (path) => {
  try {
    await stat(path);
    return true;
  } catch {
    return false;
  }
};

/** Walk a directory and return every file path relative to it. */
const walk = async (dir, base = dir) => {
  const entries = await readdir(dir, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const path = join(dir, entry.name);

    if (entry.isDirectory()) {
      files.push(...(await walk(path, base)));
    } else {
      files.push(relative(base, path));
    }
  }

  return files;
};

/** Read a PNG's IHDR dimensions without a decoder. */
const pngSize = async (file) => {
  const bytes = await readFile(file);
  const signature = bytes.subarray(0, 8).toString("hex");

  if (signature !== "89504e470d0a1a0a") {
    return null;
  }

  return {
    width: bytes.readUInt32BE(16),
    height: bytes.readUInt32BE(20),
    bytes: bytes.length,
  };
};

if (!(await exists(source))) {
  console.error(`No screenshot source at ${source}`);
  process.exit(1);
}

await mkdir(target, { recursive: true });

const sourceFiles = await walk(source);
let copied = 0;
let tooLarge = 0;

for (const file of sourceFiles) {
  if (!file.endsWith(".png")) {
    continue;
  }

  const from = join(source, file);
  const to = join(target, file);
  await mkdir(dirname(to), { recursive: true });
  await copyFile(from, to);
  copied++;

  const size = await pngSize(to);

  if (size && size.bytes > maxBytes) {
    tooLarge++;
    console.error(
      `  ${file} is ${(size.bytes / 1024).toFixed(0)} KB (>250 KB budget)`,
    );
  }
}

// App screenshots are the ones the app pipeline owns. docs-*.png belong to
// capture-docs.mjs; voice-app/ predates this pipeline and is maintained with
// the native client.
const isAppScreenshot = (file) =>
  file.endsWith(".png") &&
  !file.startsWith("docs-") &&
  !file.startsWith("voice-app/");

let pruned = 0;

if (prune) {
  const targetFiles = await walk(target);

  for (const file of targetFiles) {
    if (!isAppScreenshot(file)) {
      continue;
    }

    if (!sourceFiles.includes(file)) {
      await rm(join(target, file));
      pruned++;
    }
  }
}

const all = await walk(target);
const total = (
  await Promise.all(all.map(async (file) => (await stat(join(target, file))).size))
).reduce((sum, size) => sum + size, 0);

console.log(
  `Copied ${copied} screenshots (${(total / 1024 / 1024).toFixed(1)} MB in public/screens)` +
    (prune ? `, pruned ${pruned} stale app shot(s)` : ""),
);

if (tooLarge > 0) {
  console.error(`${tooLarge} screenshot(s) exceed the 250 KB budget.`);
  process.exitCode = 1;
}

console.log("Run `npm run build` to embed them at /screens/.");
