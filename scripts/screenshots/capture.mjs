#!/usr/bin/env node
// Playwright headless capture script for the README screenshot pipeline
// (roadmap 7.6, issue #10).
//
// Standalone Node ESM script — NOT part of the Vite/app bundle. It assumes:
//   1. A running LANoMAT server (dev: `composer run dev`, or a real/staging
//      instance) reachable at APP_URL (default http://localhost:8000).
//   2. The database has been seeded with `ScreenshotSeeder`:
//        php artisan db:seed --class=ScreenshotSeeder
//
// Usage:
//   npm run screenshots
//   APP_URL=https://staging.lan.example npm run screenshots
//
// See scripts/screenshots/README.md for the full run book.

import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from '@playwright/test';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const APP_URL = process.env.APP_URL ?? 'http://localhost:8000';
const OUTPUT_DIR = path.resolve(__dirname, '../../docs/screenshots');
const VIEWPORT = { width: 1440, height: 900 };

// Must match the fixed demo fixtures created by database/seeders/ScreenshotSeeder.php.
const EVENT_SLUG = 'screenshot-demo';
const ORGA_EMAIL = 'screenshot-orga@example.test';
const ORGA_PASSWORD = 'screenshot-demo-password';

/**
 * Routes to capture. `auth: true` routes need the seeded orga session
 * (dashboard, /admin) — everything else in this app is intentionally public
 * (event/seating/bracket/schedule/catering/voting/lfg/files/infoscreen
 * pages have no auth middleware, see routes/web.php).
 */
const ROUTES = [
    { name: 'event-page', path: `/events/${EVENT_SLUG}` },
    { name: 'seating', path: `/events/${EVENT_SLUG}/seating` },
    { name: 'tournaments', path: `/events/${EVENT_SLUG}/tournaments` },
    { name: 'schedule', path: `/events/${EVENT_SLUG}/schedule` },
    { name: 'catering', path: `/events/${EVENT_SLUG}/catering` },
    { name: 'voting', path: `/events/${EVENT_SLUG}/polls` },
    { name: 'lfg', path: `/events/${EVENT_SLUG}/lfg` },
    { name: 'files', path: `/events/${EVENT_SLUG}/files` },
    { name: 'infoscreen', path: `/screen/${EVENT_SLUG}` },
    { name: 'admin', path: '/admin', auth: true },
];

async function loginAsOrga(request, context, baseURL) {
    // Fortify's `/login` (email+password) has no UI entry point in this
    // Discord-only app (see resources/js/pages/auth/Login.vue — it only
    // renders a "Login with Discord" button), but the underlying route is
    // still live under the hood. The seeded orga (ScreenshotSeeder) gets a
    // fixed local password specifically so this script can authenticate
    // headlessly without a real Discord OAuth handshake.
    //
    // Laravel's Inertia layout carries no <meta name="csrf-token"> tag; CSRF
    // protection here relies on the XSRF-TOKEN cookie set on every response
    // (see VerifyCsrfToken), echoed back as the X-XSRF-TOKEN header — the
    // same thing axios/Inertia's request client does automatically in the
    // browser. We do it by hand since Playwright's APIRequestContext doesn't.
    await request.get(`${baseURL}/login`);

    const cookies = await context.cookies(baseURL);
    const xsrfCookie = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');

    if (!xsrfCookie) {
        throw new Error(
            'Could not find an XSRF-TOKEN cookie after GET /login — is the server running?',
        );
    }

    const response = await request.post(`${baseURL}/login`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.value) },
        form: { email: ORGA_EMAIL, password: ORGA_PASSWORD },
    });

    if (!response.ok() && response.status() !== 302) {
        throw new Error(
            `Login as seeded orga failed (status ${response.status()}). Did you run "php artisan db:seed --class=ScreenshotSeeder"?`,
        );
    }
}

async function captureRoute(context, route, colorScheme) {
    const page = await context.newPage();
    await page.emulateMedia({ colorScheme });
    await page.goto(`${APP_URL}${route.path}`, { waitUntil: 'networkidle' });
    // Let entrance animations/fonts settle; respects the design system's
    // prefers-reduced-motion floor but we still want a stable frame.
    await page.waitForTimeout(300);

    const fileName = `${route.name}-${colorScheme}.png`;
    await page.screenshot({
        path: path.join(OUTPUT_DIR, fileName),
        fullPage: true,
    });
    console.log(`  wrote docs/screenshots/${fileName}`);

    await page.close();
}

async function main() {
    await mkdir(OUTPUT_DIR, { recursive: true });

    const browser = await chromium.launch();
    const context = await browser.newContext({
        viewport: VIEWPORT,
        baseURL: APP_URL,
    });

    await loginAsOrga(context.request, context, APP_URL);

    for (const route of ROUTES) {
        for (const colorScheme of ['light', 'dark']) {
            console.log(`Capturing ${route.path} (${colorScheme})...`);

            try {
                await captureRoute(context, route, colorScheme);
            } catch (error) {
                console.error(
                    `  FAILED: ${route.path} (${colorScheme}): ${error.message}`,
                );
                process.exitCode = 1;
            }
        }
    }

    await context.close();
    await browser.close();
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
