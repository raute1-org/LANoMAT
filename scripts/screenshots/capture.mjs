#!/usr/bin/env node
// Playwright headless capture for the documentation screenshot set.
//
// Standalone Node ESM script — NOT part of the Vite/app bundle. It assumes:
//   1. A running LANoMAT server reachable at APP_URL.
//   2. The database seeded with `ScreenshotSeeder` (fixed demo slugs, the
//      DEMO-2345 pairing code and the demo agent token).
//
// Usage:
//   npm run screenshots
//   APP_URL=http://127.0.0.1:8040 ONLY=dashboard,games-filter npm run screenshots
//   APP_URL=https://staging.lan.example SCREENSHOT_DIR=/tmp/shots npm run screenshots
//
// Knobs:
//   ONLY=a,b        capture just these route names (comma separated; the
//                   names are the route names below, e.g.
//                   ONLY=games-share,games-share-shares,servers,servers-all)
//   SCHEMES=light   limit colour schemes (default light,dark)
//   MOBILE=0        skip the 375 px pairs
//   COMPRESS=0      keep Playwright's raw PNGs
//   AGENT_CLI=...   path to clients/agent/dist/cli.js for the terminal shots
//   NO_TERMINAL=1   skip the two terminal shots
//
// Every console error, page error and failed HTTP request of a capture is
// printed as a summary at the end — "what you see is a finding".
//
// See scripts/screenshots/README.md for the full run book.

