// Game-sharing journey smoke test (real browser).
//
// The real-browser twin of tests/Feature/GameRecipes/GameSharingStoryTest.php
// and of the "game sharing" card in docs/superpowers/plans/2026-11-21-game-
// sharing-swarm.md (section 8): three accounts drive the journey the PHP suite
// can only assert the server side of — share an archive, refuse the bad ones,
// review and publish in the panel, find the game, join its server, and watch
// what a `lanomat://` click does in a browser that has no launcher installed.
//
// Prerequisites:
//   php artisan migrate:fresh --force
//   php artisan storage:link
//   php artisan db:seed --class=GameSharingSmokeSeeder
//   a running app at APP_URL (form uploads need FILES_STORE=local, the default)
//
// Usage:
//   APP_URL=http://127.0.0.1:8000 node scripts/smoke/game-sharing-smoke.mjs
//
// Knobs:
//   SMOKE_BROWSER=…     chromium (default), firefox or webkit
//   CHROMIUM_PATH=…     use a specific Chromium binary (Playwright's own or a
//                       system one); default: Playwright's bundled browser
//   SMOKE_SHOT_DIR=…    where the per-step screenshots go (default: a temp dir)
//   SMOKE_STRICT=1      findings (known application defects) also fail the run
//
// It is NOT wired into CI. See scripts/smoke/README.md.

import { mkdir, readdir } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

import { chromium, firefox, webkit } from '@playwright/test';

const APP_URL = (process.env.APP_URL ?? 'http://localhost:8000').replace(
    /\/$/,
    '',
);

// Must match database/seeders/GameSharingSmokeSeeder.php.
const PASSWORD = 'smoke-demo-password';
const SHARER_EMAIL = 'smoke-sharer@example.test';
const ORGA_EMAIL = 'smoke-orga@example.test';
const PLAYER_EMAIL = 'smoke-player@example.test';
const OUTSIDER_EMAIL = 'smoke-outsider@example.test';
const EVENT_SLUG = 'smoke-lan';
const GAME_SLUG = 'tiny-racer';
const GAME_TITLE = 'Tiny Racer';
const SHARER_NAME = 'Mira Teilnehmerin';
const PLAYER_NAME = 'Piet Mitspieler';
const HIDDEN_NAME = 'Hanna Versteckt';
// The plain `address:port` server (the launcher cannot name it) and the one
// that advertises its own `steam://connect/...` join URL.
const SERVER_TITLE = 'Tiny Racer · Halle 3';
const SERVER_TITLE_LINKED = 'Tiny Racer · Bühne 2';
const LINKED_CONNECT = 'steam://connect/203.0.113.6:27016';
const ARCHIVE_NAME = 'tiny-racer.zip';
// The placeholder launcher build GameSharingSmokeSeeder publishes (same name).
const LAUNCHER_BUILD_NAME = 'lanomat-launcher-0.0.0-smoke-linux-x86_64.tar.gz';
const EXECUTABLE = 'bin/TinyRacer.exe';

const STEP_TIMEOUT_MS = 15_000;
// The launcher-link attempt waits 2.5 s for a takeover before it says so; that
// is the one honest sleep in this script (the "opened" confirmation then
// clears after 6 s).
const NOTICE_TIMEOUT_MS = 6_000;
const OPENED_RESET_TIMEOUT_MS = 9_000;

const SHOT_DIR = process.env.SMOKE_SHOT_DIR
    ? path.resolve(process.env.SMOKE_SHOT_DIR)
    : path.join(os.tmpdir(), 'lanomat-game-sharing-smoke');

/** Everything a step observed; the report prints it in one block at the end. */
const report = {
    steps: [],
    findings: [],
    console: [],
    http: [],
    pageErrors: [],
    requests: [],
};

let currentStep = 'start';

// --- tiny fixtures ---------------------------------------------------------

/**
 * A minimal stored-entry ZIP (no compression, no dependencies): exactly what
 * the share page accepts and what a "ready to run" game folder looks like.
 *
 * @param {Record<string, string | Buffer>} entries path → bytes
 * @returns {Buffer}
 */
function buildZip(entries) {
    const local = [];
    const central = [];
    let offset = 0;

    for (const [name, content] of Object.entries(entries)) {
        const body = Buffer.isBuffer(content)
            ? content
            : Buffer.from(content, 'utf8');
        const nameBytes = Buffer.from(name, 'utf8');
        const crc = crc32(body);

        const header = Buffer.alloc(30);
        header.writeUInt32LE(0x04034b50, 0); // local file header
        header.writeUInt16LE(20, 4); // version needed
        header.writeUInt16LE(0, 6); // flags
        header.writeUInt16LE(0, 8); // stored
        header.writeUInt16LE(0, 10); // time
        header.writeUInt16LE(0, 12); // date
        header.writeUInt32LE(crc, 14);
        header.writeUInt32LE(body.length, 18);
        header.writeUInt32LE(body.length, 22);
        header.writeUInt16LE(nameBytes.length, 26);
        header.writeUInt16LE(0, 28);

        local.push(header, nameBytes, body);

        const entry = Buffer.alloc(46);
        entry.writeUInt32LE(0x02014b50, 0); // central directory
        entry.writeUInt16LE(20, 4);
        entry.writeUInt16LE(20, 6);
        entry.writeUInt16LE(0, 8);
        entry.writeUInt16LE(0, 10);
        entry.writeUInt16LE(0, 12);
        entry.writeUInt16LE(0, 14);
        entry.writeUInt32LE(crc, 16);
        entry.writeUInt32LE(body.length, 20);
        entry.writeUInt32LE(body.length, 24);
        entry.writeUInt16LE(nameBytes.length, 28);
        entry.writeUInt32LE(offset, 42);

        central.push(entry, nameBytes);
        offset += header.length + nameBytes.length + body.length;
    }

    const directory = Buffer.concat(central);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(Object.keys(entries).length, 8);
    end.writeUInt16LE(Object.keys(entries).length, 10);
    end.writeUInt32LE(directory.length, 12);
    end.writeUInt32LE(offset, 16);

    return Buffer.concat([...local, directory, end]);
}

/** CRC-32 (the ZIP checksum), no dependency. */
function crc32(buffer) {
    let crc = 0xffffffff;

    for (const byte of buffer) {
        crc ^= byte;

        for (let bit = 0; bit < 8; bit++) {
            crc = crc & 1 ? (crc >>> 1) ^ 0xedb88320 : crc >>> 1;
        }
    }

    return (crc ^ 0xffffffff) >>> 0;
}

/** The run's game archive: a few KB, a placeholder "exe", a readme. */
function gameArchive() {
    return buildZip({
        'bin/TinyRacer.exe': 'MZ placeholder — not a real executable\n'.repeat(
            8,
        ),
        'README.txt': 'Tiny Racer, shared in the game-sharing smoke test.\n',
    });
}

// --- browser plumbing ------------------------------------------------------

/** The engine under test: chromium (default), firefox or webkit. */
const ENGINE = (process.env.SMOKE_BROWSER ?? 'chromium').toLowerCase();

const ENGINES = { chromium, firefox, webkit };

/**
 * The chosen engine, with one Chromium fallback: this host's Playwright
 * package and the browser cache can be one revision apart, in which case the
 * default launch fails with "Executable doesn't exist" while a usable
 * Chromium sits in the same cache. Never downloads anything.
 */
