#!/usr/bin/env node
/**
 * Live recipe editor for the UT2004 recipe (Filament ID 20).
 *
 * The admin panel is the only editor that keeps `metadata.launch_targets`,
 * tags, live_url and the review stamp intact: Filament saves the registered
 * form fields and leaves `metadata` alone. `POST /api/v1/recipes` (the
 * ContributeRecipe action) replaces `metadata` wholesale when the payload has
 * none, so it is only used here for a metadata-only restore fallback.
 *
 * All admin traffic goes through the real Filament page with a browser session
 * (Laravel session cookie + XSRF-TOKEN) and Livewire's `get`/`set`/`call`.
 * The plan command is the only Bearer-token call; it reads the token from the
 * launcher config and never prints it.
 *
 * No external npm packages besides Playwright. Node ESM.
 */

import fs from 'node:fs';
import fsp from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { isDeepStrictEqual } from 'node:util';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '../../..');

const LIVE_BASE = 'https://lan.raute1.org';
const DEFAULT_RECIPE_ID = '20';
const DEFAULT_SLUG = 'unreal-tournament-2004';
const DEFAULT_COOKIE_FILE = '/tmp/opencode/cookies.txt';
const DEFAULT_CONFIG_FILE = path.join(os.homedir(), '.config/lanomat-launcher/config.json');

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const SHA256_RE = /^[0-9a-f]{64}$/i;
const PLATFORMS = ['windows', 'linux', 'macos'];
const RESTORE_KEYS = [
    'title',
    'aliases',
    'version',
    'platforms',
    'summary',
    'description',
    'homepage',
    'license',
    'skill_markdown',
    'requirements',
    'steps',
    'metadata',
    'status',
];

const VALUE_FLAGS = new Set([
    'base-url',
    'id',
    'recipe-id',
    'slug',
    'cookie-file',
    'config',
    'os',
    'iso-url',
    'iso-match',
    'platforms',
    'patch-linux-url',
    'patch-linux-sha',
    'patch-macos-url',
    'patch-macos-sha',
    'out',
    'file',
    'backup-dir',
]);
const BOOL_FLAGS = new Set(['help', 'apply', 'yes', 'keep-metadata', 'record', 'json']);

function usageError(message) {
    const error = new Error(message);
    error.exitCode = 2;
    return error;
}

function fail(message) {
    const error = new Error(message);
    error.exitCode = 1;
    return error;
}

function nowStamp() {
    return new Date().toISOString().replace(/[:.]/g, '-').replace('T', '_').slice(0, 19);
}

function parseArgs(argv) {
    const command = argv[0] && !argv[0].startsWith('-') ? argv[0] : '';
    const rest = command ? argv.slice(1) : argv;
    const options = { command, _: [] };

    for (let i = 0; i < rest.length; i += 1) {
        const arg = rest[i];

        if (arg === '--') {
            options._.push(...rest.slice(i + 1));
            break;
        }

        if (!arg.startsWith('--')) {
            options._.push(arg);
            continue;
        }

        const equals = arg.indexOf('=');
        const key = equals === -1 ? arg.slice(2) : arg.slice(2, equals);
        let value = equals === -1 ? undefined : arg.slice(equals + 1);

        if (equals === -1 && VALUE_FLAGS.has(key)) {
            if (i + 1 >= rest.length) {
                throw usageError(`--${key} needs a value`);
            }
            value = rest[i + 1];
            i += 1;
        } else if (equals === -1) {
            if (!BOOL_FLAGS.has(key)) {
                throw usageError(`unknown option --${key}`);
            }
            value = true;
        }

        if (key === 'recipe-id') {
            options.id = value;
        } else if (key === 'help') {
            options.help = true;
        } else if (key === 'json') {
            options.json = true;
        } else {
            options[key] = value;
        }
    }

    return options;
}