import { execFile } from 'node:child_process';
import { mkdir, stat, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

import { chromium } from '@playwright/test';

const execFileAsync = promisify(execFile);

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const APP_URL = (process.env.APP_URL ?? 'http://127.0.0.1:8040').replace(
    /\/$/,
    '',
);
// `SCREENSHOT_DIR` lets a docs run write into a scratch folder instead of the
// app's own docs/screenshots set (same script, one extra knob).
const OUTPUT_DIR = process.env.SCREENSHOT_DIR
    ? path.resolve(process.env.SCREENSHOT_DIR)
    : path.resolve(__dirname, '../../docs/screenshots');

const DESKTOP = { width: 1440, height: 900 };
const MOBILE = { width: 375, height: 812 };
const VIEWPORT = DESKTOP;

// Must match database/seeders/ScreenshotSeeder.php.
const EVENT_SLUG = 'screenshot-demo'; // live event
const RECAP_SLUG = 'screenshot-recap'; // finished event (M12 recap)
const UPCOMING_SLUG = 'screenshot-upcoming'; // registration-open event (M12 countdown)
const ORGA_EMAIL = 'screenshot-orga@example.test';
const ORGA_PASSWORD = 'screenshot-demo-password';
const PAIRING_CODE = 'DEMO-2345';
const AGENT_TOKEN = 'lmcp_screenshotAgentToken000000000000000000000000000';

// A fixed wall clock makes clock-derived UI (the LIVE clock, countdowns,
// "seit 14 Min") identical across runs. The seeded rows keep their own
// timestamps; this only freezes what the browser computes.
const CAPTURE_TIME = new Date('2026-11-21T19:30:00+01:00');

const ONLY = process.env.ONLY
    ? new Set(
          process.env.ONLY.split(',')
              .map((name) => name.trim())
              .filter(Boolean),
      )
    : null;
const SCHEMES = (process.env.SCHEMES ?? 'light,dark')
    .split(',')
    .map((scheme) => scheme.trim())
    .filter(Boolean);
const MOBILE_ENABLED = process.env.MOBILE !== '0';
const COMPRESS = process.env.COMPRESS !== '0';
const TERMINAL_ENABLED = process.env.NO_TERMINAL !== '1';

const AGENT_CLI =
    process.env.AGENT_CLI ??
    path.resolve(__dirname, '../../clients/agent/dist/cli.js');

/**
 * Routes to capture. Everything runs in the seeded orga session (the demo
 * pages require login), except routes marked `guest`.
 *
 * - `action(page)` runs after the page settled, e.g. toggling a filter or
 *   scrolling to the part of the page the screenshot is about.
 * - `resolve(page)` computes a path that needs a database id (tournament,
 *   Filament record) by reading the real page.
 * - `after(page)` runs after the frame was captured, e.g. to undo a table
 *   filter the next route would inherit through Filament's session state.
 * - `mobile: true` also captures a 375 px pair (name-mobile-light/dark.png).
 * - `fullPage: true` captures the whole document instead of the 1440×900
 *   frame — used only where the full form is the point (recipe edit).
 */
const ROUTES = [
    // Guest login gate. The logged-in `/` is Now, so this route uses its own
    // (signed-out) context to keep the gate and the start page distinct.
    { name: 'home', path: '/', guest: true, mobile: true },
    { name: 'dashboard', path: '/dashboard', mobile: true },
    { name: 'event-page', path: `/events/${EVENT_SLUG}`, mobile: true },
    { name: 'countdown', path: `/events/${UPCOMING_SLUG}` },
    { name: 'seating', path: `/events/${EVENT_SLUG}/seating` },
    { name: 'tournaments', path: `/events/${EVENT_SLUG}/tournaments` },
    {
        name: 'tournament-bracket',
        resolve: resolveTournamentPath,
        mobile: true,
    },
    { name: 'schedule', path: `/events/${EVENT_SLUG}/schedule` },
    { name: 'catering', path: `/events/${EVENT_SLUG}/catering` },
    { name: 'voting', path: `/events/${EVENT_SLUG}/polls` },
    { name: 'lfg', path: `/events/${EVENT_SLUG}/lfg` },
    // The list lives below the upload card; a full-page frame keeps every
    // file row (and its uploader line) intact instead of cutting one.
    {
        name: 'files',
        path: `/events/${EVENT_SLUG}/files`,
        fullPage: true,
    },
    {
        name: 'files-upload',
        path: `/events/${EVENT_SLUG}/files`,
        mobile: true,
    },
    { name: 'presence', path: `/events/${EVENT_SLUG}/presence` },
    { name: 'jukebox', path: `/events/${EVENT_SLUG}/jukebox` },
    { name: 'gallery', path: `/events/${EVENT_SLUG}/gallery` },
    { name: 'recap', path: `/events/${RECAP_SLUG}/recap` },
    { name: 'infoscreen', path: `/screen/${EVENT_SLUG}` },
    { name: 'infoscreen-recap', path: `/screen/${RECAP_SLUG}` },

    // Games library, the filter state, and one game detail page.
    { name: 'games', path: '/games' },
    {
        name: 'games-filter',
        path: '/games',
        action: async (page) => {
            const filter = page.getByRole('button', { name: /Nur LAN-Spiele/ });
            await filter.click();
            await page.waitForTimeout(250);

            // On the 375 px layout the results sit below the sidebar/filter;
            // bring the first LAN card into the frame.
            const firstCard = page
                .getByRole('button', { name: /Installieren/ })
                .first();

            if ((await firstCard.count()) > 0) {
                await firstCard.scrollIntoViewIfNeeded();
            }
        },
        mobile: true,
    },
    {
        name: 'game-ut2004',
        path: '/games/unreal-tournament-2004',
        action: async (page) => {
            // At 375 px the game list above the page is taller than the frame;
            // bring the game itself (title, actions) into it.
            if ((page.viewportSize()?.width ?? 0) >= 700) {
                return;
            }

            const title = page
                .getByRole('heading', {
                    name: 'Unreal Tournament 2004',
                    exact: true,
                })
                .first();

            if ((await title.count()) > 0) {
                await title.evaluate((node) => {
                    const top =
                        node.getBoundingClientRect().top + window.scrollY - 80;
                    window.scrollTo({
                        top: Math.max(0, top),
                        behavior: 'instant',
                    });
                });
                await page.waitForTimeout(300);
            }
        },
        mobile: true,
    },
    // A game a participant shared rather than a bundled recipe: its own page
    // with the server row and the launcher join.
    {
        name: 'game-shared',
        path: '/games/bunker-bash-deluxe',
    },

    // Game sharing (Nov 2026): the archive picker with the archive selected,
    // the filled description and the resulting "Deine geteilten Spiele" list
    // (waiting, published, archive gone) — three frames of the same page, each
    // readable on its own, the list also at 375 px.
    {
        name: 'games-share',
        resolve: resolveSharePath,
    },
    {
        name: 'games-share-form',
        resolve: resolveSharePath,
        action: fillShareForm,
    },
    {
        name: 'games-share-shares',
        path: '/games/share',
        action: scrollToShareList,
        mobile: true,
    },

    // Agent pairing: the code entry, the pending approval, the connected list.
    { name: 'mcp-connect', path: '/mcp/connect' },
    {
        name: 'mcp-connect-pending',
        path: `/mcp/connect?code=${PAIRING_CODE}`,
    },

    // Filament admin: dashboard, the review queue, the recipe forms.
    { name: 'admin', path: '/admin' },
    {
        // The review queue: the contributed filter, the file state and the
        // pull request state with its reason line. Filament keeps the column
        // choice in the session, so the capture sets it every time and clears
        // it afterwards (the other recipe shots need the full table).
        name: 'admin-recipes',
        path: '/admin/recipes',
        action: showReviewQueue,
        after: resetRecipeTable,
    },
    {
        name: 'admin-recipe-edit',
        resolve: resolveRecipeEditPath,
        action: (page) => scrollToText(page, 'Benötigte Dateien'),
    },
    {
        // A shared recipe: the file facts a reviewer needs, what it runs
        // ("Start und Launcher") and where it stands in the catalogue.
        name: 'admin-recipe-contributed',
        resolve: (page) =>
            resolveContributionEditPath(page, 'Neon Drift Arena'),
        action: (page) => scrollToText(page, 'Angaben zur Datei'),
    },
    {
        // The catalogue preview itself: what the checks would refuse, as a
        // draft and once published.
        name: 'admin-recipe-catalog',
        resolve: (page) =>
            resolveContributionEditPath(page, 'Neon Drift Arena'),
        action: scrollToPageEnd,
    },
    {
        // The one-step handoff: the confirmation before the orga approves the
        // pending archive and publishes the recipe. Never submitted.
        name: 'admin-recipe-publish-confirm',
        resolve: (page) =>
            resolveContributionEditPath(page, 'Neon Drift Arena'),
        action: openPublishConfirmation,
    },

    // Cross-event participant surfaces.
    { name: 'teams', path: '/teams' },
    { name: 'friends', path: '/friends' },
    { name: 'leaderboard', path: '/stats/leaderboard' },
    // The server surfaces of the install block and the occupant line: the
    // overview with one row opened, the cross-event browser with a card that
    // installs/joins, and the card whose game is not on the LAN right now.
    {
        name: 'servers',
        path: '/servers',
        action: async (page) => {
            const row = page
                .locator('button[aria-controls^="server-panel-"]')
                .first();

            if ((await row.count()) > 0) {
                await row.click();
                await page.waitForTimeout(400);
            }
        },
    },
    {
        name: 'servers-all',
        path: '/servers/all?game=Bunker%20Bash%20Deluxe',
    },
    {
        name: 'servers-all-lan-missing',
        path: '/servers/all?game=Pixel%20Rally%2099',
    },
    // The one download page (`/app`): the voice app and the launcher as a
    // list of downloads. `/launcher` and `/talk/client` only redirect here.
    {
        name: 'app-download',
        path: '/app',
    },
    // The notice a `lanomat://` click brings up when nothing on the machine
    // answers it, on a server card that really has a launcher join.
    {
        name: 'launcher-notice',
        path: '/servers/all?game=Bunker%20Bash%20Deluxe',
        action: showLauncherNotice,
    },
    { name: 'connections', path: '/settings/connections' },
    { name: 'talk', path: '/talk' },
    {
        name: 'notifications',
        path: '/dashboard',
        action: async (page) => {
            const bell = page.locator('button:has(svg.lucide-bell)').first();

            if ((await bell.count()) > 0) {
                await bell.click();
                await page.waitForTimeout(250);
            }
        },
    },
    { name: 'register', path: `/events/${UPCOMING_SLUG}/register` },

    // Orga check-in, participant and QR.
    { name: 'orga-checkin', path: `/orga/events/${EVENT_SLUG}/checkin` },
    { name: 'orga-checkin-qr', path: `/orga/events/${EVENT_SLUG}/checkin-qr` },

    // Filament resources that document the optional modules.
    { name: 'admin-events', path: '/admin/events' },
    { name: 'admin-users', path: '/admin/users' },
    { name: 'admin-teams', path: '/admin/teams' },
    { name: 'admin-seats', path: '/admin/seats' },
    { name: 'admin-games', path: '/admin/games' },
    { name: 'admin-shared-files', path: '/admin/shared-files' },
    { name: 'admin-event-photos', path: '/admin/event-photos' },
    { name: 'admin-food-orders', path: '/admin/food-orders' },
    { name: 'admin-schedule', path: '/admin/schedule-items' },
    { name: 'admin-news', path: '/admin/news-posts' },
    { name: 'admin-remote-hosts', path: '/admin/remote-hosts' },
    { name: 'admin-custom-servers', path: '/admin/custom-servers' },
    { name: 'admin-server-links', path: '/admin/server-links' },
    { name: 'admin-room-control', path: '/admin/room-control' },
    { name: 'admin-disputes', path: '/admin/tournaments/disputes' },

    // Terminal shots, rendered from the real CLI output (not the app).
    { name: 'agent-install-terminal', terminal: 'install' },
    { name: 'agent-doctor-terminal', terminal: 'doctor' },
];

/**
 * Read an Inertia page's props from the server-rendered `data-page` payload.
 * The link hrefs are generated client-side, so the JSON is the reliable
 * source for a database id.
 *
 * @returns {Promise<Record<string, unknown>>}
 */
async function inertiaProps(page, url) {
    await page.goto(`${APP_URL}${url}`, { waitUntil: 'networkidle' });

    return page.evaluate(() => {
        // Inertia v2 renders the page payload as a JSON script tag:
        // <script data-page="app" type="application/json">{...}</script>
        const payload =
            document.querySelector('script[data-page]')?.textContent;

        return payload ? (JSON.parse(payload).props ?? {}) : {};
    });
}

async function resolveTournamentPath(page) {
    const props = await inertiaProps(page, `/events/${EVENT_SLUG}/tournaments`);
    const tournaments = Array.isArray(props.tournaments)
        ? props.tournaments
        : [];
    const tournament =
        tournaments.find((entry) => entry.name === 'Counter-Strike 2 Cup') ??
        tournaments[0];

    if (!tournament || typeof tournament.id !== 'number') {
        throw new Error(
            'No seeded tournament on the demo event — run the ScreenshotSeeder first.',
        );
    }

    return `/tournaments/${tournament.id}`;
}

async function resolveRecipeEditPath(page) {
    await page.goto(`${APP_URL}/admin/recipes`, { waitUntil: 'networkidle' });

    // The published UT2004 recipe is the interesting form: real requirements
    // (iso/patch/mod) and real steps, not a draft with three empty rows.
    const row = page
        .locator('tr', { hasText: 'unreal-tournament-2004' })
        .first();
    const scoped = row.locator('a[href$="/edit"]').first();
    const edit =
        (await scoped.count()) > 0
            ? scoped
            : page.locator('a[href*="/admin/recipes/"][href$="/edit"]').first();

    if ((await edit.count()) === 0) {
        throw new Error(
            'No recipe edit link on /admin/recipes — is the GameRecipes module enabled and seeded?',
        );
    }

    const href = await edit.getAttribute('href');

    if (!href) {
        throw new Error('Recipe edit link has no href.');
    }

    return href.replace(APP_URL, '');
}

/**
 * The edit page of one contributed recipe by title. The admin list is
 * filtered/column-trimmed by earlier captures in the same session; the row is
 * contributed either way, so the lookup stays valid.
 */
async function resolveContributionEditPath(page, title) {
    await page.goto(`${APP_URL}/admin/recipes`, { waitUntil: 'networkidle' });

    const row = page.locator('tr', { hasText: title }).first();
    const edit = row.locator('a[href$="/edit"]').first();

    if ((await edit.count()) === 0) {
        throw new Error(
            `No recipe row "${title}" on /admin/recipes — run the ScreenshotSeeder first.`,
        );
    }

    const href = await edit.getAttribute('href');

    if (!href) {
        throw new Error(`The edit link of "${title}" has no href.`);
    }

    return href.startsWith(APP_URL)
        ? href.slice(APP_URL.length)
        : new URL(href, APP_URL).pathname;
}

/**
 * `/games/share?file=<id>` with one of the seeded archives preselected: the
 * page only honours `?file=` for files it shows and accepts.
 */
async function resolveSharePath(page) {
    const props = await inertiaProps(page, '/games/share');
    const files = Array.isArray(props.files) ? props.files : [];
    const archive = files.find(
        (file) =>
            typeof file === 'object' &&
            file !== null &&
            file.usable === true &&
            typeof file.name === 'string' &&
            file.name.endsWith('.zip'),
    );

    if (!archive || typeof archive.id !== 'number') {
        throw new Error(
            'No usable .zip archive on /games/share — run the ScreenshotSeeder first.',
        );
    }

    return `/games/share?file=${archive.id}`;
}

/** The share form of a game whose archive is already picked. */
async function fillShareForm(page) {
    await page.locator('#share-title').fill('Neon Drift Arena');
    await page.locator('#share-executable').fill('NeonDrift.exe');
    await page
        .locator('#share-summary')
        .fill(
            'Arcade-Driften auf vier Neon-Strecken — entpacken und sofort starten.',
        );
    await page.locator('#share-version').fill('1.0');
    await page.locator('#share-players').fill('2-8');
    // Linux opens the runner choice (Wine is the page's default).
    await page.locator('#share-platform-linux').check();
    await page.waitForTimeout(250);
}

/** The panel's review queue: contributed rows, file state, pull request state. */
async function showReviewQueue(page) {
    await page.getByRole('button', { name: 'Filtern' }).click();
    await page.waitForTimeout(300);
    await page
        .locator('button[id="tableFiltersForm.contributed.isActive"]')
        .click();
    await page.waitForTimeout(300);
    await page.getByRole('button', { name: 'Filter anwenden' }).click();
    await page.waitForTimeout(600);

    // Only the columns the picture is about. Status, Datei, Dateien and
    // Aktualisiert are not toggleable; the rest would push "Pull Request" and
    // its reason out of the frame.
    await page.getByRole('button', { name: 'Spalten auswählen' }).click();
    await page.waitForTimeout(300);

    for (const label of [
        'Slug',
        'Version',
        'In der Spieleliste zeigen',
        'Zuletzt geprüft',
        'Beitrag von',
    ]) {
        const box = page
            .locator('.fi-ta-col-manager-item')
            .filter({ hasText: label })
            .first()
            .locator('input');

        if (
            (await box.count()) > 0 &&
            (await box.isChecked()) &&
            (await box.isEnabled())
        ) {
            await box.uncheck();
            await page.waitForTimeout(200);
        }
    }

    const pullRequest = page
        .locator('.fi-ta-col-manager-item')
        .filter({ hasText: 'Pull Request' })
        .first()
        .locator('input');

    if ((await pullRequest.count()) > 0 && !(await pullRequest.isChecked())) {
        await pullRequest.check();
        await page.waitForTimeout(200);
    }

    await page.getByRole('button', { name: 'Spalten anwenden' }).click();
    await page.waitForTimeout(600);
}

/** The "Dateien freigeben und veröffentlichen" confirmation, left open. */
async function openPublishConfirmation(page) {
    const action = page.getByRole('button', {
        name: 'Dateien freigeben und veröffentlichen',
    });

    if ((await action.count()) === 0) {
        throw new Error(
            'The contributed recipe has no pending archive — run the ScreenshotSeeder first.',
        );
    }

    await action.click();
    await page.waitForTimeout(900);
}

/**
 * Click the server card's "Im Launcher beitreten" and wait for the honest
 * notice: headless Chromium has no handler for `lanomat://`, so the page is
 * still focused when the composable's timeout (2.5 s) passes.
 */
async function showLauncherNotice(page) {
    const join = page
        .locator('[data-test=server-install-actions] a')
        .filter({ hasText: 'Im Launcher beitreten' })
        .first();

    if ((await join.count()) === 0) {
        throw new Error(
            'No server card with a launcher join — run the ScreenshotSeeder first.',
        );
    }

    await join.click();
    await page.waitForTimeout(3200);

    // The aborted `lanomat://` navigation leaves the area unpainted in
    // headless Chromium; scrolling the card into view repaints it.
    const card = page.locator('article').filter({ has: join }).first();

    if ((await card.count()) > 0) {
        await card.evaluate((node) => {
            const top = node.getBoundingClientRect().top + window.scrollY - 100;
            window.scrollTo({ top: Math.max(0, top), behavior: 'instant' });
        });
        await page.waitForTimeout(700);
    }
}

/**
 * The share page's own list. Its heading carries the section number as a
 * separate text node, so its accessible name is "03 Deine geteilten Spiele" —
 * the id is the reliable anchor.
 */
async function scrollToShareList(page) {
    const heading = page.locator('#share-shares-heading').first();

    if ((await heading.count()) === 0) {
        return;
    }

    // Absolute position, clamped by the browser: near the document end a
    // scrollIntoView + scrollBy pair would move the content back down again.
    await heading.evaluate((node) => {
        const top = node.getBoundingClientRect().top + window.scrollY - 140;
        window.scrollTo({ top: Math.max(0, top), behavior: 'instant' });
    });
    await page.waitForTimeout(300);
}

/** The bottom of the document (a long Filament form's last section). */
async function scrollToPageEnd(page) {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(400);
}

/**
 * Undo what {@see showReviewQueue} did to the table: Filament keeps the filter
 * and the column choice in the session, and the other recipe captures (and the
 * next colour scheme) need the full, default table.
 */
async function resetRecipeTable(page) {
    const filterButton = page.getByRole('button', { name: 'Filtern' });

    if ((await filterButton.count()) > 0) {
        await filterButton.click();
        await page.waitForTimeout(250);

        const resetFilter = page.getByRole('button', {
            name: 'Filter zurücksetzen',
            exact: true,
        });

        if ((await resetFilter.count()) > 0) {
            await resetFilter.click();
            await page.waitForTimeout(400);
        }

        await page.keyboard.press('Escape');
        await page.waitForTimeout(250);
    }

    const columnsButton = page.getByRole('button', {
        name: 'Spalten auswählen',
    });

    if ((await columnsButton.count()) > 0) {
        await columnsButton.click();
        await page.waitForTimeout(250);

        const resetColumns = page.getByRole('button', {
            name: 'Zurücksetzen',
            exact: true,
        });

        if ((await resetColumns.count()) > 0) {
            await resetColumns.click();
            await page.waitForTimeout(250);
        }

        const apply = page.getByRole('button', { name: 'Spalten anwenden' });

        if ((await apply.count()) > 0) {
            await apply.click();
            await page.waitForTimeout(400);
        }
    }
}

async function scrollToText(page, text) {
    // Exact heading first (a loose match also hits "Zu benötigte Dateien
    // hinzufügen" below the repeater and lands past the section).
    const exact = page.getByText(text, { exact: true });
    const target =
        (await exact.count()) > 0
            ? exact.first()
            : page.getByText(text, { exact: false }).first();

    if ((await target.count()) === 0) {
        return;
    }

    // Filament lays the form out in the document flow; `scrollIntoView`
    // with an explicit block is reliable where `scrollIntoViewIfNeeded`
    // (which only scrolls the nearest scrollable ancestor) can no-op.
    await target.evaluate((node) => {
        node.scrollIntoView({ block: 'start', behavior: 'instant' });
        window.scrollBy(0, -110);
    });
    await page.waitForTimeout(300);
}

async function loginAsOrga(context, baseURL) {
    // This app is Discord-OAuth-only in the UI, but Fortify's `/login`
    // (email+password) route is still live. The seeded orga gets a fixed
    // local password purely so this script can authenticate headlessly.
    // Laravel relies on the XSRF-TOKEN cookie, echoed as X-XSRF-TOKEN.
    await context.request.get(`${baseURL}/login`);

    const cookies = await context.cookies(baseURL);
    const xsrfCookie = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');

    if (!xsrfCookie) {
        throw new Error(
            'Could not find an XSRF-TOKEN cookie after GET /login — is the server running?',
        );
    }

    const response = await context.request.post(`${baseURL}/login`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.value) },
        form: { email: ORGA_EMAIL, password: ORGA_PASSWORD },
    });

    if (!response.ok() && response.status() !== 302) {
        throw new Error(
            `Login as seeded orga failed (status ${response.status()}). Did you run "php artisan db:seed --class=ScreenshotSeeder"?`,
        );
    }
}

