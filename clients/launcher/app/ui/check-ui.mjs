#!/usr/bin/env node
// Dependency-free contract check for the LANoMAT Launcher UI (L5).
//
// It is a static review gate: required files, element ids, German copy, the exact shell
// command/event names, the Signalpult token rule (raw hex only in tier 1), accessibility
// hooks and local-only assets. The real click-through lives in the L11 shell smoke.
//
// Run: node clients/launcher/app/ui/check-ui.mjs

import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const failures = [];
let checks = 0;

function check(condition, label) {
    checks += 1;

    if (!condition) {
        failures.push(label);
    }
}

function read(relative) {
    const path = join(root, relative);

    if (!existsSync(path)) {
        failures.push(`missing file: ${relative}`);
        checks += 1;

        return '';
    }

    return readFileSync(path, 'utf8');
}

function tagFor(html, id) {
    const index = html.indexOf(`id="${id}"`);

    if (index < 0) {
        return '';
    }

    const start = html.lastIndexOf('<', index);
    const end = html.indexOf('>', index);

    return start >= 0 && end > start ? html.slice(start, end + 1) : '';
}

const html = read('index.html');
const css = read('app.css');
const readme = read('README.md');

const jsFiles = [
    'js/main.js',
    'js/state.js',
    'js/connect.js',
    'js/chat.js',
    'js/library.js',
    'js/share.js',
    'js/install.js',
    'js/ready.js',
    'js/qr.js',
    'js/nav.js',
    'js/util.js',
    'js/strings.js',
];

const sources = new Map(jsFiles.map((file) => [file, read(file)]));
const js = [...sources.values()].join('\n');

const fontFiles = [
    'fonts/space-grotesk-latin-400-normal.woff2',
    'fonts/space-grotesk-latin-500-normal.woff2',
    'fonts/space-grotesk-latin-600-normal.woff2',
    'fonts/space-grotesk-latin-700-normal.woff2',
    'fonts/jetbrains-mono-latin-400-normal.woff2',
    'fonts/jetbrains-mono-latin-500-normal.woff2',
    'fonts/LICENSE-space-grotesk.txt',
    'fonts/LICENSE-jetbrains-mono.txt',
];

for (const file of fontFiles) {
    check(existsSync(join(root, file)), `missing font/licence: ${file}`);
}

check(existsSync(join(root, 'logo-mark.svg')), 'missing logo-mark.svg');
check(!/Placeholder until the launcher UI wave lands/i.test(js), 'placeholder JS still present');