function helpText(command) {
    const header = `UT2004 live recipe (Filament ID ${DEFAULT_RECIPE_ID}, ${DEFAULT_SLUG})

Usage:
  node scripts/ops/ut2004/live-recipe.mjs show [options]
  node scripts/ops/ut2004/live-recipe.mjs plan --os linux|windows|macos [options]
  node scripts/ops/ut2004/live-recipe.mjs set <changes...> [--apply --yes] [options]
  node scripts/ops/ut2004/live-recipe.mjs backup [--out FILE|-] [options]
  node scripts/ops/ut2004/live-recipe.mjs dump [--out FILE|-] [options]
  node scripts/ops/ut2004/live-recipe.mjs restore --file FILE [--apply --yes] [options]

Commands:
  show       Read the live Filament state as JSON (requirements/match/url/os,
             steps, platforms, metadata, status).
  plan       Ask the agent API for the install plan for one OS. Sends
             dry_run:true unless --record is given, so it writes no audit row.
  set        Change form values. Without --apply it is a dry-run diff.
  backup     Write the complete live form state to a JSON file.
  dump       Alias for backup.
  restore    Replay a JSON backup. Without --apply it only shows the diff.

Set changes (at least one):
  --iso-url URL               Set the ISO/base requirement URL ("" clears it).
  --iso-match a,b,c           Set the ISO/base requirement match globs.
  --platforms windows,linux,macos
  --patch-linux-url URL       Set the Linux patch requirement URL.
  --patch-linux-sha SHA       Set the Linux patch SHA-256 (64 hex, "" clears).
  --patch-macos-url URL       Set the macOS patch requirement URL.
  --patch-macos-sha SHA       Set the macOS patch SHA-256 (64 hex, "" clears).

Safety:
  --apply                     Write the change (a no-op apply still calls
                              Livewire save). Requires --yes.
  --yes                       Explicit confirmation for --apply.
  --keep-metadata             restore: keep live metadata instead of the
                              backup's metadata.

Connection / files:
  --base-url URL              Default: launcher config url, else ${LIVE_BASE}
  --id N                      Filament recipe id (default ${DEFAULT_RECIPE_ID})
  --slug SLUG                 Recipe slug for plan/API (default ${DEFAULT_SLUG})
  --cookie-file PATH          Cookie header / Netscape / JSON cookie file
                              (default ${DEFAULT_COOKIE_FILE}, env LANOMAT_ADMIN_COOKIES_FILE)
  --config PATH               Launcher config (default ${DEFAULT_CONFIG_FILE})
  --out FILE|-                backup/dump destination (default /tmp/opencode/ut2004-recipe-<id>-<ts>.json)
  --backup-dir DIR            Auto-backup dir for --apply (default /tmp/opencode)
  --file FILE|-               restore input
  --record                    plan: let the server record the planned install
  --help                      This text.

Examples:
  node scripts/ops/ut2004/live-recipe.mjs show
  node scripts/ops/ut2004/live-recipe.mjs plan --os linux
  node scripts/ops/ut2004/live-recipe.mjs set --iso-url https://host/ut2004.iso
  node scripts/ops/ut2004/live-recipe.mjs set --iso-url https://host/ut2004.iso --apply --yes
  node scripts/ops/ut2004/live-recipe.mjs backup --out /tmp/opencode/ut2004-before.json
  node scripts/ops/ut2004/live-recipe.mjs restore --file /tmp/opencode/ut2004-before.json --apply --yes

Exit codes: 0 ok, 1 runtime/verification failure, 2 usage/confirmation.
Secrets (cookies, bearer token) are never printed.
`;

    if (!command) {
        return header;
    }

    return header;
}

async function readJson(file) {
    return JSON.parse(await fsp.readFile(file, 'utf8'));
}

async function readLauncherConfig(options) {
    const file = options.config || process.env.LANOMAT_CONFIG || DEFAULT_CONFIG_FILE;

    try {
        const config = await readJson(file);
        return { file, ...config };
    } catch (error) {
        return { file, error: error.message };
    }
}

function baseUrl(options, config) {
    return String(options['base-url'] || process.env.LANOMAT_URL || config?.url || LIVE_BASE)
        .trim()
        .replace(/\/+$/, '');
}

function parseCookieHeader(raw, host, secure) {
    return raw
        .split(';')
        .map((pair) => pair.trim())
        .filter(Boolean)
        .map((pair) => {
            const index = pair.indexOf('=');
            if (index <= 0) {
                return null;
            }
            let value = pair.slice(index + 1).trim();
            if (value.length >= 2 && value.startsWith('"') && value.endsWith('"')) {
                value = value.slice(1, -1);
            }
            return {
                name: pair.slice(0, index).trim(),
                value,
                domain: host,
                path: '/',
                secure,
                httpOnly: false,
                sameSite: 'Lax',
            };
        })
        .filter(Boolean);
}

function parseCookieFile(raw, host, secure) {
    const trimmed = raw.trim();

    if (trimmed.startsWith('{')) {
        try {
            const parsed = JSON.parse(trimmed);
            if (Array.isArray(parsed.cookies)) {
                return parsed.cookies.map((cookie) => ({
                    domain: host,
                    path: '/',
                    secure,
                    httpOnly: false,
                    sameSite: 'Lax',
                    ...cookie,
                }));
            }
        } catch {
            // Not our JSON format; fall through to the header parser.
        }
    }

    if (trimmed.includes('\t') || trimmed.startsWith('# Netscape')) {
        return trimmed
            .split(/\r?\n/)
            .filter((line) => line && !line.startsWith('#'))
            .map((line) => line.split('\t'))
            .filter((fields) => fields.length >= 7)
            .map((fields) => ({
                domain: fields[0],
                path: fields[2] || '/',
                secure: fields[3] === 'TRUE',
                name: fields[5],
                value: fields[6],
                httpOnly: false,
                sameSite: 'Lax',
            }));
    }

    return parseCookieHeader(trimmed, host, secure);
}