/** Settle fonts, images and reduced-motion state before the frame. */
async function settle(page) {
    await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all(
            Array.from(document.images)
                .filter((image) => !image.complete)
                .map((image) => image.decode().catch(() => undefined)),
        );

        const style = document.createElement('style');
        style.textContent =
            '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}';
        document.head.append(style);
    });
    await page.waitForTimeout(350);
}

/**
 * Re-encode the PNG as a 256-colour palette with maximum compression. Flat
 * UI colours stay crisp; screenshots drop from ~300 KB to ~90 KB, below the
 * docs' 250 KB budget. `-dither None` keeps the result deterministic.
 */
async function compressPng(file) {
    if (!COMPRESS) {
        return;
    }

    const before = (await stat(file)).size;

    try {
        await execFileAsync('magick', [
            file,
            '-strip',
            '-dither',
            'None',
            '-colors',
            '256',
            '-define',
            'png:compression-level=9',
            `PNG8:${file}`,
        ]);
    } catch (error) {
        if (error.code === 'ENOENT') {
            throw new Error(
                'ImageMagick (`magick`) is required to compress screenshots; install it or set COMPRESS=0.',
            );
        }

        throw error;
    }

    const after = (await stat(file)).size;

    if (after > 250 * 1024) {
        console.error(
            `  WARN ${path.basename(file)} is ${(after / 1024).toFixed(0)} KB (>250 KB budget)`,
        );
    }

    console.log(
        `  wrote ${path.relative(OUTPUT_DIR, file)} (${(after / 1024).toFixed(0)} KB, was ${(before / 1024).toFixed(0)} KB)`,
    );
}

