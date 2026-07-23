// Realtime-page smoke test.
//
// Regression guard for the exact bug that shipped once and slipped past the
// PHP suite: if the browser's Echo client is misconfigured (e.g. instantiated
// without a Reverb app key), pages that open a realtime channel throw during
// setup and render *blank* — but every server-side test still passes, because
// they only assert the config the server *sends*, not what the browser *does*
// with it. A PHP test cannot catch a client-side JS error; this does.
//
// It logs in as the seeded orga and loads the realtime pages, failing (exit 1)
// if any of them logs a fatal Echo/Pusher error or renders empty content.
//
// Usage (assumes the app is running at APP_URL, default http://localhost:8000,
// with the ScreenshotSeeder fixtures — same prerequisites as the screenshot
// pipeline):
//   php artisan db:seed --class=ScreenshotSeeder
//   npm run smoke
//
// Not wired into CI yet (needs app + Postgres/Redis + a build in the runner);
// run it locally before merging realtime-touching frontend changes. See
// scripts/smoke/README.md.

import { chromium } from '@playwright/test';

const APP_URL = process.env.APP_URL ?? 'http://localhost:8000';
const EVENT_SLUG = 'screenshot-demo'; // live event from ScreenshotSeeder
const ORGA_EMAIL = 'screenshot-orga@example.test';
const ORGA_PASSWORD = 'screenshot-demo-password';

// Pages that open a Reverb channel via useEventChannel — the ones that go
// blank when Echo can't be constructed.
const REALTIME_ROUTES = [
    { name: 'presence', path: `/events/${EVENT_SLUG}/presence` },
    { name: 'jukebox', path: `/events/${EVENT_SLUG}/jukebox` },
];

// Console/page-error substrings that mean Echo itself failed to initialise
// (as opposed to a mere websocket-connection failure, which is benign — the
// page still renders and realtime is a progressive enhancement).
const FATAL_PATTERNS = [/app key/i, /pusher/i, /configureecho/i];

const MIN_CONTENT_LENGTH = 50;

async function loginAsOrga(request, context) {
    await request.get(`${APP_URL}/login`);
    const cookies = await context.cookies(APP_URL);
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');

    if (!xsrf) {
        throw new Error('No XSRF-TOKEN cookie after GET /login — is the server running?');
    }

    const res = await request.post(`${APP_URL}/login`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) },
        form: { email: ORGA_EMAIL, password: ORGA_PASSWORD },
    });

    if (!res.ok() && res.status() !== 302) {
        throw new Error(
            `Login as seeded orga failed (status ${res.status()}). Did you run "php artisan db:seed --class=ScreenshotSeeder"?`,
        );
    }
}

const browser = await chromium.launch();
const context = await browser.newContext();
await loginAsOrga(context.request, context);

const failures = [];

for (const route of REALTIME_ROUTES) {
    const page = await context.newPage();
    const errors = [];
    page.on('console', (m) => {
        if (m.type() === 'error') {
            errors.push(m.text());
        }
    });
    page.on('pageerror', (e) => errors.push(`uncaught: ${e.message}`));

    await page.goto(`${APP_URL}${route.path}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    const contentLength = await page.evaluate(
        () => (document.querySelector('main')?.innerText ?? '').trim().length,
    );
    const fatal = errors.filter((e) => FATAL_PATTERNS.some((p) => p.test(e)));

    if (fatal.length > 0) {
        failures.push(`${route.name}: fatal Echo/Pusher error → ${fatal.join(' | ')}`);
    } else if (contentLength < MIN_CONTENT_LENGTH) {
        failures.push(
            `${route.name}: rendered empty (main content ${contentLength} chars < ${MIN_CONTENT_LENGTH}) — realtime setup likely threw`,
        );
    } else {
        console.log(`  ✓ ${route.name} (${contentLength} chars, no fatal errors)`);
    }

    await page.close();
}

await browser.close();

if (failures.length > 0) {
    console.error('\nRealtime smoke test FAILED:');

    for (const f of failures) {
        console.error(`  ✗ ${f}`);
    }

    process.exit(1);
}

console.log('\nRealtime smoke test passed.');