async function loadCookies(options, base) {
    const explicitFile = options['cookie-file'];
    const envHeader = process.env.LANOMAT_ADMIN_COOKIES;
    const file = explicitFile || process.env.LANOMAT_ADMIN_COOKIES_FILE || DEFAULT_COOKIE_FILE;
    const url = new URL(base);
    const secure = url.protocol === 'https:';

    if (!explicitFile && envHeader) {
        return { source: 'environment', cookies: parseCookieHeader(envHeader, url.hostname, secure) };
    }

    let raw;
    try {
        raw = await fsp.readFile(file, 'utf8');
    } catch (error) {
        throw fail(`cannot read cookie file ${file}: ${error.code || error.message}`);
    }

    const cookies = parseCookieFile(raw, url.hostname, secure);
    if (cookies.length === 0) {
        throw fail(`cookie file ${file} contains no cookies`);
    }

    return { source: file, cookies };
}

async function loadPlaywright() {
    try {
        return await import('playwright');
    } catch {
        const entry = path.join(REPO_ROOT, 'node_modules/playwright/index.mjs');
        if (!fs.existsSync(entry)) {
            throw fail(`Playwright not found (looked for ${entry})`);
        }
        return import(pathToFileURL(entry).href);
    }
}

function findChromium() {
    const explicit = process.env.LANOMAT_CHROMIUM || process.env.CHROMIUM_PATH;
    if (explicit && fs.existsSync(explicit)) {
        return explicit;
    }

    const root = path.join(os.homedir(), '.cache/ms-playwright');
    const candidates = [];

    try {
        for (const entry of fs.readdirSync(root)) {
            if (entry.startsWith('chromium_headless_shell-')) {
                candidates.push(path.join(root, entry, 'chrome-headless-shell-linux64', 'chrome-headless-shell'));
            }
            if (entry.startsWith('chromium-')) {
                candidates.push(path.join(root, entry, 'chrome-linux64', 'chrome'));
                candidates.push(path.join(root, entry, 'chrome-linux', 'chrome'));
            }
        }
    } catch {
        // Playwright's bundled browser may still resolve by itself.
    }

    return candidates.find((candidate) => fs.existsSync(candidate));
}

async function launchBrowser() {
    const { chromium } = await loadPlaywright();
    const executablePath = findChromium();
    const baseOptions = {
        headless: true,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    };

    if (executablePath) {
        try {
            return await chromium.launch({ ...baseOptions, executablePath });
        } catch (error) {
            try {
                return await chromium.launch(baseOptions);
            } catch {
                throw fail(`could not launch Chromium (${executablePath}): ${error.message}`);
            }
        }
    }

    try {
        return await chromium.launch(baseOptions);
    } catch (error) {
        throw fail(`could not launch Chromium: ${error.message}`);
    }
}

function editUrl(base, id) {
    return `${base}/admin/recipes/${encodeURIComponent(id)}/edit`;
}

async function waitForEditor(page) {
    await page.waitForFunction(() => Boolean(window.Livewire), null, { timeout: 30000 });
    await page.waitForFunction(
        () =>
            [...document.querySelectorAll('[wire\\:id]')].some((node) =>
                (node.getAttribute('wire:snapshot') || '').includes('EditRecipe'),
            ),
        null,
        { timeout: 30000 },
    );
    await page.waitForFunction(
        () => {
            const element = [...document.querySelectorAll('[wire\\:id]')].find((node) =>
                (node.getAttribute('wire:snapshot') || '').includes('EditRecipe'),
            );
            if (!element) {
                return false;
            }
            const component = window.Livewire.find(element.getAttribute('wire:id'));
            return Boolean(component && component.get('data') && component.get('data.slug'));
        },
        null,
        { timeout: 30000 },
    );
}

async function openEditor(options) {
    const config = await readLauncherConfig(options);
    const base = baseUrl(options, config);
    const id = String(options.id || DEFAULT_RECIPE_ID);
    const { source, cookies } = await loadCookies(options, base);
    const browser = await launchBrowser();

    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        await context.addCookies(cookies);
        const page = await context.newPage();
        page.setDefaultTimeout(30000);

        const url = editUrl(base, id);
        const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });

        if (!response) {
            throw fail(`GET ${url} returned no response`);
        }
        if (response.status() >= 400) {
            throw fail(`GET ${url} answered HTTP ${response.status()}`);
        }
        if (/\/login|\/auth\//.test(page.url())) {
            throw fail('admin session expired (redirected to login); refresh the cookie file');
        }

        await waitForEditor(page);

        return { browser, page, base, id, cookieSource: source };
    } catch (error) {
        await browser.close().catch(() => {});
        throw error;
    }
}