/**
 * Console errors, page errors, failed requests and 4xx/5xx responses of the
 * whole run, printed as one summary at the end. Some are expected (the
 * `lanomat://` click, the boost dev logger); they are listed, not judged.
 */
const diagnostics = [];

function watchPage(page, route, colorScheme, viewport) {
    const note = (kind, text) =>
        diagnostics.push({
            route: route.name,
            scheme: colorScheme,
            width: viewport.width,
            kind,
            text,
        });

    page.on('console', (message) => {
        if (message.type() === 'error') {
            note('console', message.text());
        }
    });
    page.on('pageerror', (error) => note('pageerror', error.message));
    page.on('requestfailed', (request) =>
        note(
            'request',
            `${request.method()} ${request.url()} — ${request.failure()?.errorText ?? 'failed'}`,
        ),
    );
    page.on('response', (response) => {
        if (response.status() >= 400) {
            note('http', `${response.status()} ${response.url()}`);
        }
    });
}

function reportDiagnostics() {
    if (diagnostics.length === 0) {
        console.log('\nNo console errors, page errors or failed requests.');

        return;
    }

    console.log(
        `\n${diagnostics.length} console error(s) / failed request(s):`,
    );

    for (const entry of diagnostics) {
        console.log(
            `  ${entry.route} (${entry.scheme}, ${entry.width} px) [${entry.kind}] ${entry.text}`,
        );
    }
}