// ---- HTML shell ----
check(/<html\s+lang="de"/.test(html), 'html lang="de"');
check(/<meta\s+charset="utf-8"/i.test(html), 'charset meta');
check(/name="viewport"/.test(html), 'viewport meta');
check(/href="app\.css"/.test(html), 'app.css stylesheet');
check(/src="js\/main\.js"/.test(html), 'js/main.js module');
check(/<script[^>]+type="module"[^>]+src="js\/main\.js"/.test(html), 'module script');
check(/value="https:\/\/lan\.raute1\.org"/.test(html), 'default server URL in the input');
check(/<label\s+for="connect-url"/.test(html), 'server URL label');
check(!/<style[\s>]/i.test(html), 'no <style> block in index.html');
check(!/\sstyle\s*=/i.test(html), 'no inline style attribute');
check(
    [...html.matchAll(/<script\b([^>]*)>/gi)].every((match) =>
        /\bsrc\s*=/i.test(match[1]),
    ),
    'no inline <script> without src',
);
check(!/\son(click|submit|change|input)\s*=/i.test(html), 'no inline event handlers (CSP)');
check(!/<link[^>]+href="https?:/i.test(html), 'no external stylesheet');
check(!/<script[^>]+src="https?:\/\//i.test(html), 'no external script');
check(!/<img[^>]+src="https?:\/\//i.test(html), 'no external image');

// ---- hardened CSP (R2 F7) ----
// The window has no plugin or <base> surface, never submits a form by navigation and
// must not be framed. Tauri injects nonces for its own bootstrap; the UI itself ships
// no inline script or style, so the only allowance kept is style-src 'unsafe-inline'
// for the progress bar's style.width.
const configPath = join(root, '..', 'tauri.conf.json');
let csp = '';

try {
    csp = JSON.parse(readFileSync(configPath, 'utf8'))?.app?.security?.csp ?? '';
} catch {
    csp = '';
}

check(typeof csp === 'string' && csp.length > 0, 'tauri.conf.json carries a CSP');

for (const directive of [
    "default-src 'self'",
    "font-src 'self'",
    "img-src 'self' data:",
    "style-src 'self' 'unsafe-inline'",
    'connect-src ipc: http://ipc.localhost',
    "object-src 'none'",
    "base-uri 'none'",
    "form-action 'none'",
    "frame-ancestors 'none'",
]) {
    check(csp.includes(directive), `CSP directive: ${directive}`);
}

check(!/unsafe-eval/.test(csp), 'CSP has no unsafe-eval');
check(!/\*/.test(csp), 'CSP has no wildcard source');

const requiredIds = [
    'main',
    'live',
    'toast',
    'who',
    'who-dot',
    'who-text',
    'logout',
    'offline-banner',
    'offline-text',
    'offline-retry',
    'tpl-game',
    'tpl-server',
    'screen-boot',
    'screen-connect',
    'connect-title',
    'connect-form',
    'connect-url',
    'connect-url-hint',
    'connect-form-error',
    'connect-submit',
    'connect-device',
    'connect-code',
    'connect-link',
    'connect-open',
    'connect-open-error',
    'connect-cancel',
    'connect-wait',
    'connect-countdown',
    'connect-slow',
    'connect-slow-secs',
    'connect-qr-wrap',
    'connect-qr',
    'connect-qr-title',
    'connect-qr-hint',
    'connect-qr-fallback',
    'connect-error',
    'connect-error-title',
    'connect-error-text',
    'connect-retry',
    'connect-change',
    'screen-chat',
    'chat-title',
    'chat-server',
    'chat-server-name',
    'chat-skip',
    'chat-clear',
    'chat-log',
    'chat-hint',
    'chat-suggestions',
    'chat-form',
    'chat-input',
    'chat-send',
    'start-live-title',
    'start-live-lead',
    'start-live-loading',
    'start-live-empty',
    'start-live-error',
    'start-live-error-text',
    'start-live-retry',
    'start-live-list',
    'start-games',
    'start-servers',
    'start-share',
    'start-share-hint',
    'start-chat',
    'screen-library',
    'library-title',
    'library-server',
    'library-server-name',
    'library-refresh',
    'library-share',
    'library-share-hint',
    'library-chat',
    'library-loading',
    'library-empty',
    'library-empty-retry',
    'library-empty-share',
    'library-error',
    'library-error-text',
    'library-retry',
    'library-reconnect',
    'game-list',
    'screen-install',
    'install-title',
    'install-back',
    'install-game-title',
    'install-version',
    'install-phases',
    'install-message',
    'install-progress',
    'install-progress-fill',
    'install-percent',
    'install-file',
    'install-bytes',
    'install-speed',
    'install-eta',
    'install-cancel',
    'install-hint',
    'install-error',
    'install-error-text',
    'install-error-raw',
    'install-retry',
    'install-back-error',
    'install-cancelled',
    'install-retry-cancelled',
    'install-back-cancelled',
    'screen-ready',
    'ready-title',
    'ready-game',
    'ready-card',
    'ready-path',
    'ready-version-row',
    'ready-version',
    'ready-error',
    'ready-launch',
    'ready-repair',
    'ready-browser',
    'ready-library',
    'ready-launched',
    'ready-empty',
    'ready-empty-library',
    'device',
    'device-label',
    'device-name',
    'device-hint',
    'arcade-badge',
    'gamepad',
    'gamepad-dot',
    'gamepad-label',
    'library-installed-block',
    'library-installed-title',
    'library-installed',
    'library-installed-empty',
    'library-installed-empty-share',
    'library-catalog-head',
];

for (const id of requiredIds) {
    check(html.includes(`id="${id}"`), `missing id="${id}"`);
}

for (const screen of ['connect', 'chat', 'library', 'install', 'ready']) {
    const tag = tagFor(html, `screen-${screen}`);

    check(Boolean(tag), `screen-${screen} section`);
    check(/\shidden(\s|>)/.test(tag), `screen-${screen} starts hidden`);
}

for (const phase of ['planning', 'downloading', 'extracting', 'patching', 'verifying', 'ready']) {
    check(html.includes(`data-phase="${phase}"`), `phase item ${phase}`);
}

check(/id="live"[^>]*aria-live="polite"/.test(html), 'screen-reader live region');
check(/id="toast"[^>]*role="status"/.test(html), 'toast status region');
check(/id="install-message"[^>]*aria-live="polite"/.test(html), 'install message aria-live');

const progressTag = tagFor(html, 'install-progress');

check(/role="progressbar"/.test(progressTag), 'progressbar role');
check(/aria-valuemin="0"/.test(progressTag), 'progressbar aria-valuemin');
check(/aria-valuemax="100"/.test(progressTag), 'progressbar aria-valuemax');
check(/aria-labelledby="install-message"/.test(progressTag), 'progressbar labelled by message');

for (const id of [
    'connect-form-error',
    'connect-error',
    'library-error',
    'install-error',
    'ready-error',
]) {
    check(/role="alert"/.test(tagFor(html, id)), `${id} is an alert`);
}

check(/id="library-loading"[^>]*aria-busy="true"/.test(html), 'library loading aria-busy');
check(/id="screen-boot"[^>]*aria-busy="true"/.test(html), 'boot aria-busy');

const buttons = html.match(/<button\b[\s\S]*?<\/button>/g) || [];

check(buttons.length >= 12, 'expected buttons present');

for (const button of buttons) {
    const label = button
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    check(Boolean(label) || /aria-label=/.test(button), 'button without a label');
}

const buttonAnchors =
    html.match(/<a\b[^>]*class="[^"]*\bbtn\b[^"]*"[\s\S]*?<\/a\s*>/g) || [];

check(buttonAnchors.length >= 1, 'server browser link button');

// The server browser link follows the paired server: a LAN-only install has no public
// host, so a fixed lan.raute1.org/servers would be wrong. No base → link hidden.
const readyJs = sources.get('js/ready.js');

check(!/lan\.raute1\.org\/servers/i.test(`${html}\n${js}`), 'server browser URL is not hardcoded');
check(readyJs.includes('state.url'), 'server browser URL derives from the paired server');
check(readyJs.includes('SERVERS_PATH'), 'server browser URL builds on the known base');
check(/link\.hidden\s*=\s*!url/.test(readyJs), 'server browser link hides without a known URL');
check(/\.replace\(\/\\\/\+\$\/,\s*''\)/.test(readyJs), 'server browser base trims trailing slashes');
check(readyJs.includes('https?:'), 'server browser URL keeps the http(s) allowlist');
check(!js.includes('S.serversUrl'), 'no fixed public server browser URL');

// ---- chat: deterministic intents, skippable demo, aggregated server cards ----
const chatJs = sources.get('js/chat.js');
const connectJs = sources.get('js/connect.js');
const qrJs = sources.get('js/qr.js');

check(/export function intentOf/.test(chatJs), 'chat exposes a deterministic intent parser');
check(/\\b\(server\|servers\|adresse\|ip\|beitreten/.test(chatJs), 'chat recognizes the server intent');
check(/\\b\(spiel\|spiele\|game\|games/.test(chatJs), 'chat recognizes the games intent');
check(/list_servers/.test(chatJs), 'chat loads the aggregated server list');
check(/list_games/.test(chatJs), 'chat loads the games list');
check(/start-live-list/.test(chatJs), 'the start page paints the live server list');
check(/joinServer/.test(chatJs), 'a live server has a one-click join');
check(
    /install\.begin\(recipe, \{ join \}\)/.test(chatJs),
    'the one-click join keeps the server through the install wizard',
);
check(/prefers-reduced-motion/.test(chatJs), 'chat demo respects reduced motion');
check(/prefers-reduced-motion/.test(qrJs) || /prefers-reduced-motion/.test(chatJs), 'chat demo has a reduced-motion path');
check(/demoType|demoClear/.test(chatJs), 'chat demo types and backs spaces out');
check(/data-intent/.test(chatJs), 'suggestion chips drive the demo');
check(/chat-skip/.test(sources.get('js/main.js')), 'chat demo is skippable to the library');
check(/joinUrlOf/.test(chatJs) && /open_url/.test(chatJs), 'server cards open a join link through open_url');
check(/pageUrlOf/.test(chatJs) && /SERVERS_PATH/.test(chatJs), 'server cards fall back to the LANoMAT server page');
check(/permanent/.test(chatJs) && /events\//.test(chatJs), 'server page URL distinguishes permanent and event servers');
check(/server-grid/.test(html) === false, 'server cards are built from the template, not hardcoded');
check(/id="tpl-server"/.test(html), 'server card template exists');
check(/connect-qr/.test(html) && /connect-qr/.test(connectJs), 'connect screen carries a QR area');
check(/verification_uri_complete/.test(connectJs), 'QR encodes the complete verification URL');
check(/device\.qr_payload/.test(connectJs), 'the QR payload is the one the shell chose (qr_payload)');
check(/export function render/.test(qrJs), 'QR encoder renders locally');
check(/reedSolomon/.test(qrJs), 'QR encoder computes Reed-Solomon ECC');

const qrModule = await import(join(root, 'js/qr.js'));
const qrSample = qrModule.encode(
    'https://lan.example/mcp/connect?code=ABCD-EFGH',
);

check(Boolean(qrSample), 'QR encodes a realistic verification URL');
check(qrSample?.size >= 21 && qrSample.size <= 57, 'QR matrix has a valid size');
check(qrSample?.modules?.[0]?.[0] === true, 'QR starts with a finder pattern');
check(
    qrSample?.modules?.some((row) => row.some((value) => value === true)),
    'QR matrix carries dark modules',
);
check(/Mit Handy scannen/.test(html), 'QR area carries the scan label');
check(/connect-qr-fallback/.test(html) && /connect-qr-fallback/.test(connectJs), 'QR has a code/link fallback');

// ---- German copy (strings + markup) ----
const copy = `${html}\n${js}`;
const requiredStrings = [
    'Verbinden',
    'Gerät freischalten',
    'Browser öffnen',
    'Warte auf Bestätigung',
    'Code abgelaufen',
    'Anmeldung abgelehnt',
    'Neuen Code erzeugen',
    'Serveradresse ändern',
    'Spiele',
    'Installieren',
    'Starten',
    'Neu installieren',
    'Reparieren',
    'Installation reparieren',
    'Installiert nach',
    'Installation',
    'Herunterladen',
    'Entpacken',
    'Patchen',
    'Prüfen',
    'Abbrechen',
    'Wird abgebrochen',
    'Installation abgebrochen',
    'Erneut versuchen',
    'Startklar',
    'Serverbrowser',
    'Zur Bibliothek',
    'Aktualisieren',
    'Deep-Link',
    'Chat',
    'Welche Spiele gibt es?',
    'Welche Server laufen?',
    'Jetzt live',
    'Beitreten',
    'LANoMAT-Seite',
    'Mit Handy scannen',
    'Kein Handy zur Hand',
    'Zu den Spielen',
    'Meine Bibliothek',
    'Spiele vom Server',
    'Bald im Launcher',
    'Auf der Webseite ansehen',
    'Spiel teilen',
    'Archiv hochladen',
    'Archiv laden',
    'Ergänzen',
    'manuelle Schritte',
    'Dieses Gerät',
];

for (const text of requiredStrings) {
    check(copy.includes(text), `missing German string: ${text}`);
}

// Logout is local only: the shell deletes this machine's pairing, the bearer token stays
// valid on the server until it is revoked on /mcp/connect. The copy must say so instead
// of implying a full sign-out.
const headerStrings = sources.get('js/strings.js');

check(headerStrings.includes('/mcp/connect'), 'logout hint names the /mcp/connect revoke page');
check(
    /Trennt nur dieses Gerät/.test(headerStrings),
    'logout hint says the disconnect is local',
);
check(/id="logout-hint"/.test(html), 'logout hint element');
check(
    /id="logout"[^>]*aria-describedby="logout-hint"/.test(html),
    'logout button is described by the hint',
);

// ---- shell command and event contract ----
const commands = [
    'get_status',
    'connect_start',
    'connect_poll',
    'logout',
    'list_games',
    'list_servers',
    'install',
    'cancel_install',
    'verify_install',
    'launch',
    'open_url',
    'take_pending_deep_link',
];

for (const command of commands) {
    check(js.includes(`'${command}'`), `missing invoke command: ${command}`);
}

check(js.includes("'install-progress'"), 'missing install-progress listener');
check(js.includes("'deep-link'"), 'missing deep-link listener');
check(js.includes('take_pending_deep_link'), 'missing pending deep-link boot call');
check(!js.includes("'start_device'"), 'start_device is not an L4 command');
check(!js.includes("'get_plan'"), 'library scope must not call get_plan');
check(js.includes("'pending'"), 'poll state pending');
check(js.includes("'slow_down'"), 'poll state slow_down');
check(js.includes("'connected'"), 'poll state connected');
check(js.includes("'failed'"), 'poll state failed');
check(js.includes('core.invoke'), 'window.__TAURI__.core.invoke');
check(js.includes('event.listen'), 'window.__TAURI__.event.listen');
check(js.includes('window.__TAURI__'), 'withGlobalTauri bridge');
check(!/\.innerHTML\s*=/.test(js), 'no innerHTML assignment');
check(!/\bfetch\s*\(/.test(js), 'no fetch');
check(!/XMLHttpRequest/.test(js), 'no XMLHttpRequest');
check(!/#[0-9a-fA-F]{3,6}\b/.test(js), 'no raw hex in JS');

// ---- imports resolve locally ----
for (const [file, source] of sources) {
    for (const match of source.matchAll(/from\s+'(\.[^']+)'/g)) {
        const target = normalize(join(dirname(join(root, file)), match[1]));

        check(existsSync(target), `${file}: unresolved import ${match[1]}`);
    }
}

// ---- Signalpult tokens and the no-hex rule ----
const start = css.indexOf('/* signalpult:primitives:start */');
const end = css.indexOf('/* signalpult:primitives:end */');

check(start >= 0 && end > start, 'tier-1 token markers');
check((css.match(/signalpult:primitives:start/g) || []).length === 1, 'one token block');
check((css.match(/signalpult:primitives:end/g) || []).length === 1, 'one token block end');

const hex = /#[0-9a-fA-F]{3,8}\b/g;
let primitiveHex = 0;

for (const match of css.matchAll(hex)) {
    const index = match.index ?? 0;

    if (start >= 0 && end > start && index > start && index < end) {
        primitiveHex += 1;
    } else {
        const line = css.slice(0, index).split('\n').length;

        failures.push(`raw hex outside the token block at app.css:${line} (${match[0]})`);
        checks += 1;
    }
}

check(primitiveHex >= 20, 'tier-1 palette primitives are defined once');

const roles = [
    '--background',
    '--foreground',
    '--card',
    '--primary',
    '--primary-foreground',
    '--secondary',
    '--muted',
    '--muted-foreground',
    '--accent',
    '--destructive',
    '--border',
    '--input',
    '--ring',
    '--live',
    '--ok',
    '--warn',
    '--down',
    '--radius',
];

for (const role of roles) {
    check(css.includes(`${role}:`), `missing semantic role ${role}`);
}

check(/--primary:\s*var\(--amber/.test(css), 'primary references a primitive');
check(/--live:\s*var\(--amber/.test(css), 'live references a primitive');
check(/@media\s*\(prefers-color-scheme:\s*dark\)/.test(css), 'dark mode');
check(/@media\s*\(prefers-reduced-motion:\s*reduce\)/.test(css), 'reduced motion');
check(/:focus-visible/.test(css), 'visible keyboard focus');
check(/@font-face/.test(css), 'font faces');
check(/font-family:\s*'Space Grotesk'/.test(css), 'Space Grotesk');
check(/font-family:\s*'JetBrains Mono'/.test(css), 'JetBrains Mono');
check(/@media\s*\(max-width:\s*(520|560|600|620)px\)/.test(css), 'small-window media query');
check(!/@import/.test(css), 'no CSS import');
check(!/url\(\s*['"]?https?:/i.test(css), 'no remote CSS asset');
check(css.includes('color-mix('), 'role-based translucency uses color-mix');

// ---- text-tuned status shades, live regions, phase state, footer (L10 review) ----
const installJs = sources.get('js/install.js');
const countdownTag = tagFor(html, 'connect-countdown');
const codeTag = tagFor(html, 'connect-code');

check(/--ok-text:\s*var\(--green/.test(css), 'status text role --ok-text from a primitive');
check(
    /--destructive-text:\s*var\(--red/.test(css),
    'status text role --destructive-text from a primitive',
);
check(/\.badge\.ok\s*{[^}]*color:\s*var\(--ok-text\)/s.test(css), 'badge text uses --ok-text');
check(/\.error\s*{[^}]*color:\s*var\(--destructive-text\)/s.test(css), 'error text uses --destructive-text');
check(/\.btn\.danger\s*{[^}]*color:\s*var\(--destructive-text\)/s.test(css), 'danger text uses --destructive-text');
check(/\.foot-update\s*{[^}]*color:\s*var\(--primary\)/s.test(css), 'footer update link keeps AA amber');
check(/aria-hidden="true"/.test(countdownTag), 'connect countdown is hidden from screen readers');
check(/aria-hidden="true"/.test(tagFor(html, 'connect-wait')) === false, 'connect wait text stays announced');
check(/role="group"/.test(codeTag) && /aria-label="[^"]+"/.test(codeTag), 'device code is a labelled group');
check(/phaseState/.test(installJs), 'phase rail exposes its states as text');
check(/aria-current/.test(installJs), 'current phase carries aria-current');
check(/!known/.test(installJs), 'raw install error only shown when it is unknown');
check(!/id="install-message"[^>]*>Die Installation ist fehlgeschlagen/.test(html), 'no duplicate error headline in the card');
check(/export function httpStatus/.test(sources.get('js/util.js')), 'HTTP status extraction helper');
check(js.includes('S.httpError'), 'HTTP failures are phrased in German');
check(/link\.href\s*=\s*updatePage/.test(js), 'footer update link carries its real URL');
check(html.includes('id="build-info"') && html.includes('id="build-update"'), 'footer build/update elements');
check(js.includes("'get_environment'"), 'missing invoke command: get_environment');
check(js.includes("'check_launcher_update'"), 'missing invoke command: check_launcher_update');
check(/id="library-empty-retry"[\s\S]{0,200}?Aktualisieren/.test(html), 'empty library CTA says Aktualisieren');

// ---- R5 review guards: install lifecycle, deep links, focus, honest header ----
const mainJs = sources.get('js/main.js');

check(
    /export (const|function) isRunning/.test(installJs),
    'install exports isRunning() for the deep-link guards',
);
check(
    (mainJs.match(/install\.isRunning\(\)/g) || []).length >= 2,
    'both deep-link actions stand down while an install runs',
);

const launchDeepLink = mainJs.slice(
    mainJs.indexOf('async function deepLinkLaunch'),
    mainJs.indexOf('function hasInstallRecord'),
);

check(
    launchDeepLink.includes('install.isRunning()'),
    'deep-link launch checks whether an install is running',
);
check(
    launchDeepLink.includes('S.deepLink.busy'),
    'deep-link launch answers a running install with the busy toast',
);
check(
    launchDeepLink.indexOf('install.isRunning()') >= 0 &&
        launchDeepLink.indexOf('install.isRunning()') <
            launchDeepLink.indexOf('ready.show'),
    'deep-link launch refuses before it opens the ready screen',
);
check(
    /async function routeDeepLink[\s\S]*?install\.isRunning\(\)[\s\S]*?deepLinkBusy/.test(
        mainJs,
    ),
    'the deep-link router answers a running install before it re-enters',
);

const afterInstallFn = mainJs.slice(
    mainJs.indexOf('function afterInstall'),
    mainJs.indexOf('function reconnect'),
);

check(
    afterInstallFn.includes('state.status?.paired'),
    'afterInstall requires a paired status',
);
check(
    afterInstallFn.includes("state.stage === 'connect'"),
    'afterInstall keeps the connect screen',
);
check(
    afterInstallFn.indexOf('state.status?.paired') >= 0 &&
        afterInstallFn.indexOf('state.status?.paired') <
            afterInstallFn.indexOf('ready.show'),
    'afterInstall checks the pairing before it shows the ready screen',
);

const beginFn = installJs.slice(
    installJs.indexOf('export async function begin'),
    installJs.indexOf('export function retry'),
);

check(/try\s*{/.test(beginFn), 'install.begin guards the run with try');
check(
    /} catch \(error\) {[\s\S]*?errorText\(error\)/.test(beginFn),
    'install.begin surfaces a failing listen/call',
);
check(
    /} finally {[\s\S]*?starting = false;[\s\S]*?cleanupListener\(\);/.test(beginFn),
    'install.begin clears starting and the listener in finally',
);
check(
    beginFn.indexOf('finally') >= 0 &&
        beginFn.indexOf('finally') < beginFn.indexOf('if (result.ok)'),
    'install.begin settles before it renders the result',
);
check(
    beginFn.includes('readyAnnounced = false'),
    'a retry may announce its own ready state',
);
check(
    /button\.setAttribute\('aria-disabled', 'true'\)/.test(installJs) &&
        /\.btn\[aria-disabled='true'\]/.test(css),
    'a running cancel keeps focus through aria-disabled',
);

check(
    /function showError[\s\S]*?focusAction\(\$\('install-retry'\)\)/.test(installJs),
    'install error moves focus to the retry action',
);
check(
    /function showCancelled[\s\S]*?focusAction\(\$\('install-retry-cancelled'\)\)/.test(installJs),
    'cancelled install moves focus to the retry action',
);
check(
    /function focusAction[\s\S]*?state\.stage !== 'install'/.test(installJs),
    'install focus stays off a screen that is no longer shown',
);
check(
    /export function focusNode/.test(sources.get('js/util.js')),
    'focus helper keeps the target in the document',
);

const launchFn = readyJs.slice(
    readyJs.indexOf('async function launchGame'),
    readyJs.indexOf('async function openBrowser'),
);

check(launchFn.includes('focusNode(button)'), 'launch returns focus to the launch action');
check(
    launchFn.indexOf('button.disabled = false') < launchFn.indexOf('focusNode(button)'),
    'the launch button is re-enabled before focus returns',
);

check(
    /setText\('library-server-name'/.test(mainJs),
    'library header names the paired server',
);
check(
    /paintServerName\(''\)/.test(mainJs),
    'logout clears the server name',
);
check(
    /id="library-server"[\s\S]{0,200}?id="library-server-name"/.test(html),
    'server name sits in its own mono span',
);
check(
    /classList\.toggle\('down'/.test(mainJs),
    'the who dot follows the connectivity state',
);
check(
    (installJs.match(/announce\(S\.install\.ready\)/g) || []).length === 1,
    'the ready state is announced exactly once',
);
check(
    installJs.includes('announceReady()'),
    'progress and promise share the guarded ready announcement',
);

// ---- catalogue vs local library vs device identity ----
// A terminal pairs on its own, installs locally and shares only the catalogue.
const libraryJs = sources.get('js/library.js');

check(
    libraryJs.includes('state.status?.installs'),
    'the local library reads the install ledger, not the server catalogue',
);
check(
    (libraryJs.match(/paintInstalled\(\)/g) || []).length >= 2,
    'the local library is painted before the catalogue is judged',
);
check(
    libraryJs.indexOf('paintInstalled()') <
        libraryJs.indexOf('if (!gamesResult.ok)'),
    'an offline server still leaves the installed library on screen',
);
check(
    libraryJs.includes('launcher_supported'),
    'the install action is gated by launcher_supported',
);
check(
    libraryJs.includes('recipe.installable !== false'),
    'an unknown installable flag does not read as "no"',
);
check(
    libraryJs.includes('GAMES_PATH'),
    'a "Bald im Launcher" card links to the paired server game page',
);
check(
    libraryJs.includes("call('open_url'"),
    'the web fallback opens through the shell, not by navigating the window',
);
check(
    /call\('launch'/.test(readyJs) && libraryJs.includes('ready.show'),
    'Starten reuses the offline launch path',
);
check(
    /id="library-installed-block"[\s\S]{0,800}?id="library-installed-empty"/.test(html),
    'the library has a local installed list with its own empty state',
);
check(
    /<template id="tpl-game">[\s\S]*data-action="web"/.test(html),
    'the catalogue card template carries the web fallback link',
);
check(
    /id="device-name"[^>]*class="mono[^"]*"|class="mono[^"]*"[^>]*id="device-name"/.test(html),
    'the terminal name is machine data (mono)',
);
check(
    /setText\('device-name'/.test(mainJs) && mainJs.includes("'get_environment'"),
    'the header names this terminal from get_environment',
);
check(
    /device-hint/.test(html) && sources.get('js/strings.js').includes('Jedes Gerät koppelt sich selbst'),
    'the device hint says every terminal pairs on its own',
);

// ---- R1 #2: an install record is never a dead end ----
// The ledger can outlive the folder: a moved/deleted target or an incomplete
// payload must lead back into the install flow, without touching the other
// installs. `verify_install` decides the quiet label without downloading.
check(
    /data-action="reinstall"/.test(html),
    'the catalogue card offers a quiet reinstall action next to Starten',
);
check(
    libraryJs.includes("call('verify_install'"),
    'the library checks a record through verify_install',
);
check(
    libraryJs.includes('result.value.exists') && libraryJs.includes('missing'),
    'the check keeps the folder state and the missing files',
);
check(
    /installButton\.hidden = Boolean\(record\)/.test(libraryJs) &&
        /reinstallButton\.hidden = !record/.test(libraryJs),
    'a record shows Starten and keeps a reinstall/repair action',
);
check(
    libraryJs.includes('reinstallButton.addEventListener') &&
        libraryJs.includes('install.begin(recipe)'),
    'reinstall reuses the install command',
);
check(
    /const reinstall = el\('button'[\s\S]*?install\.begin\(installTarget/.test(
        libraryJs,
    ),
    'the local library tile also keeps a repair path',
);
check(
    libraryJs.indexOf('await refreshChecks()') <
        libraryJs.indexOf('paintInstalled()'),
    'the disk check runs before the installed library is painted',
);
check(/id="ready-repair"/.test(html), 'the ready screen offers a repair action');
check(
    /ready-repair'\)\.addEventListener\('click', repair\)/.test(readyJs),
    'the ready repair action is wired',
);
check(
    /function repair[\s\S]*?install\.begin\(/.test(readyJs),
    'ready repair re-runs the install command',
);
check(
    /function paintRepair[\s\S]*?button\.hidden = !recorded/.test(readyJs),
    'repair is hidden without a recorded install',
);
check(
    /function repairable[\s\S]*?installationsordner fehlt/.test(readyJs),
    'a moved/deleted target is recognised as repairable',
);
check(
    /paintRepair\(\{ promote: stalled \}\)/.test(readyJs),
    'a repairable launch failure promotes the repair action',
);
check(
    /\.badge\.warn/.test(css) && /\.install-state\.ok/.test(css),
    'record states use the status roles',
);

// ---- sharing: /games/share in the browser, no packing in the launcher ----
// The launcher cannot pack or upload an archive. "Spiel teilen" opens the paired
// server's share page; the orga checks the upload before the game appears here.
const shareJs = sources.get('js/share.js');
const stringJs = sources.get('js/strings.js');

check(
    stringJs.includes("SHARE_PATH = '/games/share'"),
    'the share path is the paired server page',
);
check(
    !/lan\.raute1\.org\/games\/share/i.test(`${html}\n${js}`),
    'share URL is not hardcoded',
);
check(
    shareJs.includes('serverBase') && shareJs.includes('SHARE_PATH'),
    'the share URL derives from the paired server',
);
check(
    shareJs.includes('safeHttpUrl'),
    'the share URL keeps the http(s) allowlist',
);
check(
    /call\('open_url'/.test(shareJs),
    'share opens through the shell, not by navigating the window',
);
check(
    shareJs.includes('state.status?.paired'),
    'the share entry needs a paired server',
);
check(
    shareJs.includes('setHidden'),
    'the share entries are hidden without a known URL',
);
check(
    /export function sync\(\)/.test(shareJs) &&
        (mainJs.match(/share\.sync\(\)/g) || []).length >= 2,
    'the share entries follow the pairing status on every change',
);
check(
    shareJs.includes('isOffered()'),
    'the offered state gates every share entry',
);
check(
    /export async function openShare[\s\S]{0,600}?if \(!url\) {[\s\S]{0,120}?return;/.test(
        shareJs,
    ),
    'the share action refuses without a valid URL',
);
check(
    !/shareUnavailable/.test(`${shareJs}\n${stringJs}`),
    'no unreachable share-unavailable copy or branch',
);
check(
    stringJs.includes('nach der Prüfung durch die Orga'),
    'the share hint names the orga check before the game appears',
);
check(
    stringJs.includes('shareError'),
    'a failed share page has German error copy',
);
check(
    stringJs.includes('Dort kannst du das Spiel teilen'),
    'the share-opened hint says what happens in the browser',
);

for (const id of [
    'library-share',
    'start-share',
    'library-empty-share',
    'library-installed-empty-share',
]) {
    check(/\shidden(\s|>)/.test(tagFor(html, id)), `${id} starts hidden`);
    check(shareJs.includes(`'${id}'`), `share.js offers ${id}`);
}

check(
    /id="library-share"[^>]*aria-describedby="library-share-hint"/.test(html),
    'the library share entry is described by its hint',
);
check(
    /id="start-share"[^>]*aria-describedby="start-share-hint"/.test(html),
    'the start share entry is described by its hint',
);
check(
    /id="library-refresh"[\s\S]{0,600}?id="library-share"/.test(html),
    'the share entry sits next to the refresh action',
);
check(
    /id="start-servers"[\s\S]{0,600}?id="start-share"/.test(html),
    'the share entry sits among the start page main actions',
);
check(
    /id="library-empty"[\s\S]{0,1200}?id="library-empty-share"/.test(html),
    'the empty catalogue invites sharing',
);
check(
    /id="library-installed-empty"[\s\S]{0,1200}?id="library-installed-empty-share"/.test(html),
    'the empty local library invites sharing',
);
check(
    chatJs.includes('share.isOffered') && chatJs.includes('share.openShare'),
    'the empty chat games answer offers the same share entry',
);
check(
    !/nur\s+(Unreal|UT2004)|only\s+(Unreal|UT2004)/i.test(stringJs),
    'visible copy does not claim the pilot is the only installable game',
);

// ---- a catalogue of several games: honest labels for a generic install ----
check(
    stringJs.includes('phasesArchive') && /Archiv laden/.test(stringJs),
    'a portable archive install reads "Archiv laden"',
);
check(
    /Ergänzen/.test(stringJs),
    'the optional patch step has neutral wording for a generic recipe',
);
check(
    installJs.includes('labelsFor(recipe)') &&
        installJs.includes('paintPhaseLabels'),
    'the install rail is labelled per recipe kind',
);
check(
    /\$\{phaseLabel\(name\)\}/.test(installJs),
    'phase aria-labels use the active label set',
);
check(
    /stoppedAt\(phaseLabel\(lastPhase\)\)/.test(installJs),
    'the stopped-at sentence uses the active label set',
);
check(
    /progress\.message \|\| phaseLabel\(phase\)/.test(installJs),
    'a progress message without text falls back to the active label',
);
check(
    libraryJs.includes('soonHintNoUrl'),
    'a "Bald im Launcher" card without a server URL still says what to do',
);
check(
    /GAMES_PATH\}\$\{encodeURIComponent\(slug\)\}/.test(libraryJs) &&
        /webLink\.href = url/.test(libraryJs),
    'the web fallback targets the game page /games/<slug>',
);

// ---- one source per empty-state sentence ----
// The UI's static screen copy lives in index.html (connect, install, ready and
// the empty states); strings.js carries only the copy the JS paints. The empty
// states used to exist in both — the HTML is the one source now, and a re-added
// string fails here. ("Gerade läuft kein Server." is excluded: the chat answer
// legitimately paints the same sentence from strings.js.)
const htmlText = html.replace(/\s+/g, ' ');
const emptyStateCopy = [
    'Noch kein Spiel freigegeben.',
    'Sobald die Orga ein Spiel für den Launcher freigibt, erscheint es hier. Du kannst auch selbst eines teilen.',
    'Noch nichts installiert.',
    'Installiere ein Spiel aus der Serverliste. Es bleibt auf diesem Gerät. Du hast ein eigenes Spiel? Teile es über LANoMAT.',
    'Sobald ein Server bereit ist, erscheint er hier mit einem Klick zum Beitreten.',
    'Noch keine Installation.',
    'Installiere das Spiel zuerst in der Bibliothek.',
];

for (const sentence of emptyStateCopy) {
    check(
        htmlText.includes(sentence),
        `empty-state copy in index.html: ${sentence.slice(0, 40)}…`,
    );
    check(
        !stringJs.includes(sentence),
        `empty-state copy only in index.html, not strings.js: ${sentence.slice(0, 40)}…`,
    );
}

// ---- README contract ----
check(readme.includes('check-ui.mjs'), 'README names the check script');
check(readme.includes('start_device'), 'README records the start_device mismatch');
check(readme.includes('connect_start'), 'README names connect_start');
check(connectJs.includes('connect_start'), 'connect.js starts the device flow');
check(connectJs.includes('serverUrl:'), 'connect.js sends the Tauri camelCase serverUrl argument');
check(!connectJs.includes('server_url:'), 'connect.js never sends a snake_case server_url to Tauri');
check(readme.includes('cancel_install'), 'README names cancel_install');
check(readme.includes('install-progress'), 'README documents the progress event');
check(readme.includes('deep-link'), 'README documents the deep-link event');
check(readme.includes('take_pending_deep_link'), 'README documents the pending deep link');
check(readme.includes('list_servers'), 'README documents the server list command');
check(readme.includes('Mit Handy scannen'), 'README documents the QR sign-in');
check(readme.includes('Beitreten'), 'README documents the server join action');
check(readme.includes('prefers-reduced-motion'), 'README documents reduced motion');
check(readme.includes('--destructive-text'), 'README documents the status text shades');
check(readme.includes('get_environment'), 'README documents the footer build command');
check(readme.includes('aria-current'), 'README documents the phase state');
check(readme.includes('missing LAN files'), 'README documents the install error mapping');
check(readme.includes('launcher_supported'), 'README documents the launcher capability flag');
check(readme.includes('Meine Bibliothek'), 'README documents the local library');
check(readme.includes('Dieses Gerät'), 'README documents the terminal name');
check(readme.includes('verify_install'), 'README documents the install check command');
check(readme.includes('Neu installieren'), 'README documents the reinstall action');
check(readme.includes('Installation reparieren'), 'README documents the ready repair action');
check(readme.includes('ohne etwas herunterzuladen'), 'README says the check downloads nothing');
check(readme.includes('leave_fullscreen'), 'README documents leaving the arcade fullscreen');
check(readme.includes('Nächstes Gerät'), 'README documents the arcade sign-out');
check(readme.includes('Gamepad verbunden'), 'README documents the gamepad indicator');
check(readme.includes('Spiel teilen'), 'README documents the share entry');
check(readme.includes('SHARE_PATH'), 'README documents the share path');
check(readme.includes('games/share'), 'README names the share page on the paired server');
check(readme.includes('Archiv laden'), 'README documents the generic install wording');
check(readme.includes('Bald im Launcher'), 'README documents the not-installable card');
check(readme.includes('portable'), 'README explains the portable archive recipes');

// ---- arcade terminals: QR payload, fullscreen, kiosk, keyboard + gamepad ----
// The QR payload contract is executable: the payload the shell builds for the
// device code flow must be encodable by the local encoder. This is what a phone
// scans after `connect_start`, so the printed code and the scanned link agree.
const verificationPayload = 'https://lan.raute1.org/mcp/connect?code=ABCD-1234';
let qrMatrix = null;

try {
    const encoder = await import(new URL('./js/qr.js', import.meta.url).href);

    qrMatrix = encoder.encode(verificationPayload);
} catch {
    qrMatrix = null;
}

check(Boolean(qrMatrix), 'the QR payload (verification link) encodes');
check(
    Boolean(qrMatrix) && qrMatrix.size >= 21 && qrMatrix.size % 4 === 1,
    'the QR matrix has a valid symbol size',
);
check(
    Boolean(qrMatrix) && qrMatrix.modules.length === qrMatrix.size,
    'the QR matrix is square',
);

// The Rust shell owns the flags and the payload; the UI must not invent them.
const srcRoot = join(root, '..', 'src');
const libRs = readFileSync(join(srcRoot, 'lib.rs'), 'utf8');
const envRs = readFileSync(join(srcRoot, 'env.rs'), 'utf8');
const mainRs = readFileSync(join(srcRoot, 'main.rs'), 'utf8');
const arcadeRs = readFileSync(join(srcRoot, 'arcade.rs'), 'utf8');
const capability = JSON.parse(
    readFileSync(join(root, '..', 'capabilities', 'default.json'), 'utf8'),
);
const permissions = capability.permissions || [];

check(/pub arcade: bool/.test(envRs), 'get_environment reports arcade to the UI');
check(/pub kiosk: bool/.test(envRs), 'get_environment reports the kiosk lock');
check(/ArcadeMode::detect\(\)/.test(mainRs), '--arcade / LANOMAT_ARCADE is parsed once in main');
check(/--arcade/.test(arcadeRs), 'the arcade module knows the --arcade flag');
check(/LANOMAT_ARCADE/.test(arcadeRs), 'the arcade module knows LANOMAT_ARCADE');
check(/--kiosk/.test(arcadeRs) && /LANOMAT_KIOSK/.test(arcadeRs), 'the kiosk lock has its own flag and variable');
check(/fn escapable/.test(arcadeRs), 'the mode decides who may leave the fullscreen');
check(/fn enter/.test(arcadeRs) && /is_fullscreen/.test(arcadeRs), 'fullscreen enters idempotently');
check(/leave_fullscreen/.test(libRs), 'the UI can leave the fullscreen');
check(/fn verification_payload/.test(libRs), 'the shell owns the QR payload');
check(/qr_payload/.test(libRs) && /qr_payload/.test(connectJs), 'the QR payload reaches the UI');
check(!permissions.some((permission) => permission.startsWith('core:window:')), 'Rust owns the window/fullscreen calls; the webview gets no window permission');

const mainSource = sources.get('js/main.js');
const navJs = sources.get('js/nav.js');

check(/classList\.toggle\('arcade'/.test(mainSource), 'the UI adds the arcade class from get_environment');
check(/S\.arcade\.logout/.test(mainSource), 'arcade wording: Abmelden / Nächstes Gerät');
check(/connect\.nextDevice/.test(mainSource), 'arcade shows the QR for the next device right away');
check(/leave_fullscreen/.test(mainSource), 'Escape asks the shell to leave fullscreen');
check(/state\.kiosk/.test(mainSource), 'kiosk keeps the fullscreen and says why');
check(/id="arcade-badge"/.test(html), 'arcade badge in the footer');
check(/html\.arcade/.test(css), 'arcade styles are scoped to the arcade class');
check(/html\.arcade button:focus/.test(css), 'arcade keeps a visible focus ring for gamepad focus');
check(/navigator\.getGamepads/.test(navJs), 'gamepad polled through the Gamepad API');
check(/pressed\(pad, 12\)/.test(navJs) && /pressed\(pad, 15\)/.test(navJs), 'd-pad moves the focus');
check(/DEADZONE/.test(navJs) && /pad\.axes/.test(navJs), 'the left stick moves the focus');
check(/ArrowUp/.test(navJs) && /'Escape'/.test(navJs), 'the keyboard keeps working');
check(/gamepadconnected/.test(navJs), 'hot-plugged controllers are picked up');
check(/set_gamepad/.test(mainSource), 'gamepad state reaches the terminal heartbeat');
check(/id="gamepad"/.test(html) && /id="gamepad-label"/.test(html), 'gamepad indicator in the shell');
check(/Gamepad verbunden/.test(copy) && /Gamepad getrennt/.test(copy), 'gamepad copy is German');
check(/Nächstes Gerät/.test(copy), 'arcade copy names the next device');

// ---- result ----
if (failures.length > 0) {
    console.error(`ui contract FAILED (${failures.length} of ${checks} checks)`);

    for (const failure of failures) {
        console.error(`  - ${failure}`);
    }

    process.exit(1);
}

console.log(`ui contract ok — ${checks} checks in ${root}`);