async function launchBrowser() {
    const engine = ENGINES[ENGINE];

    if (engine === undefined) {
        throw new Error(
            `SMOKE_BROWSER must be one of ${Object.keys(ENGINES).join(', ')}`,
        );
    }

    if (ENGINE !== 'chromium') {
        return engine.launch();
    }

    if (process.env.CHROMIUM_PATH) {
        return chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
    }

    try {
        return await chromium.launch();
    } catch (error) {
        const message = String(error?.message ?? '');

        if (!message.includes("Executable doesn't exist")) {
            throw error;
        }

        const fromCache = await cachedChromium();

        if (fromCache === null) {
            throw error;
        }

        console.log(
            `  i Playwright's own revision is missing; using ${fromCache}`,
        );

        return chromium.launch({ executablePath: fromCache });
    }
}

/** Any full Chromium that is already in the Playwright browser cache. */
async function cachedChromium() {
    const root = path.join(
        process.env.PLAYWRIGHT_BROWSERS_PATH ??
            path.join(os.homedir(), '.cache'),
        process.env.PLAYWRIGHT_BROWSERS_PATH ? '' : 'ms-playwright',
    );

    let entries = [];

    try {
        entries = await readdir(root);
    } catch {
        return null;
    }

    const candidates = entries
        .filter((entry) => entry.startsWith('chromium-'))
        .sort()
        .reverse();

    for (const entry of candidates) {
        for (const binary of ['chrome-linux64/chrome', 'chrome-linux/chrome']) {
            const candidate = path.join(root, entry, binary);

            try {
                await readdir(path.dirname(candidate));

                return candidate;
            } catch {
                continue;
            }
        }
    }

    return null;
}