async function readState(page) {
    return page.evaluate(() => {
        const element = [...document.querySelectorAll('[wire\\:id]')].find((node) =>
            (node.getAttribute('wire:snapshot') || '').includes('EditRecipe'),
        );
        if (!element) {
            throw new Error('EditRecipe component not found');
        }
        const component = window.Livewire.find(element.getAttribute('wire:id'));
        if (!component) {
            throw new Error('Livewire EditRecipe component is not initialised');
        }
        const data = component.get('data');
        if (!data || typeof data !== 'object') {
            throw new Error('Livewire data is not loaded');
        }
        return data;
    });
}

async function setField(page, name, value) {
    await page.evaluate(
        async ({ name: fieldName, value: fieldValue }) => {
            const element = [...document.querySelectorAll('[wire\\:id]')].find((node) =>
                (node.getAttribute('wire:snapshot') || '').includes('EditRecipe'),
            );
            if (!element) {
                throw new Error('EditRecipe component not found');
            }
            const component = window.Livewire.find(element.getAttribute('wire:id'));
            await component.set(fieldName, fieldValue);
        },
        { name, value },
    );
}

async function saveEditor(page) {
    const result = await page.evaluate(async () => {
        const element = [...document.querySelectorAll('[wire\\:id]')].find((node) =>
            (node.getAttribute('wire:snapshot') || '').includes('EditRecipe'),
        );
        if (!element) {
            throw new Error('EditRecipe component not found');
        }
        const component = window.Livewire.find(element.getAttribute('wire:id'));
        try {
            await component.call('save');
            return { ok: true };
        } catch (error) {
            return { ok: false, error: String(error?.message || error) };
        }
    });

    await page.waitForTimeout(400);

    if (!result.ok) {
        const visible = await validationErrors(page);
        throw fail(`Livewire save failed: ${result.error}${visible.length ? ` (${visible.join(' | ')})` : ''}`);
    }
}

async function validationErrors(page) {
    return page.evaluate(() =>
        [...document.querySelectorAll('.fi-fo-field-wrp-error-message, [data-validation-error], .fi-fo-field-wrp-error')]
            .map((node) => node.textContent.trim())
            .filter(Boolean)
            .slice(0, 6),
    );
}

async function reloadEditor(page, base, id) {
    const response = await page.goto(editUrl(base, id), { waitUntil: 'domcontentloaded', timeout: 60000 });
    if (!response || response.status() >= 400) {
        throw fail(`reloading the editor answered HTTP ${response ? response.status() : '?'}`);
    }
    if (/\/login|\/auth\//.test(page.url())) {
        throw fail('admin session expired while reloading the editor');
    }
    await waitForEditor(page);
}

function clone(value) {
    return structuredClone(value);
}

function entriesByRole(state, role) {
    return Object.entries(state.requirements || {}).filter(([, requirement]) => requirement?.role === role);
}

function findRequirement(state, role, requirementOs) {
    const entries = entriesByRole(state, role).filter(([, requirement]) => {
        if (requirementOs === undefined) {
            return true;
        }
        return (requirement.os ?? null) === requirementOs;
    });

    if (entries.length === 0) {
        const known = entriesByRole(state, role)
            .map(([, requirement]) => requirement.os ?? 'any')
            .join(', ');
        throw fail(
            `recipe has no requirement with role="${role}"${requirementOs ? ` and os="${requirementOs}"` : ''}` +
                (known ? ` (found ${role} for: ${known})` : ''),
        );
    }
    if (entries.length > 1) {
        throw fail(`recipe has ${entries.length} requirements with role="${role}"` + (requirementOs ? ` and os="${requirementOs}"` : '') + '; refusing to guess');
    }

    return entries[0];
}

function makeChange(changes, field, from, to, target) {
    if (isDeepStrictEqual(from, to)) {
        return;
    }
    changes.push({ field, from, to, target });
}

function parsePlatforms(value) {
    const platforms = String(value)
        .split(',')
        .map((item) => item.trim().toLowerCase())
        .filter(Boolean);

    if (platforms.length === 0) {
        throw usageError('--platforms needs at least one of windows,linux,macos');
    }

    const unknown = platforms.filter((platform) => !PLATFORMS.includes(platform));
    if (unknown.length > 0) {
        throw usageError(`unknown platform(s): ${unknown.join(', ')} (allowed: ${PLATFORMS.join(', ')})`);
    }

    if (new Set(platforms).size !== platforms.length) {
        throw usageError('--platforms contains duplicates');
    }

    return platforms;
}

function parsePatterns(value) {
    return String(value)
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);
}

function parseUrl(value, label) {
    const url = String(value).trim();
    if (url === '') {
        return null;
    }
    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        throw usageError(`${label} is not a valid URL: ${url}`);
    }
    if (!['http:', 'https:'].includes(parsed.protocol)) {
        throw usageError(`${label} must be http(s): ${url}`);
    }
    return url;
}