async function captureRoute(
    context,
    route,
    colorScheme,
    viewport,
    suffix = '',
) {
    const page = await context.newPage();
    watchPage(page, route, colorScheme, viewport);
    await page.setViewportSize(viewport);
    await page.emulateMedia({ colorScheme, reducedMotion: 'reduce' });

    // Freeze Date-derived UI; timers keep running but reduced motion and the
    // settle pass make the frame stable.
    await page.clock.setFixedTime(CAPTURE_TIME);

    const targetPath = route.resolve ? await route.resolve(page) : route.path;

    await page.goto(`${APP_URL}${targetPath}`, { waitUntil: 'networkidle' });
    await settle(page);

    if (route.action) {
        await route.action(page);
        await settle(page);
    }

    const fileName = `${route.name}${suffix}-${colorScheme}.png`;

    await page.screenshot({
        path: path.join(OUTPUT_DIR, fileName),
        fullPage: route.fullPage === true,
    });

    if (route.after) {
        await route.after(page);
    }

    await page.close();

    await compressPng(path.join(OUTPUT_DIR, fileName));
}

/** The branded terminal card around the real CLI output. */
function terminalHtml({ title, command, output, scheme, fontSize = 13 }) {
    const dark = scheme === 'dark';
    const palette = dark
        ? {
              page: '#0b0c0e',
              panel: '#121417',
              border: '#262a30',
              text: '#e7e5e4',
              muted: '#8a8f98',
              amber: '#f5a524',
              chrome: '#1b1e23',
          }
        : {
              page: '#f3f1ec',
              panel: '#ffffff',
              border: '#d9d5cc',
              text: '#141414',
              muted: '#6b6b6b',
              amber: '#b26a00',
              chrome: '#e9e5dd',
          };

    const escape = (value) =>
        value
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;');

    const lines = output
        .replaceAll('\r', '')
        .split('\n')
        .filter((line) => line.trim() !== '')
        .map((line) => {
            const safe = escape(line);
            const highlighted = safe
                .replaceAll(
                    '✔',
                    `<span style="color:${palette.amber}">✔</span>`,
                )
                .replaceAll(
                    '✖',
                    `<span style="color:${palette.amber}">✖</span>`,
                );

            return `<div>${highlighted}</div>`;
        })
        .join('');

    return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; }
  body {
    background: ${palette.page};
    color: ${palette.text};
    font-family: "JetBrains Mono", "DejaVu Sans Mono", "SFMono-Regular", Consolas, monospace;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    min-height: 100vh;
    padding: 48px;
  }
  .card {
    width: 1280px;
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid ${palette.border};
    background: ${palette.panel};
    box-shadow: 0 28px 80px rgba(0,0,0,${dark ? '0.45' : '0.12'});
  }
  .bar {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px;
    background: ${palette.chrome};
    border-bottom: 1px solid ${palette.border};
    font-size: 12px; color: ${palette.muted};
  }
  .dot { width: 10px; height: 10px; border-radius: 50%; background: ${palette.border}; }
  .dot:first-child { background: ${palette.amber}; }
  .title { margin-left: 8px; letter-spacing: .02em; }
  .body { padding: 22px 26px 26px; font-size: ${fontSize}px; line-height: 1.55; }
  .prompt { color: ${palette.amber}; font-weight: 700; }
  .cmd { color: ${palette.text}; }
  pre { margin: 0; white-space: pre-wrap; }
  .out { margin-top: 14px; color: ${palette.text}; }
  .out div { min-height: 19px; }
  .footer { margin-top: 16px; font-size: 11.5px; color: ${palette.muted}; }