/** Collect what the page reports, tagged with the step that was running. */
function watch(page) {
    page.on('console', (message) => {
        if (message.type() === 'error') {
            report.console.push(`[${currentStep}] ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => {
        report.pageErrors.push(`[${currentStep}] ${error.message}`);
    });
    page.on('response', (response) => {
        if (response.status() >= 400) {
            report.http.push(
                `[${currentStep}] ${response.status()} ${response.url()}`,
            );
        }
    });
    page.on('request', (request) => {
        const url = request.url();

        if (url.startsWith('lanomat://')) {
            report.requests.push(`[${currentStep}] ${url}`);
        }
    });
}

/**
 * Log in through Fortify's email/password form. The participant UI is
 * Discord-only, but the seeded accounts (like the screenshot pipeline's) have
 * a fixed local password for headless runs.
 */
async function login(context, email) {
    await context.request.get(`${APP_URL}/login`);
    const cookies = await context.cookies(APP_URL);
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');

    if (!xsrf) {
        throw new Error(
            `No XSRF-TOKEN cookie after GET /login — is ${APP_URL} running?`,
        );
    }

    const response = await context.request.post(`${APP_URL}/login`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) },
        form: { email, password: PASSWORD },
    });

    if (!response.ok() && response.status() !== 302) {
        throw new Error(
            `Login as ${email} failed (status ${response.status()}). Did you run "php artisan db:seed --class=GameSharingSmokeSeeder"?`,
        );
    }
}

/** One logged-in browser context with one page. */
async function session(
    browser,
    email,
    viewport = { width: 1440, height: 900 },
) {
    const context = await browser.newContext({ viewport });

    await login(context, email);

    const page = await context.newPage();
    watch(page);

    return { context, page };
}

async function shot(page, name) {
    // A fixed sidebar is captured where the page was scrolled to; go to the top
    // so the full-page screenshot shows it in one piece.
    await page.evaluate(() =>
        window.scrollTo({ top: 0, left: 0, behavior: 'instant' }),
    );
    await page.screenshot({
        path: path.join(SHOT_DIR, `${name}.png`),
        fullPage: true,
    });
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

function assertIncludes(haystack, needle, what) {
    assert(
        haystack.includes(needle),
        `${what} does not contain “${needle}”; saw: ${JSON.stringify(haystack.slice(0, 400))}`,
    );
}

/** A recorded application defect: the journey works around it and goes on. */
function finding(message) {
    report.findings.push(`[${currentStep}] ${message}`);
    console.log(`  ! FINDING: ${message}`);
}

async function step(name, run) {
    currentStep = name;
    const started = Date.now();
    console.log(`\n▶ ${name}`);

    try {
        await run();
        report.steps.push({ name, ok: true, ms: Date.now() - started });
        console.log(`  ✓ ${name} (${Date.now() - started} ms)`);
    } catch (error) {
        report.steps.push({
            name,
            ok: false,
            ms: Date.now() - started,
            error: error.message,
        });
        console.error(`  ✗ ${name}: ${error.message}`);
    }
}

// --- the server row --------------------------------------------------------

/**
 * The block of the seeded server on one of the browser surfaces.
 *
 * `/servers/all` renders the server as a card with its actions in the open.
 * `/servers` keeps them behind the row's disclosure, so the row is expanded
 * the way a participant expands it (the header button) before anything is
 * asserted.
 */
async function serverScope(page, route, title = SERVER_TITLE) {
    await page.goto(`${APP_URL}${route}`, { waitUntil: 'networkidle' });

    if (route === '/servers/all') {
        const card = page.locator('article').filter({ hasText: title }).first();
        await card.waitFor({ timeout: STEP_TIMEOUT_MS });

        return card;
    }

    const toggle = page
        .locator('button[aria-expanded]')
        .filter({ hasText: title })
        .first();
    await toggle.waitFor({ timeout: STEP_TIMEOUT_MS });

    if ((await toggle.getAttribute('aria-expanded')) === 'false') {
        await toggle.click();
    }

    const panelId = await toggle.getAttribute('aria-controls');
    assert(panelId !== null, 'the server row has no panel to expand');
    const panel = page.locator(`#${panelId}`);
    await panel.waitFor({ timeout: STEP_TIMEOUT_MS });

    return panel;
}

// --- the launcher link -----------------------------------------------------

/**
 * The `LauncherLink` block around one visible action: the anchor plus the one
 * persistent live region and the state lines below it. Every launcher surface
 * renders the same component, so this one helper serves the server row, the
 * game page and the "Im Launcher öffnen" action.
 */
function launcherBlock(page, name) {
    const link = page.getByRole('link', { name }).first();
    const block = link.locator('xpath=ancestor::span[1]');

    return { link, block };
}

/**
 * Wait until Filament's modal has finished its fade-in: the element is
 * "visible" to Playwright while it is still at low opacity, which makes the
 * full-page screenshot unreadable.
 */
async function waitForModal(page) {
    await page.waitForFunction(
        () => {
            const element = document.querySelector('.fi-modal-window');

            if (!(element instanceof HTMLElement)) {
                return false;
            }

            const style = getComputedStyle(element);
            const box = element.getBoundingClientRect();

            return (
                style.opacity === '1' &&
                style.visibility === 'visible' &&
                box.width > 0 &&
                box.height > 0
            );
        },
        undefined,
        { timeout: STEP_TIMEOUT_MS },
    );
}

/** The visible "Öffne den Launcher …" line (the live region says it too). */
function tryingLine(block) {
    return block
        .locator('span[aria-hidden="true"]')
        .filter({ hasText: 'Öffne den Launcher' });
}

/** Record, in the page, what a scheme click does to focus and visibility. */
async function recordAttemptEvents(page) {
    await page.evaluate(() => {
        window.__smokeLauncherEvents = [];

        for (const event of ['blur', 'focus', 'pagehide']) {
            window.addEventListener(event, () =>
                window.__smokeLauncherEvents.push(event),
            );
        }

        document.addEventListener('visibilitychange', () =>
            window.__smokeLauncherEvents.push(
                `visibility:${document.visibilityState}`,
            ),
        );
    });
}

/**
 * The open notice must sit under its own button, aligned with it, and must not
 * push the row's other actions into the middle of the row (the row used
 * `items-center` before the first browser run). `scope` is the server row the
 * action lives in, on either server surface.
 */
async function assertNoticeLayout(scope, label, notice) {
    const button = scope.getByRole('link', { name: label }).first();
    const buttonBox = await button.boundingBox();
    const noticeBox = await notice.boundingBox();

    assert(
        buttonBox !== null && noticeBox !== null,
        'the launcher button or its notice has no box',
    );
    assert(
        noticeBox.y >= buttonBox.y + buttonBox.height - 2,
        `the notice (y ${noticeBox.y}) is not below its button (bottom ${buttonBox.y + buttonBox.height})`,
    );
    assert(
        Math.abs(noticeBox.x - buttonBox.x) <= 2,
        `the notice (x ${noticeBox.x}) is not aligned with its button (x ${buttonBox.x})`,
    );

    const other = scope
        .getByRole('link', {
            name: /Spiel installieren|Zum Spiel|Mitspieler suchen/,
        })
        .first();

    if ((await other.count()) > 0) {
        const otherBox = await other.boundingBox();
        assert(
            otherBox !== null && Math.abs(otherBox.y - buttonBox.y) <= 8,
            `the row's other action sits at y ${otherBox?.y} while the launcher button is at ${buttonBox.y} — the notice pushed it out of the action row`,
        );
    }
}

// --- the share page --------------------------------------------------------

/** Upload one archive through the page's own form and wait for its row. */
async function uploadArchive(page, name, buffer) {
    const before = await page
        .locator('#share-file-group input[type="radio"]')
        .count();

    await page.locator('#share-upload-file').setInputFiles({
        name,
        mimeType: 'application/zip',
        buffer,
    });
    await page.getByRole('button', { name: 'Hochladen' }).click();

    await page.waitForFunction(
        (count) =>
            document.querySelectorAll('#share-file-group input[type="radio"]')
                .length > count,
        before,
        { timeout: STEP_TIMEOUT_MS },
    );
}

/** The file id of the list row with this name, or null. */
async function archiveIdByName(page, name) {
    return page.evaluate((wanted) => {
        const label = [
            ...document.querySelectorAll('#share-file-group label'),
        ].find((element) => element.innerText.includes(wanted));
        const input = label?.querySelector('input[type="radio"]');

        return input instanceof HTMLInputElement ? Number(input.value) : null;
    }, name);
}

/** The id of the selected archive, or null. */
async function checkedArchiveId(page) {
    return page.evaluate(() => {
        const input = document.querySelector(
            '#share-file-group input[type="radio"]:checked',
        );

        return input instanceof HTMLInputElement ? Number(input.value) : null;
    });
}

/** Remove every upload of the account on the files page (the "Löschen" buttons). */
async function deleteAllOwnUploads(page) {
    await page.goto(`${APP_URL}/events/${EVENT_SLUG}/files`, {
        waitUntil: 'networkidle',
    });

    const deleteButton = page.getByRole('button', { name: 'Löschen' });

    for (let guard = 0; guard < 10; guard++) {
        const before = await deleteButton.count();

        if (before === 0) {
            return;
        }

        await deleteButton.first().click();

        // Wait for the list to settle, not for a fixed time.
        for (let wait = 0; wait < 50; wait++) {
            if ((await deleteButton.count()) < before) {
                break;
            }

            await page.waitForTimeout(100);
        }

        assert(
            (await deleteButton.count()) < before,
            'an upload could not be deleted on the files page',
        );
    }
}

// --- the journey -----------------------------------------------------------

const archive = gameArchive();

await mkdir(SHOT_DIR, { recursive: true });

const browser = await launchBrowser();
console.log(`  i engine: ${browser.browserType().name()} ${browser.version()}`);

/**
 * One session per account of the journey: the sharer, the orga, the player who
 * finds and joins, and one account that is not registered for the event.
 */
const sharer = await session(browser, SHARER_EMAIL);
const orga = await session(browser, ORGA_EMAIL);
const player = await session(browser, PLAYER_EMAIL);
const outsider = await session(browser, OUTSIDER_EMAIL);

await step('0 clean slate: a previous run’s recipe and uploads', async () => {
    // Running this smoke twice must work without re-seeding: the recipe of the
    // previous run is deleted in the panel (its file pin cascades), and every
    // account removes its own uploads on the files page. A first run finds
    // nothing and skips both.
    const { page } = orga;

    await page.goto(`${APP_URL}/admin/recipes`, { waitUntil: 'networkidle' });
    const row = page.locator('tr', { hasText: GAME_SLUG }).first();

    if ((await row.count()) > 0) {
        await row.getByRole('link', { name: 'Bearbeiten' }).click();
        await page.waitForURL(/\/admin\/recipes\/\d+\/edit/);
        await page.getByRole('button', { name: 'Löschen' }).first().click();

        await waitForModal(page);
        const modal = page.locator('.fi-modal-window').first();
        await modal.getByRole('button', { name: 'Löschen' }).click();
        await page
            .locator('tr', { hasText: GAME_SLUG })
            .first()
            .waitFor({ state: 'detached', timeout: STEP_TIMEOUT_MS });
        console.log('  i deleted the previous run’s recipe');
    }

    for (const account of [sharer, player]) {
        await deleteAllOwnUploads(account.page);
    }

    const remaining = await orga.page
        .goto(`${APP_URL}/admin/recipes`, { waitUntil: 'networkidle' })
        .then(() => orga.page.locator('tr', { hasText: GAME_SLUG }).count());
    assert(remaining === 0, 'a previous recipe survived the clean-slate step');
});

await step(
    '1 share: the upload is selected, keyboard-only, a hand-picked one wins',
    async () => {
        const { page } = sharer;

        await page.goto(`${APP_URL}/games`, { waitUntil: 'networkidle' });
        await page.getByRole('link', { name: 'Spiel teilen' }).first().click();
        await page.waitForURL(`${APP_URL}/games/share`);
        assert(
            (await page.locator('h1, [data-slot="page-title"]').count()) > 0,
            'the share page rendered no heading',
        );

        // Before anything is filled in, the page says what is missing. The
        // picker's own line is about the file for the next upload, never about
        // the archive that is already selected below it.
        const missing = page.locator('[data-test="share-missing"]');
        assertIncludes(
            await missing.innerText(),
            'Es fehlt noch: ein Archiv, ein Titel und die Startdatei.',
            'the missing-fields line',
        );
        const pickerLine = page.getByText(
            'Noch keine Datei zum Hochladen gewählt',
        );
        await pickerLine.first().waitFor({ timeout: STEP_TIMEOUT_MS });
        await shot(page, '01-share-empty');

        // 1a. A form upload selects the archive — the page promises it does.
        await uploadArchive(page, ARCHIVE_NAME, archive);
        const firstId = await archiveIdByName(page, ARCHIVE_NAME);
        assert(
            firstId !== null,
            `the uploaded archive ${ARCHIVE_NAME} is in no radio`,
        );
        assert(
            (await checkedArchiveId(page)) === firstId,
            `the uploaded archive was not selected (checked ${await checkedArchiveId(page)}, expected ${firstId})`,
        );
        assert(
            (await missing.innerText()) ===
                'Es fehlt noch: ein Titel und die Startdatei.',
            `the missing-fields line after the upload reads ${JSON.stringify(await missing.innerText())}`,
        );
        assert(
            await page
                .getByRole('button', { name: 'Spiel teilen' })
                .isDisabled(),
            'the submit is enabled without a title and a start file',
        );
        assert(
            (await page
                .getByText('Noch keine Datei zum Hochladen gewählt')
                .count()) > 0,
            'the picker line does not say that no file is chosen for the next upload',
        );
        await shot(page, '02-upload-selected');

        // 1b. A second upload is selected too, but a hand-picked archive wins:
        // the participant's own choice is only replaced by a new upload when
        // they have not picked one.
        await uploadArchive(page, 'tiny-racer-bonus.zip', archive);
        const bonusId = await archiveIdByName(page, 'tiny-racer-bonus.zip');
        assert(
            (await checkedArchiveId(page)) === bonusId,
            `the second upload was not selected (checked ${await checkedArchiveId(page)}, new ${bonusId})`,
        );

        // Picking the *other* archive by hand (it is not the selected one, so
        // this is a real click on the radio group).
        await page
            .locator(`#share-file-group input[value="${firstId}"]`)
            .check();
        await uploadArchive(page, 'tiny-racer-save.zip', archive);
        const saveId = await archiveIdByName(page, 'tiny-racer-save.zip');
        assert(
            (await checkedArchiveId(page)) === firstId,
            `the hand-picked archive was replaced by the new upload (checked ${await checkedArchiveId(page)}, picked ${firstId}, new ${saveId})`,
        );

        // 1c. Keyboard-only: Tab from the upload control to the submit button,
        // Space picks the archive, typing fills the fields, Enter submits.
        const seen = [];
        await page.locator('#share-upload-file').focus();

        for (let press = 0; press < 40; press++) {
            await page.keyboard.press('Tab');

            const control = await page.evaluate(() => {
                const element = document.activeElement;

                if (!(element instanceof HTMLElement)) {
                    return null;
                }

                return {
                    id: element.id,
                    tag: element.tagName.toLowerCase(),
                    type: element.getAttribute('type') ?? '',
                    name: element.getAttribute('name') ?? '',
                    text: (element.innerText ?? element.value ?? '')
                        .trim()
                        .slice(0, 40),
                };
            });

            if (control === null) {
                break;
            }

            seen.push(control);

            if (control.type === 'radio' && control.name === 'shared-file') {
                await page.keyboard.press('Space');
            }

            if (control.id === 'share-title') {
                await page.keyboard.type(GAME_TITLE);
            }

            if (control.id === 'share-executable') {
                await page.keyboard.type(EXECUTABLE);
            }

            if (
                control.tag === 'button' &&
                control.type === 'submit' &&
                control.text === 'Spiel teilen'
            ) {
                break;
            }
        }

        const reached = new Set(seen.map((control) => control.id));
        const expected = [
            'share-title',
            'share-platform-windows',
            'share-executable',
            'share-summary',
            'share-version',
            'share-players',
            'share-homepage',
        ];
        const unreadable = expected.filter((id) => !reached.has(id));
        assert(
            unreadable.length === 0,
            `Tab never reached ${unreadable.join(', ')} (saw ${[...reached].filter(Boolean).join(' → ')})`,
        );
        assert(
            seen.some((control) => control.name === 'shared-file'),
            'the radio (file) group is not in the Tab order',
        );
        assert(
            (await checkedArchiveId(page)) === firstId,
            'Space on the file radio changed the hand-picked archive',
        );
        assert(
            (await missing.innerText()) === '',
            'the missing-fields line still blocks the submit after typing the fields',
        );

        // Enter on the focused submit button (the same path a keyboard user takes).
        await page.keyboard.press('Enter');

        const toast = page.locator('[data-sonner-toast]');
        await toast.first().waitFor({ timeout: STEP_TIMEOUT_MS });
        assertIncludes(
            await toast.first().innerText(),
            'Entwurf für „Tiny Racer“ gespeichert.',
            'the success toast',
        );

        // The share is listed with its review state and no game link yet.
        const shares = page.locator('section', {
            hasText: 'Deine geteilten Spiele',
        });
        const row = shares.getByText(GAME_TITLE, { exact: true }).first();
        await row.waitFor({ timeout: STEP_TIMEOUT_MS });
        const shareText = await shares.innerText();
        assertIncludes(shareText, 'Die Orga prüft gerade', 'the share row');
        assertIncludes(shareText, ARCHIVE_NAME, 'the share row');
        assert(
            (await shares.locator(`a[href="/games/${GAME_SLUG}"]`).count()) ===
                0,
            'the share row already links the game before it is published',
        );
        await shot(page, '03-share-submitted');

        // 1d. After a successful share the next upload is selected again: the
        // "picked by hand" state was reset with the share.
        await uploadArchive(page, 'tiny-racer-next.zip', archive);
        const nextId = await archiveIdByName(page, 'tiny-racer-next.zip');
        assert(
            (await checkedArchiveId(page)) === nextId,
            `after a successful share the next upload was not selected (checked ${await checkedArchiveId(page)}, new ${nextId})`,
        );
        assert(
            (await page.locator('#share-title').inputValue()) === '',
            'the description fields survived the successful share',
        );

        // 375 px: the page has to stay usable without horizontal scrolling.
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto(`${APP_URL}/games/share`, { waitUntil: 'networkidle' });
        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - window.innerWidth,
        );
        assert(
            overflow <= 1,
            `the share page scrolls sideways at 375 px (${overflow} px)`,
        );
        assert(
            await page.locator('#share-title').isVisible(),
            'the title field is not visible at 375 px',
        );
        await shot(page, '04-share-375');
        await page.setViewportSize({ width: 1440, height: 900 });
    },
);

await step('2 refusals: unusable names, taken title, line break', async () => {
    const { page } = player;

    await page.goto(`${APP_URL}/games/share`, { waitUntil: 'networkidle' });

    // A real archive whose name a recipe cannot quote: listed, disabled, and
    // with the reason the share action would refuse it with. Three shapes:
    // a star, a colon and a leading dot.
    for (const name of ['Tiny Racer*.zip', 'Game: Deluxe.zip', '.hidden.zip']) {
        await uploadArchive(page, name, archive);

        const row = page
            .locator('#share-file-group label', { hasText: name })
            .first();
        await row.waitFor({ timeout: STEP_TIMEOUT_MS });
        assert(
            await row.locator('input[type="radio"]').isDisabled(),
            `${name} is selectable`,
        );
        const reason = await row.innerText();
        assertIncludes(
            reason,
            'Bitte benenne die Datei um: Der Dateiname darf keinen Backtick',
            `the reason for ${name}`,
        );
        assertIncludes(
            reason,
            'zwei Punkte hintereinander',
            `the reason for ${name}`,
        );
        assert(
            !reason.includes('Kein Archiv'),
            `${name} is still called "Kein Archiv" although it is a .zip`,
        );
        assert(
            (await checkedArchiveId(page)) === null,
            `${name} was selected although it is unusable`,
        );
        assertIncludes(
            await page.locator('[data-test="share-missing"]').innerText(),
            'ein Archiv',
            'the missing-fields line after an unusable upload',
        );
    }

    await shot(page, '05-unusable-names');

    // A usable archive is selected on arrival now, so the taken title is the
    // only thing left to fill in.
    await uploadArchive(page, 'tiny-racer-player.zip', archive);
    const goodId = await archiveIdByName(page, 'tiny-racer-player.zip');
    assert(
        (await checkedArchiveId(page)) === goodId,
        'the usable archive was not selected automatically',
    );

    await page.locator('#share-title').fill(GAME_TITLE);
    await page.locator('#share-executable').fill(EXECUTABLE);
    await page.getByRole('button', { name: 'Spiel teilen' }).click();

    const titleError = page.locator('#share-title-error');
    await titleError.waitFor({ timeout: STEP_TIMEOUT_MS });
    assertIncludes(
        await titleError.innerText(),
        'Ein Spiel mit diesem Titel gibt es schon.',
        'the taken-title message',
    );
    await shot(page, '06-share-taken-title');

    // A line break in the title: the browser strips it from a single-line
    // input, so it can never be entered (and the server would refuse it).
    await page.locator('#share-title').fill('Tiny\nRacer');
    const typed = await page.locator('#share-title').inputValue();
    assert(!typed.includes('\n'), 'a line break survived in the title field');
    console.log(
        `  i "Tiny\\nRacer" entered into the title became ${JSON.stringify(typed)}`,
    );
});

await step(
    '3 review: panel badge, filter, launch section, publish',
    async () => {
        const { page } = orga;

        await page.goto(`${APP_URL}/admin/recipes`, {
            waitUntil: 'networkidle',
        });

        const queueItem = page
            .locator('li.fi-sidebar-item', { hasText: 'Spielrezepte' })
            .first();
        const badge = queueItem.locator('.fi-badge').first();
        assert(
            (await badge.count()) > 0 &&
                (await badge.innerText()).trim() === '1',
            'the Spielrezepte navigation badge does not show the one shared game waiting',
        );

        await page
            .getByRole('button', { name: /Filter/ })
            .first()
            .click();
        const sharedFilter = page
            .getByText('Geteilte Spiele', { exact: true })
            .first();
        await sharedFilter.waitFor({ timeout: STEP_TIMEOUT_MS });
        assert(
            await sharedFilter.isVisible(),
            'the "Geteilte Spiele" filter is not visible in the filter panel',
        );
        await page.keyboard.press('Escape');

        await page
            .locator('tr', { hasText: GAME_SLUG })
            .getByRole('link', { name: 'Bearbeiten' })
            .click();
        await page.waitForURL(/\/admin\/recipes\/\d+\/edit/);

        const body = await page.locator('body').innerText();
        assertIncludes(body, 'Start und Launcher', 'the review page');
        assertIncludes(
            body,
            'Start-Process -FilePath "{{target}}/bin/TinyRacer.exe"',
            'the review page launch command',
        );
        await shot(page, '07-admin-review');

        await page
            .getByRole('button', {
                name: 'Dateien freigeben und veröffentlichen',
            })
            .click();

        await waitForModal(page);
        const modal = page.locator('.fi-modal-window').first();
        const confirmation = await modal.innerText();
        assertIncludes(confirmation, ARCHIVE_NAME, 'the publish confirmation');
        assertIncludes(
            confirmation,
            'Start-Process -FilePath "{{target}}/bin/TinyRacer.exe"',
            'the publish confirmation',
        );
        const counts = confirmation.match(
            /Schrittbefehle: (\d+) · Startbefehle: (\d+)/,
        );
        assert(
            counts !== null,
            `the publish confirmation does not count its commands as "Schrittbefehle: N · Startbefehle: M"; saw ${JSON.stringify(confirmation)}`,
        );
        assert(
            Number(counts[1]) > 0 && Number(counts[2]) > 0,
            `the publish confirmation counts ${counts[1]} step and ${counts[2]} launch commands`,
        );
        await shot(page, '08-admin-publish-confirm');

        await modal
            .getByRole('button', { name: 'Freigeben und veröffentlichen' })
            .click();

        await page
            .getByText('Datei freigegeben und Rezept veröffentlicht')
            .first()
            .waitFor({ timeout: STEP_TIMEOUT_MS });

        await page.goto(`${APP_URL}/admin/recipes`, {
            waitUntil: 'networkidle',
        });
        const row = page.locator('tr', { hasText: GAME_SLUG }).first();
        const rowText = await row.innerText();
        assertIncludes(rowText, 'Veröffentlicht', 'the recipe list row');
        assertIncludes(rowText, 'Ja', 'the recipe list row (lineup)');
        assert(
            (await queueItem.locator('.fi-badge').count()) === 0,
            'the review badge is still shown after publishing',
        );
        await shot(page, '09-admin-published');
    },
);

await step('4 find: the game on /games and its page', async () => {
    const { page } = player;

    await page.goto(`${APP_URL}/games`, { waitUntil: 'networkidle' });
    const card = page.locator(`a[href="/games/${GAME_SLUG}"]`).first();
    await card.waitFor({ timeout: STEP_TIMEOUT_MS });
    assertIncludes(
        await card.innerText(),
        GAME_TITLE,
        'the games library card',
    );
    assertIncludes(
        await page.locator('main').innerText(),
        '● LIVE Smoke LAN 2026',
        'the games library event line',
    );

    // One download entry everywhere: the header action and the sidebar item
    // both say "App herunterladen" and both lead to /app.
    const downloadEntries = page.getByRole('link', {
        name: /App herunterladen/,
    });
    assert(
        (await downloadEntries.count()) >= 2,
        `the shell offers ${await downloadEntries.count()} "App herunterladen" entries, expected the header and the sidebar`,
    );

    for (const entry of await downloadEntries.all()) {
        assert(
            (await entry.getAttribute('href')) === '/app',
            `a shell download entry links ${JSON.stringify(await entry.getAttribute('href'))}, not /app`,
        );
    }

    await page.goto(`${APP_URL}/games/${GAME_SLUG}`, {
        waitUntil: 'networkidle',
    });
    const body = await page.locator('main').innerText();
    assertIncludes(body, GAME_TITLE, 'the game page');
    assertIncludes(body, 'Installieren', 'the game page actions');
    assertIncludes(body, 'AUF DER LAN', 'the game page');
    assertIncludes(body, ARCHIVE_NAME, 'the game page file list');
    assert(
        !body.includes('This draft needs verification'),
        'the published game page still calls itself a draft',
    );
    assertIncludes(
        body,
        'The orga reviewed and published it for this LAN.',
        'the published description',
    );
    await shot(page, '10-game-page');
});

await step(
    '5 join: both launcher actions on /servers/all and /servers',
    async () => {
        const { page } = player;

        for (const route of ['/servers/all', '/servers']) {
            // The plain `address:port` server: the link cannot name it, so the row
            // says "Im Launcher starten" and explains that the player picks the
            // server in the game.
            const plain = await serverScope(page, route, SERVER_TITLE);
            const start = plain.getByRole('link', {
                name: 'Im Launcher starten',
            });
            assert(
                (await start.count()) === 1,
                `${route}: no "Im Launcher starten" on the plain-address server`,
            );
            assert(
                (await plain
                    .getByRole('link', { name: 'Im Launcher beitreten' })
                    .count()) === 0,
                `${route}: the plain-address server still offers "Im Launcher beitreten"`,
            );
            assert(
                (await start.getAttribute('href')) ===
                    `lanomat://join/${GAME_SLUG}`,
                `${route}: the plain launch link is ${JSON.stringify(await start.getAttribute('href'))}`,
            );
            assertIncludes(
                await plain.innerText(),
                'Den Server wählst du dann im Spiel',
                `${route}: the plain launch hint`,
            );
            assert(
                !(await plain.innerText()).includes('auf diesem Server'),
                `${route}: the plain-address row still promises to start on that server`,
            );

            const seq = await plain
                .locator('a, button')
                .evaluateAll((elements) =>
                    elements
                        .map((element) => element.innerText.trim())
                        .filter(Boolean),
                );
            const order = [
                seq.indexOf('Im Launcher starten'),
                seq.findIndex((text) => text.includes('Spiel installieren')),
                seq.indexOf('Mitspieler suchen'),
            ];
            assert(
                order[0] >= 0 && order[1] > order[0] && order[2] > order[1],
                `${route}: the action order is ${JSON.stringify(order)} in ${JSON.stringify(seq)}`,
            );
            await shot(
                page,
                `11-servers-plain-${route.replaceAll('/', '-').replace(/^-/, '')}`,
            );

            // The server with its own `steam://connect/...` join URL: the launcher
            // link carries it, and the action keeps its name.
            const linked = await serverScope(page, route, SERVER_TITLE_LINKED);
            const join = linked.getByRole('link', {
                name: 'Im Launcher beitreten',
            });
            assert(
                (await join.count()) === 1,
                `${route}: no "Im Launcher beitreten" on the linked server`,
            );
            const href = await join.getAttribute('href');
            assert(
                href !== null &&
                    href.startsWith(`lanomat://join/${GAME_SLUG}?url=`),
                `${route}: the linked join link is ${JSON.stringify(href)}`,
            );
            assert(
                decodeURIComponent(href.slice(href.indexOf('?url=') + 5)) ===
                    LINKED_CONNECT,
                `${route}: the linked join link carries ${JSON.stringify(href)}, not ${LINKED_CONNECT}`,
            );
            assertIncludes(
                await linked.innerText(),
                'startet es auf diesem Server',
                `${route}: the linked join hint`,
            );
            await shot(
                page,
                `12-servers-linked-${route.replaceAll('/', '-').replace(/^-/, '')}`,
            );
        }
    },
);

await step(
    '6 launcher link: attempt, notice, retry, dismiss, modified click',
    async () => {
        const { page } = player;

        await page.goto(`${APP_URL}/servers/all`, { waitUntil: 'networkidle' });
        const { block } = launcherBlock(page, 'Im Launcher starten');
        await recordAttemptEvents(page);

        const before = await page.evaluate(() => ({
            url: location.href,
            hasFocus: document.hasFocus(),
            visibility: document.visibilityState,
        }));
        const pagesBefore = page.context().pages().length;
        const dialogs = [];
        page.on('dialog', (dialog) => {
            dialogs.push(`${dialog.type()}: ${dialog.message()}`);
            dialog.dismiss().catch(() => undefined);
        });

        await launcherBlock(page, 'Im Launcher starten').link.click();

        const trying = tryingLine(block);
        await trying.waitFor({ timeout: 2_000 });

        const status = block.locator('[role="status"]');
        assertIncludes(
            await status.innerText(),
            'Öffne den Launcher',
            'the persistent live region while the attempt runs',
        );

        const notice = block.locator('[data-test="launcher-unanswered"]');
        await notice.waitFor({ timeout: NOTICE_TIMEOUT_MS });

        const after = await page.evaluate(() => ({
            url: location.href,
            hasFocus: document.hasFocus(),
            visibility: document.visibilityState,
            events: window.__smokeLauncherEvents ?? [],
        }));

        assertIncludes(
            await status.innerText(),
            'Kein Launcher-Fenster aufgegangen?',
            'the persistent live region after the timeout',
        );
        const noticeText = await notice.innerText();
        assertIncludes(
            noticeText,
            'Kein Launcher-Fenster aufgegangen?',
            'the notice',
        );
        assertIncludes(noticeText, 'App herunterladen', 'the notice');
        assertIncludes(noticeText, 'Nochmal versuchen', 'the notice');
        assert(
            (await notice
                .getByRole('button', { name: 'Hinweis schließen' })
                .count()) === 1,
            'the notice has no dismiss button',
        );
        assert(
            (await notice
                .getByRole('link', { name: 'App herunterladen' })
                .getAttribute('href')) === '/app',
            'the notice download link does not point at /app',
        );

        console.log(
            `  i engine: url ${before.url === after.url ? 'unchanged' : `changed to ${after.url}`}, ` +
                `pages ${pagesBefore} → ${page.context().pages().length}, ` +
                `dialogs ${JSON.stringify(dialogs)}, focus ${before.hasFocus} → ${after.hasFocus}, ` +
                `visibility ${before.visibility} → ${after.visibility}, ` +
                `page events ${JSON.stringify(after.events)}, ` +
                `scheme requests ${JSON.stringify(report.requests.filter((entry) => entry.startsWith(`[${currentStep}]`)))}`,
        );

        // The notice sits under its own button and does not push the row's
        // other actions into the middle of the row.
        const plainCard = page
            .locator('article')
            .filter({ hasText: SERVER_TITLE })
            .first();
        await assertNoticeLayout(plainCard, 'Im Launcher starten', notice);
        await shot(page, '13-launcher-notice');

        // "Nochmal versuchen" starts the attempt again (and must end in the same
        // place when nothing takes over).
        await notice.getByRole('button', { name: 'Nochmal versuchen' }).click();
        await trying.waitFor({ timeout: 2_000 });
        await notice.waitFor({ timeout: NOTICE_TIMEOUT_MS });
        console.log(
            '  i "Nochmal versuchen" → "Öffne den Launcher …" again → notice again',
        );

        // Dismissing hides the notice and leaves the action usable.
        await notice.getByRole('button', { name: 'Hinweis schließen' }).click();
        await notice.waitFor({ state: 'detached', timeout: 2_000 });
        assert(
            (await status.innerText()).trim() === '',
            'the live region still has text after dismissing the notice',
        );

        // A modified click belongs to the browser (new tab), never to the
        // launcher attempt: no "Öffne den Launcher …" on this page. A bounded
        // wait is the only way to observe that nothing started (the trying line
        // appears synchronously inside the click handler).
        const pagesBeforeModified = page.context().pages().length;
        await launcherBlock(page, 'Im Launcher starten').link.click({
            modifiers: ['Control'],
        });
        await page.waitForTimeout(500);
        assert(
            (await tryingLine(block).count()) === 0,
            'Ctrl+click started a launcher attempt on the page',
        );
        assert(
            page.url().endsWith('/servers/all'),
            `Ctrl+click navigated the page to ${page.url()}`,
        );

        for (const extra of page.context().pages()) {
            if (extra !== page) {
                await extra.close();
            }
        }

        await launcherBlock(page, 'Im Launcher starten').link.click({
            button: 'middle',
        });
        await page.waitForTimeout(500);
        assert(
            (await tryingLine(block).count()) === 0,
            'middle-click started a launcher attempt on the page',
        );
        await page.bringToFront();

        for (const extra of page.context().pages()) {
            if (extra !== page) {
                await extra.close();
            }
        }

        console.log(
            `  i modified clicks: no attempt started, page stayed on ${page.url()} ` +
                `(${pagesBeforeModified} → ${page.context().pages().length} browser tab(s))`,
        );

        // The same notice in the other server surface: the expanded row on
        // /servers, where the action sits inside the disclosure panel.
        const panel = await serverScope(page, '/servers', SERVER_TITLE);
        await recordAttemptEvents(page);
        await panel.getByRole('link', { name: 'Im Launcher starten' }).click();
        const rowNotice = panel.locator('[data-test="launcher-unanswered"]');
        await rowNotice.waitFor({ timeout: NOTICE_TIMEOUT_MS });
        await assertNoticeLayout(panel, 'Im Launcher starten', rowNotice);
        await shot(page, '14-servers-row-notice');
        await rowNotice
            .getByRole('button', { name: 'Hinweis schließen' })
            .click();
        await rowNotice.waitFor({ state: 'detached', timeout: 2_000 });
    },
);

await step(
    '6b the one download page: /app, and the old URLs redirect to it',
    async () => {
        const { page } = player;

        // `/launcher` and `/talk/client` only redirect now; the server rows that
        // used to live on the launcher page are gone.
        for (const legacy of ['/launcher', '/talk/client']) {
            await page.goto(`${APP_URL}${legacy}`, {
                waitUntil: 'networkidle',
            });
            assert(
                page.url() === `${APP_URL}/app`,
                `${legacy} did not redirect to /app but to ${page.url()}`,
            );
        }

        await page.goto(`${APP_URL}/app`, { waitUntil: 'networkidle' });
        assertIncludes(
            await page.locator('main').innerText(),
            'LANoMAT-App',
            'the download page',
        );

        const launcherCard = page.locator(
            '[data-test="app-download-launcher"]',
        );
        await launcherCard.waitFor({ timeout: STEP_TIMEOUT_MS });
        assertIncludes(
            await launcherCard.innerText(),
            'Spiele (Launcher)',
            'the launcher card',
        );

        // The seeded placeholder build is the one thing the page has to offer
        // for the launcher; its file comes from the module's own download route.
        const builds = launcherCard.locator(
            '[data-test="app-builds-launcher"]',
        );
        await builds.waitFor({ timeout: STEP_TIMEOUT_MS });
        assertIncludes(
            await builds.innerText(),
            LAUNCHER_BUILD_NAME,
            'the launcher build list',
        );
        const downloadLink = builds
            .getByRole('link', { name: /Herunterladen/ })
            .first();
        const downloadHref = await downloadLink.getAttribute('href');
        assert(
            downloadHref !== null &&
                downloadHref.startsWith('/launcher/download/'),
            `the launcher build links ${JSON.stringify(downloadHref)}`,
        );

        // The card reads its release from the manifest the seeder wrote.
        const release = launcherCard.locator(
            '[data-test="app-release-launcher"]',
        );
        await release.waitFor({ timeout: STEP_TIMEOUT_MS });
        assertIncludes(
            await release.innerText(),
            '0.0.0-smoke',
            'the launcher release line',
        );
        await shot(page, '15-download-page');
    },
);

await step('6c takeover: a window that takes the focus', async () => {
    const { page } = player;

    // (a) The event the composable documents: the page loses focus.
    await page.goto(`${APP_URL}/servers/all`, { waitUntil: 'networkidle' });
    let { block } = launcherBlock(page, 'Im Launcher starten');
    await recordAttemptEvents(page);
    await launcherBlock(page, 'Im Launcher starten').link.click();
    await tryingLine(block).waitFor({ timeout: 2_000 });

    await page.evaluate(() => window.dispatchEvent(new Event('blur')));

    const opened = block.locator('[data-test="launcher-opened"]');
    await opened.waitFor({ timeout: 2_000 });
    assertIncludes(
        await opened.innerText(),
        'Launcher geöffnet.',
        'the takeover confirmation',
    );
    assertIncludes(
        await block.locator('[role="status"]').innerText(),
        'Launcher geöffnet.',
        'the live region after a takeover',
    );
    await shot(page, '16-launcher-opened');

    // It goes quiet again on its own.
    await opened.waitFor({
        state: 'detached',
        timeout: OPENED_RESET_TIMEOUT_MS,
    });
    console.log('  i synthetic window blur → "Launcher geöffnet." → cleared');

    // (b) A hidden page (a launcher window in front).
    await page.goto(`${APP_URL}/servers/all`, { waitUntil: 'networkidle' });
    ({ block } = launcherBlock(page, 'Im Launcher starten'));
    await recordAttemptEvents(page);
    await launcherBlock(page, 'Im Launcher starten').link.click();
    await tryingLine(block).waitFor({ timeout: 2_000 });

    await page.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => 'hidden',
        });
        document.dispatchEvent(new Event('visibilitychange'));
    });

    const openedByVisibility = block.locator('[data-test="launcher-opened"]');
    await openedByVisibility.waitFor({ timeout: 2_000 });
    console.log('  i simulated visibilitychange → "Launcher geöffnet."');

    await page.evaluate(() => {
        delete document.visibilityState;
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await openedByVisibility.waitFor({
        state: 'detached',
        timeout: OPENED_RESET_TIMEOUT_MS,
    });

    // (c) The real thing: another tab in the same context, brought to front.
    await page.goto(`${APP_URL}/servers/all`, { waitUntil: 'networkidle' });
    ({ block } = launcherBlock(page, 'Im Launcher starten'));
    await recordAttemptEvents(page);
    await launcherBlock(page, 'Im Launcher starten').link.click();
    await tryingLine(block).waitFor({ timeout: 2_000 });

    const other = await page.context().newPage();
    await other.goto('about:blank');
    await other.bringToFront();

    // Wait for the attempt to settle either way: a takeover ("opened") or the
    // timeout notice. Headless Chromium keeps a background tab focused and
    // "visible", so this is the interesting part of the observation.
    const realTabOpened = block.locator('[data-test="launcher-opened"]');
    const realTabNotice = block.locator('[data-test="launcher-unanswered"]');
    await Promise.race([
        realTabOpened.waitFor({ timeout: NOTICE_TIMEOUT_MS }),
        realTabNotice.waitFor({ timeout: NOTICE_TIMEOUT_MS }),
    ]).catch(() => undefined);

    const openedInRealTab = (await realTabOpened.count()) > 0;
    const noticedInRealTab = (await realTabNotice.count()) > 0;
    console.log(
        `  i real second tab (headless): opened ${openedInRealTab}, notice ${noticedInRealTab}, ` +
            `background visibility ${await page.evaluate(() => document.visibilityState)}, ` +
            `hasFocus ${await page.evaluate(() => document.hasFocus())}`,
    );
    await other.close();
    await page.bringToFront();

    if (!openedInRealTab) {
        finding(
            'a real second tab (same context, brought to front) is not a takeover in headless Chromium: the background page keeps visibilityState "visible" and document.hasFocus() true, so the attempt ends in the notice after the 2.5 s timeout — a headed browser may differ; the blur/visibilitychange listeners themselves work (synthetic events above)',
        );
    }

    const leftover = block.locator('[data-test="launcher-unanswered"]');

    if ((await leftover.count()) > 0) {
        await leftover
            .getByRole('button', { name: 'Hinweis schließen' })
            .click();
    }
});

await step(
    '6d the game page: Installieren and Im Launcher öffnen',
    async () => {
        const { page } = player;

        await page.goto(`${APP_URL}/games/${GAME_SLUG}`, {
            waitUntil: 'networkidle',
        });

        const install = page.locator('[data-test="install"]').first();
        assert(
            (await install.count()) === 1,
            'the game page has no Installieren',
        );
        const installHref = await install.getAttribute('href');

        if (installHref !== null && installHref.startsWith('lanomat://')) {
            // No launcher build published: "Installieren" is the deep link itself.
            await install.click();
            const installBlock = page
                .locator('span')
                .filter({ has: page.locator('[data-test="install"]') })
                .last();
            await installBlock
                .locator('[data-test="launcher-unanswered"]')
                .waitFor({ timeout: NOTICE_TIMEOUT_MS });
            await installBlock
                .locator('[data-test="launcher-unanswered"]')
                .getByRole('button', { name: 'Hinweis schließen' })
                .click();
            console.log(
                `  i "Installieren" is the launcher link (${installHref})`,
            );
        } else {
            // A published launcher build: "Installieren" leads to the one
            // download page, not to a deep link.
            assert(
                installHref === '/app',
                `the game page's Installieren links ${JSON.stringify(installHref)}, not /app`,
            );
            await install.click();
            await page.waitForURL(`${APP_URL}/app`);
            assertIncludes(
                await page.locator('main').innerText(),
                'LANoMAT-App',
                'the download page',
            );
            await page.goBack();
            await page.waitForURL(new RegExp(`/games/${GAME_SLUG}`));
            // The history entry renders asynchronously; wait for the page itself.
            await page
                .locator('[data-test="install"]')
                .first()
                .waitFor({ timeout: STEP_TIMEOUT_MS });
            console.log(
                `  i "Installieren" leads to the download page (${installHref}), not to lanomat://`,
            );
        }

        const openInLauncher = page
            .locator('[data-test="install-launcher"]')
            .first();
        // Again a render race: the action only exists with a published build.
        await openInLauncher.waitFor({ timeout: 2_000 }).catch(() => undefined);

        if ((await openInLauncher.count()) === 0) {
            finding(
                '"Im Launcher öffnen" is missing on the game page although a launcher build is published (no data-test="install-launcher")',
            );

            return;
        }

        const { block } = launcherBlock(page, 'Im Launcher öffnen');
        await recordAttemptEvents(page);
        await openInLauncher.click();
        await tryingLine(block).waitFor({ timeout: 2_000 });
        await block
            .locator('[data-test="launcher-unanswered"]')
            .waitFor({ timeout: NOTICE_TIMEOUT_MS });
        assertIncludes(
            await block.locator('[role="status"]').innerText(),
            'Kein Launcher-Fenster aufgegangen?',
            'the game page live region',
        );
        await shot(page, '17-game-page-notice');
        await block
            .locator('[data-test="launcher-unanswered"]')
            .getByRole('button', { name: 'Hinweis schließen' })
            .click();
    },
);

await step(
    '7 missing files: the row says so, the launcher action stays',
    async () => {
        const { page: sharerPage } = sharer;

        // Delete the archive the recipe pins (the earlier steps left more uploads
        // on the page, so the card is picked by name).
        await sharerPage.goto(`${APP_URL}/events/${EVENT_SLUG}/files`, {
            waitUntil: 'networkidle',
        });
        const card = sharerPage
            .locator('[data-slot="card"]')
            .filter({ hasText: ARCHIVE_NAME })
            .filter({
                has: sharerPage.getByRole('button', { name: 'Löschen' }),
            })
            .first();
        await card.waitFor({ timeout: STEP_TIMEOUT_MS });
        await card.getByRole('button', { name: 'Löschen' }).click();
        await card.waitFor({ state: 'detached', timeout: STEP_TIMEOUT_MS });

        const { page } = player;

        for (const route of ['/servers/all', '/servers']) {
            for (const [title, label] of [
                [SERVER_TITLE, 'Im Launcher starten'],
                [SERVER_TITLE_LINKED, 'Im Launcher beitreten'],
            ]) {
                const row = await serverScope(page, route, title);

                const hint = row.locator('[data-test="install-not-on-lan"]');
                await hint.waitFor({ timeout: STEP_TIMEOUT_MS });
                assertIncludes(
                    await hint.innerText(),
                    'Für dieses Spiel liegen gerade nicht alle Dateien auf dem LAN-Server',
                    `${route} (${title}): the missing-files line`,
                );
                assertIncludes(
                    await hint.innerText(),
                    'eine Neuinstallation kann deshalb scheitern',
                    `${route} (${title}): the missing-files line`,
                );
                assert(
                    (await row.getByRole('link', { name: label }).count()) ===
                        1,
                    `${route} (${title}): "${label}" disappeared with the files`,
                );
            }

            await shot(
                page,
                `18-missing-files-${route.replaceAll('/', '-').replace(/^-/, '')}`,
            );
        }

        await page.goto(`${APP_URL}/games/${GAME_SLUG}`, {
            waitUntil: 'networkidle',
        });
        const gamePage = await page.locator('main').innerText();
        assertIncludes(
            gamePage,
            GAME_TITLE,
            'the game page after deleting the archive',
        );
        // "On the LAN" means playable here: the running server still counts.
        assertIncludes(
            gamePage,
            'AUF DER LAN',
            'the game page after deleting the archive',
        );
    },
);

await step(
    '8 presence: "Hier spielen" for participants, nobody else',
    async () => {
        const { page } = player;

        for (const route of ['/servers/all', '/servers']) {
            const row = await serverScope(page, route, SERVER_TITLE);

            const occupants = row
                .locator('p')
                .filter({ hasText: 'Hier spielen' })
                .first();
            await occupants.waitFor({ timeout: STEP_TIMEOUT_MS });

            const named = await occupants
                .locator('a[aria-label]')
                .evaluateAll((elements) =>
                    elements.map((element) =>
                        element.getAttribute('aria-label'),
                    ),
                );
            assert(
                named.includes(SHARER_NAME),
                `${route}: the sharer is not on the "Hier spielen" line (${JSON.stringify(named)})`,
            );
            assert(
                named.includes(PLAYER_NAME),
                `${route}: the viewer does not see themselves on the "Hier spielen" line (${JSON.stringify(named)})`,
            );
            assert(
                !named.includes(HIDDEN_NAME),
                `${route}: a participant with "Standort teilen" off is listed (${JSON.stringify(named)})`,
            );
            await shot(
                page,
                `19-presence-${route.replaceAll('/', '-').replace(/^-/, '')}`,
            );
        }

        // An account without a registration for the event gets no line at all.
        for (const route of ['/servers/all', '/servers']) {
            await outsider.page.goto(`${APP_URL}${route}`, {
                waitUntil: 'networkidle',
            });
            assert(
                !(await outsider.page.locator('main').innerText()).includes(
                    'Hier spielen',
                ),
                `${route}: an account that is not registered for the event sees "Hier spielen"`,
            );
        }

        console.log(
            `  i "Hier spielen" names ${SHARER_NAME} and ${PLAYER_NAME}, hides ${HIDDEN_NAME}, and is absent for the unregistered account`,
        );
    },
);

// --- the report ------------------------------------------------------------

await browser.close();

console.log('\nLoose ends');

if (report.console.length === 0) {
    console.log('  ✓ no console errors');
} else {
    for (const line of report.console) {
        console.log(`  ✗ console: ${line}`);
    }
}

if (report.http.length === 0) {
    console.log('  ✓ no 4xx/5xx responses');
} else {
    for (const line of report.http) {
        console.log(`  ✗ http: ${line}`);
    }
}

if (report.pageErrors.length === 0) {
    console.log('  ✓ no unhandled page errors');
} else {
    for (const line of report.pageErrors) {
        console.log(`  ✗ pageerror: ${line}`);
    }
}

if (report.requests.length > 0) {
    console.log(`  i lanomat:// requests seen: ${report.requests.length}`);
}

const failed = report.steps.filter((entry) => !entry.ok);

console.log('\nSummary');

for (const entry of report.steps) {
    console.log(`  ${entry.ok ? '✓' : '✗'} ${entry.name}`);
}

for (const line of report.findings) {
    console.log(`  ! ${line}`);
}

console.log(
    `\n${report.steps.length - failed.length}/${report.steps.length} steps passed, ` +
        `${report.findings.length} finding(s), screenshots in ${SHOT_DIR}`,
);

const strict = process.env.SMOKE_STRICT === '1';

if (failed.length > 0) {
    console.error('\nGame-sharing smoke test FAILED.');

    for (const entry of failed) {
        console.error(`  ✗ ${entry.name}: ${entry.error}`);
    }

    process.exit(1);
}

if (strict && report.findings.length > 0) {
    console.error(
        '\nGame-sharing smoke test FAILED (SMOKE_STRICT=1, findings).',
    );
    process.exit(1);
}

if (report.findings.length > 0) {
    console.log(
        '\nGame-sharing journey passed with findings (application defects, see above).',
    );
} else {
    console.log('\nGame-sharing smoke test passed.');
}