function parseSha(value, label) {
    const sha = String(value).trim();
    if (sha === '') {
        return null;
    }
    if (!SHA256_RE.test(sha)) {
        throw usageError(`${label} must be 64 hex characters`);
    }
    return sha.toLowerCase();
}

function semanticValue(state, target) {
    if (target.kind === 'platforms') {
        return state.platforms ?? [];
    }
    if (target.kind === 'iso') {
        const [, requirement] = findRequirement(state, 'iso');
        return requirement[target.field] ?? null;
    }
    if (target.kind === 'patch') {
        const [, requirement] = findRequirement(state, 'patch', target.os);
        return requirement[target.field] ?? null;
    }
    if (target.kind === 'top') {
        return state[target.key] ?? null;
    }
    throw new Error(`unknown target kind ${target.kind}`);
}

function canonical(value) {
    if (Array.isArray(value)) {
        return value.map(canonical);
    }
    if (value && typeof value === 'object') {
        const keys = Object.keys(value);
        if (keys.length > 0 && keys.every((key) => UUID_RE.test(key))) {
            return keys.map((key) => canonical(value[key]));
        }
        const result = {};
        for (const key of keys.sort()) {
            result[key] = canonical(value[key]);
        }
        return result;
    }
    return value;
}

function semanticEqual(a, b) {
    return isDeepStrictEqual(canonical(a), canonical(b));
}

async function backupPayload(state, base) {
    return {
        schema: 'lanomat.ut2004.live-recipe/v1',
        saved_at: new Date().toISOString(),
        base_url: base,
        recipe: {
            id: state.id,
            slug: state.slug,
            status: state.status,
        },
        data: state,
    };
}

async function writeBackup(state, base, out, backupDir = '/tmp/opencode') {
    const payload = await backupPayload(state, base);

    if (out === '-') {
        return { payload, file: '-' };
    }

    let file = out;
    if (!file) {
        const dir = path.resolve(backupDir);
        await fsp.mkdir(dir, { recursive: true });
        file = path.join(dir, `ut2004-recipe-${state.id}-${nowStamp()}.json`);
    } else {
        file = path.resolve(file);
        await fsp.mkdir(path.dirname(file), { recursive: true });
    }

    await fsp.writeFile(file, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
    return { payload, file };
}

async function commandShow(options) {
    const session = await openEditor(options);
    try {
        const state = await readState(session.page);
        const output = {
            id: state.id,
            slug: state.slug,
            title: state.title,
            version: state.version,
            status: state.status,
            platforms: state.platforms,
            requirements: state.requirements,
            steps: state.steps,
            metadata: state.metadata,
            updated_at: state.updated_at,
        };
        console.log(JSON.stringify(output, null, 2));
        return 0;
    } finally {
        await session.browser.close().catch(() => {});
    }
}

async function commandPlan(options) {
    const requestedOs = String(options.os || '').toLowerCase();
    if (!['linux', 'windows', 'macos'].includes(requestedOs)) {
        throw usageError('plan needs --os linux|windows|macos');
    }

    const config = await readLauncherConfig(options);
    const base = baseUrl(options, config);
    const token = String(config.token || '').trim();

    if (!token) {
        throw fail(`no bearer token in ${config.file}; run the launcher login first`);
    }

    const slug = String(options.slug || DEFAULT_SLUG);
    const url = `${base}/api/v1/recipes/${encodeURIComponent(slug)}/plan`;
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                os: requestedOs,
                dry_run: !options.record,
            }),
        });
    } catch (error) {
        throw fail(`plan request failed: ${error.message}`);
    }

    const text = await response.text();
    let body;
    try {
        body = JSON.parse(text);
    } catch {
        body = { message: text.slice(0, 300) };
    }

    if (!response.ok) {
        const message = body?.message || body?.error || `HTTP ${response.status}`;
        throw fail(`plan request answered HTTP ${response.status}: ${String(message).slice(0, 500)}`);
    }

    const files = Array.isArray(body.files) ? body.files : [];
    const missing = Array.isArray(body.missing) ? body.missing : [];
    const output = {
        os: body.os,
        recipe: body.recipe?.slug ?? slug,
        dry_run: !options.record,
        target_dir: body.target_dir,
        files: files.map((file) => ({
            requirement: file.requirement,
            label: file.label,
            os: file.os,
            status: file.status,
            required: Boolean(file.required),
        })),
        missing,
    };

    console.log(JSON.stringify(output, null, 2));
    return 0;
}

