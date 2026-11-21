#!/usr/bin/env node
// Capture the built docs site itself (card 13: "the docs itself" is one of the
// surfaces the docs show). The app screenshots come from
// `scripts/screenshots/capture.mjs`; this script only shoots pages of the
// Zudoku site, from the static build in docs-site/dist.
//
// Usage (from docs-site/, after `npm run build`):
//   python3 -m http.server 4013 --directory dist &
//   DOCS_URL=http://127.0.0.1:4013 node scripts/capture-docs.mjs
//
// Playwright is not a docs-site dependency: it is resolved from the app
// checkout's devDependencies (the docs site lives inside the app repo), so run
// this from a full checkout, not from the standalone docs container.

import { mkdir } from "node:fs/promises";
import { createRequire } from "node:module";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const siteRoot = resolve(here, "..");
const outputDir = join(siteRoot, "public", "screens");

const DOCS_URL = (process.env.DOCS_URL ?? "http://127.0.0.1:4013").replace(/\/$/, "");
const VIEWPORT = { width: 1440, height: 900 };

const requireSite = createRequire(join(siteRoot, "package.json"));
const requireRepo = createRequire(join(siteRoot, "..", "package.json"));
let chromium;
for (const load of [() => requireRepo("@playwright/test"), () => requireRepo("playwright"), () => requireSite("@playwright/test"), () => requireSite("playwright")]) {
  try {
    ({ chromium } = load());
    break;
  } catch {
    // try the next resolution base
  }
}
if (chromium === undefined) {
  console.error(
    "Playwright not found. Run from a full checkout with the app's devDependencies installed (npm ci in the repo root).",
  );
  process.exit(1);
}

const ROUTES = [
  {
    name: "docs-games-library",
    path: "/docs/system/games-library",
    anchor: 'img[src*="/screens/games-dark"]',
  },
  { name: "docs-mcp", path: "/docs/mcp/overview", anchor: 'img[src*="/screens/mcp-connect-pending-dark"]' },
];

async function main() {
  await mkdir(outputDir, { recursive: true });

  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: VIEWPORT });

  for (const route of ROUTES) {
    for (const colorScheme of ["light", "dark"]) {
      const page = await context.newPage();
      await page.emulateMedia({ colorScheme });
      // A plain file server has no clean-URL rewrite; the nginx image does.
      // Try the real path first, fall back to the prerendered .html file.
      let response = await page.goto(`${DOCS_URL}${route.path}`, { waitUntil: "networkidle" });
      if (response !== null && response.status() === 404) {
        response = await page.goto(`${DOCS_URL}${route.path}.html`, { waitUntil: "networkidle" });
      }
      if (response === null || !response.ok()) {
        throw new Error(`${route.path}: HTTP ${response?.status() ?? "no response"}`);
      }
      await page.waitForTimeout(300);

      if (route.anchor !== undefined) {
        const target = page.locator(route.anchor).first();
        if ((await target.count()) > 0) {
          await target.scrollIntoViewIfNeeded();
          // Center the screenshot table in the frame rather than pinning it to
          // the bottom edge.
          await page.mouse.wheel(0, -220);
          await page.waitForTimeout(200);
        }
      }

      const file = join(outputDir, `${route.name}-${colorScheme}.png`);
      await page.screenshot({ path: file });
      console.log(`  wrote docs-site/public/screens/${route.name}-${colorScheme}.png`);

      await page.close();
    }
  }

  await context.close();
  await browser.close();
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
