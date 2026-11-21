#!/usr/bin/env node
// Verify the screenshots the docs reference: they exist, are real PNGs, stay
// below the 250 KB budget, and are not accidentally huge. Also writes
// scripts/screens.json (width/height/bytes per file) so a page can emit
// <img width height loading="lazy"> without guessing.
//
// Usage:
//   node scripts/check-screens.mjs            # warn on unreferenced app shots
//   node scripts/check-screens.mjs --strict   # fail on them too
//   node scripts/check-screens.mjs --json     # print the manifest

import { readFile, readdir, stat, writeFile } from "node:fs/promises";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const siteRoot = resolve(here, "..");
const pagesDir = join(siteRoot, "pages");
const screensDir = join(siteRoot, "public", "screens");
const manifestPath = join(here, "screens.json");
const maxBytes = 250 * 1024;

const strict = process.argv.includes("--strict");
const json = process.argv.includes("--json");

const walk = async (dir) => {
  const entries = await readdir(dir, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const path = join(dir, entry.name);

    if (entry.isDirectory()) {
      files.push(...(await walk(path)));
    } else {
      files.push(path);
    }
  }

  return files;
};

const pngSize = async (file) => {
  const bytes = await readFile(file);

  if (bytes.subarray(0, 8).toString("hex") !== "89504e470d0a1a0a") {
    return null;
  }

  return {
    width: bytes.readUInt32BE(16),
    height: bytes.readUInt32BE(20),
    bytes: bytes.length,
  };
};

const pages = (await walk(pagesDir)).filter((file) =>
  /\.(md|mdx)$/.test(file),
);

/** Map screenshot path -> pages that reference it. */
const references = new Map();

for (const page of pages) {
  const source = await readFile(page, "utf8");
  const re = /\/screens\/([A-Za-z0-9._/-]+\.png)/g;
  let match;

  while ((match = re.exec(source)) !== null) {
    const file = match[1];
    const list = references.get(file) ?? [];
    list.push(relative(siteRoot, page));
    references.set(file, list);
  }
}

const problems = [];

for (const [file, where] of [...references.entries()].sort()) {
  const path = join(screensDir, file);

  let info;

  try {
    info = await pngSize(path);
  } catch {
    problems.push(`${file}: referenced from ${where.join(", ")} but missing`);
    continue;
  }

  if (!info) {
    problems.push(`${file}: not a PNG`);
    continue;
  }

  if (info.bytes > maxBytes) {
    problems.push(
      `${file}: ${(info.bytes / 1024).toFixed(0)} KB (>250 KB budget)`,
    );
  }

  if (info.width > 1440) {
    problems.push(`${file}: ${info.width}px wide (capture budget is 1440px)`);
  }
}

// App screenshots that no page references yet (new captures waiting for a
// docs edit). docs-*.png are referenced from the tour page; voice-app/ is the
// native client's set with its own README.
const allShots = (await walk(screensDir)).filter((path) =>
  path.endsWith(".png"),
);

// The manifest covers every published shot (including the new captures that
// no page links yet), so a page can get width/height before the docs edit.
const manifest = {};

for (const path of allShots) {
  const file = relative(screensDir, path);
  const info = await pngSize(path);

  if (!info) {
    continue;
  }

  manifest[file] = {
    width: info.width,
    height: info.height,
    bytes: info.bytes,
    referencedBy: (references.get(file) ?? []).sort(),
  };
}

const unreferenced = allShots
  .map((path) => relative(screensDir, path))
  .filter(
    (file) =>
      !references.has(file) &&
      !file.startsWith("docs-") &&
      !file.startsWith("voice-app/"),
  )
  .sort();

if (strict && unreferenced.length > 0) {
  problems.push(
    `${unreferenced.length} app screenshot(s) unreferenced: ${unreferenced.join(", ")}`,
  );
}

const ordered = Object.fromEntries(
  Object.entries(manifest).sort(([a], [b]) => a.localeCompare(b)),
);

await writeFile(manifestPath, `${JSON.stringify(ordered, null, 2)}\n`);

if (json) {
  console.log(JSON.stringify(ordered, null, 2));
} else {
  console.log(
    `Checked ${references.size} referenced screenshot(s) and ${allShots.length} file(s) in public/screens.`,
  );

  if (unreferenced.length > 0) {
    console.log(
      `Unreferenced app shots (${unreferenced.length}): ${unreferenced.join(", ")}`,
    );
  }

  for (const problem of problems) {
    console.error(`FAIL ${problem}`);
  }
}

if (problems.length > 0) {
  process.exitCode = 1;
}