async function buildSetPlan(state, options) {
    const desired = clone(state);
    const changes = [];
    const touched = new Set();

    if (options['iso-url'] !== undefined) {
        const [, requirement] = findRequirement(state, 'iso');
        const value = parseUrl(options['iso-url'], '--iso-url');
        if (!isDeepStrictEqual(requirement.url ?? null, value)) {
            const key = findRequirementKey(state, 'iso');
            desired.requirements[key].url = value;
            changes.push({
                field: 'requirements[iso].url',
                from: requirement.url ?? null,
                to: value,
                target: { kind: 'iso', field: 'url' },
            });
            touched.add('requirements');
        }
    }

    if (options['iso-match'] !== undefined) {
        const [, requirement] = findRequirement(state, 'iso');
        const value = parsePatterns(options['iso-match']);
        if (!isDeepStrictEqual(requirement.match ?? [], value)) {
            const key = findRequirementKey(state, 'iso');
            desired.requirements[key].match = value;
            changes.push({
                field: 'requirements[iso].match',
                from: requirement.match ?? [],
                to: value,
                target: { kind: 'iso', field: 'match' },
            });
            touched.add('requirements');
        }
    }

    if (options.platforms !== undefined) {
        const value = parsePlatforms(options.platforms);
        if (!isDeepStrictEqual(state.platforms ?? [], value)) {
            desired.platforms = value;
            changes.push({
                field: 'platforms',
                from: state.platforms ?? [],
                to: value,
                target: { kind: 'platforms' },
            });
            touched.add('platforms');
        }
    }

    for (const osName of ['linux', 'macos']) {
        const urlKey = `patch-${osName}-url`;
        const shaKey = `patch-${osName}-sha`;

        if (options[urlKey] === undefined && options[shaKey] === undefined) {
            continue;
        }

        const [key, requirement] = findRequirement(state, 'patch', osName);

        if (options[urlKey] !== undefined) {
            const value = parseUrl(options[urlKey], `--${urlKey}`);
            if (!isDeepStrictEqual(requirement.url ?? null, value)) {
                desired.requirements[key].url = value;
                changes.push({
                    field: `requirements[patch:${osName}].url`,
                    from: requirement.url ?? null,
                    to: value,
                    target: { kind: 'patch', os: osName, field: 'url' },
                });
                touched.add('requirements');
            }
        }

        if (options[shaKey] !== undefined) {
            const value = parseSha(options[shaKey], `--${shaKey}`);
            if (!isDeepStrictEqual(requirement.sha256 ?? null, value)) {
                desired.requirements[key].sha256 = value;
                changes.push({
                    field: `requirements[patch:${osName}].sha256`,
                    from: requirement.sha256 ?? null,
                    to: value,
                    target: { kind: 'patch', os: osName, field: 'sha256' },
                });
                touched.add('requirements');
            }
        }
    }

    return { desired, changes, touched };
}

function findRequirementKey(state, role, requirementOs) {
    return findRequirement(state, role, requirementOs)[0];
}

function verifySet(before, after, changes) {
    const problems = [];

    if (before.status === 'published' && after.status !== 'published') {
        problems.push(`status changed from published to ${after.status ?? '(missing)'}`);
    } else if (after.status !== before.status) {
        problems.push(`status changed from ${before.status} to ${after.status}`);
    }

    if (!semanticEqual(before.metadata, after.metadata)) {
        problems.push('metadata changed');
    }

    for (const change of changes) {
        const actual = semanticValue(after, change.target);
        if (!isDeepStrictEqual(actual, change.to)) {
            problems.push(`${change.field} did not round-trip (expected ${JSON.stringify(change.to)}, got ${JSON.stringify(actual)})`);
        }
    }

    return problems;
}

async function applySet(options) {
    if (options.apply && !options.yes) {
        throw usageError('--apply needs --yes (explicit confirmation); nothing was saved');
    }

    const session = await openEditor(options);
    const { page, base, id } = session;

    try {
        const before = await readState(page);
        const { desired, changes, touched } = await buildSetPlan(before, options);
        const spec = {
            recipe: { id: before.id, slug: before.slug },
            status: before.status,
            changes: changes.map(({ field, from, to }) => ({ field, from, to })),
            metadata_preserved: true,
        };

        if (changes.length === 0 && !options.apply) {
            console.log(JSON.stringify({ ...spec, applied: false, changed: false, message: 'no changes' }, null, 2));
            return 0;
        }

        if (!options.apply) {
            console.log(JSON.stringify({ ...spec, applied: false, changed: changes.length > 0, dry_run: true }, null, 2));
            return 0;
        }

        const backup = await writeBackup(before, base, undefined, options['backup-dir'] || '/tmp/opencode');

        if (touched.has('requirements')) {
            await setField(page, 'data.requirements', desired.requirements);
        }
        if (touched.has('platforms')) {
            await setField(page, 'data.platforms', desired.platforms);
        }

        const staged = await readState(page);
        if (staged.status !== before.status) {
            throw fail(`status changed in the browser before saving (${before.status} -> ${staged.status}); aborted`);
        }
        if (!semanticEqual(staged.metadata, before.metadata)) {
            throw fail('metadata changed in the browser before saving; aborted');
        }

        await saveEditor(page);
        await reloadEditor(page, base, id);
        const after = await readState(page);
        const problems = verifySet(before, after, changes);

        if (problems.length > 0) {
            const visible = await validationErrors(page);
            throw fail(`save verification failed: ${problems.join('; ')}${visible.length ? ` (${visible.join(' | ')})` : ''}`);
        }

        console.log(
            JSON.stringify(
                {
                    ...spec,
                    applied: true,
                    saved: true,
                    verified: true,
                    status_after: after.status,
                    metadata_unchanged: true,
                    updated_at: { before: before.updated_at, after: after.updated_at },
                    backup_file: backup.file,
                },
                null,
                2,
            ),
        );
        return 0;
    } finally {
        await session.browser.close().catch(() => {});
    }
}