</style>
</head>
<body>
  <div class="card">
    <div class="bar">
      <span class="dot"></span><span class="dot"></span><span class="dot"></span>
      <span class="title">${escape(title)}</span>
    </div>
    <div class="body">
      <div><span class="prompt">$</span> <span class="cmd">${escape(command)}</span></div>
      <pre class="out">${lines}</pre>
      <div class="footer">LANoMAT agent · real CLI output from the seeded demo server</div>
    </div>
  </div>
</body>
</html>`;
}

let activeBrowser = null;

async function captureTerminal(kind, scheme) {
    if (!(await exists(AGENT_CLI))) {
        throw new Error(
            `Agent CLI not found at ${AGENT_CLI}. Build it with "cd clients/agent && npm ci && npm run build" or set AGENT_CLI.`,
        );
    }

    const configDir = path.join(
        process.env.TMPDIR ?? '/tmp/opencode',
        'lanomat-w40-agent',
    );
    await mkdir(configDir, { recursive: true });
    await writeFile(
        path.join(configDir, 'agent.json'),
        JSON.stringify(
            {
                url: APP_URL,
                token: AGENT_TOKEN,
                token_expires_at: new Date(
                    Date.now() + 90 * 24 * 3600 * 1000,
                ).toISOString(),
                user: { id: 1, name: 'Screenshot Orga', role: 'orga' },
            },
            null,
            2,
        ),
        { mode: 0o600 },
    );

    const env = {
        ...process.env,
        LANOMAT_CONFIG_DIR: configDir,
        NO_COLOR: '1',
        TERM: 'dumb',
        CI: '1',
    };
    delete env.LANOMAT_TOKEN;

    const args =
        kind === 'install'
            ? [
                  'install',
                  'unreal-tournament-2004',
                  '--dry-run',
                  '-y',
                  '--os',
                  'linux',
                  '--event',
                  EVENT_SLUG,
              ]
            : ['doctor'];

    let output = '';

    try {
        const result = await execFileAsync(
            process.execPath,
            [AGENT_CLI, ...args],
            {
                env,
                timeout: 60_000,
                maxBuffer: 4 * 1024 * 1024,
            },
        );
        output = `${result.stdout}${result.stderr}`;
    } catch (error) {
        output = `${error.stdout ?? ''}${error.stderr ?? ''}`;

        if (output.trim() === '') {
            throw error;
        }
    }

    // Presentation-only cleanup: no token, no private hostname, no operator
    // home directory (the CLI prints real paths on purpose).
    output = output
        .replace(/lmcp_[A-Za-z0-9]+/g, 'lmcp_••••••••')
        .replaceAll(configDir, '/home/demo-laptop/.config/lanomat')
        .replaceAll(os.homedir(), '/home/demo-laptop')
        .replaceAll('reneil-thinkpad', 'demo-laptop')
        .replaceAll('reneil', 'demo');

    const title =
        kind === 'install'
            ? 'lanomat-agent — install unreal-tournament-2004 --dry-run'
            : 'lanomat-agent — doctor';
    const command = `lanomat-agent ${args.join(' ')}`;

    const page = await activeBrowser.newPage();
    await page.setViewportSize(DESKTOP);

    // The install plan is longer than one screen. Shrink the type a little
    // until the whole card fits, then grow the frame to the content (never
    // below 900 px, never so tall that the PNG budget breaks).
    let fontSize = 13;
    let height = DESKTOP.height;

    for (let attempt = 0; attempt < 4; attempt++) {
        await page.setContent(
            terminalHtml({ title, command, output, scheme, fontSize }),
            { waitUntil: 'load' },
        );
        await settle(page);

        height = await page.evaluate(() =>
            Math.ceil(document.body.getBoundingClientRect().height),
        );

        if (height <= 1500 || fontSize <= 10) {
            break;
        }

        fontSize -= 1;
    }

    await page.setViewportSize({
        width: DESKTOP.width,
        height: Math.max(900, height),
    });
    await page.waitForTimeout(150);

    const fileName = `agent-${kind}-terminal-${scheme}.png`;
    await page.screenshot({ path: path.join(OUTPUT_DIR, fileName) });
    await page.close();
    await compressPng(path.join(OUTPUT_DIR, fileName));
}

async function exists(file) {
    try {
        await stat(file);

        return true;
    } catch {
        return false;
    }
}

async function main() {
    await mkdir(OUTPUT_DIR, { recursive: true });

    const browser = await chromium.launch();
    activeBrowser = browser;

    const authContext = await browser.newContext({
        viewport: VIEWPORT,
        baseURL: APP_URL,
    });
    const guestContext = await browser.newContext({
        viewport: VIEWPORT,
        baseURL: APP_URL,
    });

    await loginAsOrga(authContext, APP_URL);

    const shouldRun = (route) => ONLY === null || ONLY.has(route.name);

    for (const route of ROUTES.filter(shouldRun)) {
        for (const colorScheme of SCHEMES) {
            console.log(`Capturing ${route.name} (${colorScheme})...`);

            try {
                if (route.terminal) {
                    if (!TERMINAL_ENABLED) {
                        continue;
                    }

                    await captureTerminal(route.terminal, colorScheme);
                    continue;
                }

                const context = route.guest ? guestContext : authContext;
                await captureRoute(context, route, colorScheme, VIEWPORT);

                if (route.mobile && MOBILE_ENABLED) {
                    await captureRoute(
                        context,
                        route,
                        colorScheme,
                        MOBILE,
                        '-mobile',
                    );
                }
            } catch (error) {
                console.error(
                    `  FAILED: ${route.name} (${colorScheme}): ${error.message}`,
                );
                process.exitCode = 1;
            }
        }
    }

    await authContext.close();
    await guestContext.close();
    await browser.close();

    reportDiagnostics();
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