async function commandSet(options) {
    const known = [
        'iso-url',
        'iso-match',
        'platforms',
        'patch-linux-url',
        'patch-linux-sha',
        'patch-macos-url',
        'patch-macos-sha',
    ];
    if (!known.some((key) => options[key] !== undefined)) {
        throw usageError(`set needs at least one of --${known.join(', --')}`);
    }

    return applySet(options);
}

async function commandBackup(options) {
    const session = await openEditor(options);
    try {
        const state = await readState(session.page);
        const result = await writeBackup(state, session.base, options.out);
        if (options.out === '-') {
            console.log(JSON.stringify(result.payload, null, 2));
            return 0;
        }
        console.log(
            JSON.stringify(
                {
                    ok: true,
                    file: result.file,
                    recipe: { id: state.id, slug: state.slug, status: state.status },
                    saved_at: result.payload.saved_at,
                },
                null,
                2,
            ),
        );
        return 0;
    } finally {
        await session.browser.close().catch(() => {});
    }
}

function readBackupState(payload) {
    if (payload && typeof payload === 'object' && payload.data && typeof payload.data === 'object') {
        return payload.data;
    }
    if (payload && typeof payload === 'object' && payload.state && typeof payload.state === 'object') {
        return payload.state;
    }
    if (payload && typeof payload === 'object' && payload.slug) {
        return payload;
    }
    throw fail('backup file does not contain a recipe state (expected data/state or the state itself)');
}

function restoreChanges(live, backup) {
    const changes = [];
    for (const key of RESTORE_KEYS) {
        if (!(key in backup)) {
            continue;
        }
        if (!semanticEqual(live[key], backup[key])) {
            changes.push(key);
        }
    }
    return changes;
}

function apiPayload(state) {
    const requirements = Object.values(state.requirements || {}).map((requirement) => {
        const copy = clone(requirement);
        delete copy.shared_file_id;
        return copy;
    });

    const steps = Object.values(state.steps || {}).map((step) => {
        const copy = clone(step);
        if (copy.commands && !Array.isArray(copy.commands)) {
            copy.commands = Object.values(copy.commands);
        }
        if (!Array.isArray(copy.commands)) {
            copy.commands = [];
        }
        return copy;
    });

    return {
        slug: state.slug,
        title: state.title,
        aliases: state.aliases ?? [],
        version: state.version ?? null,
        platforms: state.platforms ?? [],
        summary: state.summary ?? null,
        description: state.description ?? null,
        homepage: state.homepage ?? null,
        license: state.license ?? null,
        requirements,
        steps,
        metadata: state.metadata ?? {},
        skill_markdown: state.skill_markdown || undefined,
    };
}

async function contributeMetadata(state, options) {
    const config = await readLauncherConfig(options);
    const base = baseUrl(options, config);
    const token = String(config.token || '').trim();

    if (!token) {
        throw fail(`metadata restore needs a bearer token in ${config.file}`);
    }

    const url = `${base}/api/v1/recipes`;
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(apiPayload(state)),
        });
    } catch (error) {
        throw fail(`metadata restore request failed: ${error.message}`);
    }

    const text = await response.text();
    let body;
    try {
        body = JSON.parse(text);
    } catch {
        body = { message: text.slice(0, 300) };
    }

    if (!response.ok) {
        const message = body?.message || body?.error || `HTTP ${response.status}`;
        throw fail(`metadata restore answered HTTP ${response.status}: ${String(message).slice(0, 800)}`);
    }

    return body;
}

async function commandRestore(options) {
    if (!options.file) {
        throw usageError('restore needs --file FILE (use - for stdin)');
    }
    if (options.apply && !options.yes) {
        throw usageError('--apply needs --yes (explicit confirmation); nothing was saved');
    }

    const raw = options.file === '-' ? await readStdin() : await fsp.readFile(path.resolve(options.file), 'utf8');
    let parsed;
    try {
        parsed = JSON.parse(raw);
    } catch (error) {
        throw fail(`backup is not valid JSON: ${error.message}`);
    }

    const backup = readBackupState(parsed);

    const session = await openEditor(options);
    const { page, base, id } = session;

    try {
        const live = await readState(page);

        if (backup.slug && live.slug && backup.slug !== live.slug) {
            throw fail(`backup is for ${backup.slug}, but the editor shows ${live.slug}; refusing to cross recipes`);
        }

        const changed = restoreChanges(live, backup);
        const metadataDiffers = 'metadata' in backup && !semanticEqual(live.metadata, backup.metadata);
        const metadataViaApi = metadataDiffers && !options['keep-metadata'];
        const spec = {
            recipe: { id: live.id, slug: live.slug },
            status: live.status,
            changed,
            metadata_changed: metadataDiffers,
            metadata_restore: options['keep-metadata'] ? 'keep-live' : metadataViaApi ? 'livewire+api-fallback' : 'not-needed',
        };

        if (changed.length === 0 && !options.apply) {
            console.log(JSON.stringify({ ...spec, applied: false, changed: false, message: 'backup already matches the live state' }, null, 2));
            return 0;
        }

        if (!options.apply) {
            console.log(JSON.stringify({ ...spec, applied: false, dry_run: true }, null, 2));
            return 0;
        }

        const before = live;
        const backupFile = await writeBackup(before, base, undefined, options['backup-dir'] || '/tmp/opencode');

        for (const key of changed) {
            if (key === 'metadata' && metadataViaApi) {
                // Filament has no metadata field; writing it via the form is a no-op.
                // It is handled by the API fallback below.
                await setField(page, `data.${key}`, backup[key]);
                continue;
            }
            await setField(page, `data.${key}`, backup[key]);
        }

        await saveEditor(page);
        await reloadEditor(page, base, id);
        let after = await readState(page);

        const nonMetadataProblems = [];
        for (const key of changed) {
            if (key === 'metadata') {
                continue;
            }
            if (!semanticEqual(after[key], backup[key])) {
                nonMetadataProblems.push(key);
            }
        }
        if (after.status !== (backup.status ?? before.status)) {
            nonMetadataProblems.push(`status (expected ${backup.status ?? before.status}, got ${after.status})`);
        }
        if (nonMetadataProblems.length > 0) {
            throw fail(`restore verification failed for: ${nonMetadataProblems.join(', ')}`);
        }

        let metadataRestoredVia = null;

        if (metadataViaApi) {
            await contributeMetadata(backup, options);
            await reloadEditor(page, base, id);

            // ContributeRecipe recreates the file rows, which drops the LAN
            // pins. Re-apply the backup's requirements through Filament so the
            // pins come back; Filament leaves metadata alone.
            if (backup.requirements !== undefined) {
                await setField(page, 'data.requirements', backup.requirements);
                await saveEditor(page);
            }
            await reloadEditor(page, base, id);
            after = await readState(page);
            metadataRestoredVia = 'api';
        }

        const problems = [];
        for (const key of RESTORE_KEYS) {
            if (!(key in backup)) {
                continue;
            }
            if (key === 'metadata' && options['keep-metadata']) {
                if (!semanticEqual(after.metadata, before.metadata)) {
                    problems.push('metadata (keep-live violated)');
                }
                continue;
            }
            if (!semanticEqual(after[key], backup[key])) {
                problems.push(key);
            }
        }

        if (problems.length > 0) {
            throw fail(`restore verification failed: ${problems.join(', ')}`);
        }

        console.log(
            JSON.stringify(
                {
                    ...spec,
                    applied: true,
                    saved: true,
                    verified: true,
                    status_after: after.status,
                    metadata_restored_via: metadataRestoredVia,
                    updated_at: { before: before.updated_at, after: after.updated_at },
                    backup_file: backupFile.file,
                },
                null,
                2,
            ),
        );
        return 0;
    } finally {
        await session.browser.close().catch(() => {});
    }
}

function readStdin() {
    return new Promise((resolve, reject) => {
        let data = '';
        process.stdin.setEncoding('utf8');
        process.stdin.on('data', (chunk) => {
            data += chunk;
        });
        process.stdin.on('end', () => resolve(data));
        process.stdin.on('error', reject);
    });
}

async function main() {
    let options;
    try {
        options = parseArgs(process.argv.slice(2));
    } catch (error) {
        console.error(`error: ${error.message}`);
        console.error('run with --help for usage');
        return error.exitCode ?? 2;
    }

    if (options.help || !options.command) {
        console.log(helpText(options.command));
        return 0;
    }

    try {
        switch (options.command) {
            case 'show':
                return await commandShow(options);
            case 'plan':
                return await commandPlan(options);
            case 'set':
                return await commandSet(options);
            case 'backup':
            case 'dump':
                return await commandBackup(options);
            case 'restore':
                return await commandRestore(options);
            default:
                throw usageError(`unknown command ${options.command}`);
        }
    } catch (error) {
        console.error(`error: ${error.message}`);
        if (error.exitCode === 2) {
            console.error('run with --help for usage');
        }
        return error.exitCode ?? 1;
    }
}

const code = await main();
process.exit(code);
