// LANoMAT Launcher, main window. Plain ES modules, no build step. Boots, owns the tiny
// stage machine and bridges the Rust commands/events into the four screens.
import * as connect from './connect.js';
import * as install from './install.js';
import * as chat from './chat.js';
import * as library from './library.js';
import * as nav from './nav.js';
import * as ready from './ready.js';
import { state, showScreen } from './state.js';
import { S } from './strings.js';
import {
    $,
    announce,
    call,
    hasTauri,
    hostOf,
    listen,
    onConnectivity,
    safeHttpUrl,
    setHidden,
    setText,
    toast,
} from './util.js';

let confirmingLogout = false;
let logoutTimer = null;
let deepLinkBusy = false;
let updatePage = null;
let announcedUpdate = null;

init();

function init() {
    connect.init({ connected: afterConnect });
    chat.init({ signedOut: reconnect, status: applyStatus });
    library.init({ status: applyStatus, signedOut: reconnect });
    install.init({ ready: afterInstall, library: goLibrary });
    ready.init({ library: goLibrary });

    // Keyboard and gamepad: the arcade cabinet has a controller and no mouse.
    // `goBack` owns the "fullscreen first, screen second" order, `reportGamepad`
    // keeps the orga monitor's gamepad flag in sync with this window.
    nav.init({ back: goBack, gamepad: reportGamepad });

    $('chat-skip').addEventListener('click', goLibrary);
    $('library-chat').addEventListener('click', goChat);

    listen('deep-link', (event) => {
        routeDeepLink(event?.payload).catch(() => {});
    }).catch(() => {});

    wireHeader();
    wireConnectivity();
    wireFooter();
    boot();
}

async function boot() {
    showScreen('boot');

    if (!hasTauri) {
        connect.show({ url: S.defaultUrl, error: S.boot.noTauri });
        showScreen('connect');

        return;
    }

    // The arcade flag and the machine name come from the local environment, so
    // the `arcade` class is on <html> before the first real screen paints.
    await loadBuildInfo();

    refreshFooter();

    const result = await call('get_status');

    if (!result.ok) {
        connect.show({
            url: S.defaultUrl,
            error: result.error.raw,
            kind: result.error.kind,
        });
        showScreen('connect');

        return;
    }

    const status = result.value || {};

    applyStatus(status);

    if (status.paired) {
        showScreen('chat');
        chat.show();
        routePendingDeepLink().catch(() => {});

        return;
    }

    connect.show({ url: status.url || S.defaultUrl });
    showScreen('connect');

    // A cabinet is a self-service terminal: the QR for the next guest is the
    // first thing on screen, no extra "Verbinden" press.
    if (state.arcade) {
        connect.nextDevice();
    }
}

function afterConnect() {
    applyStatus(state.status);
    chat.clear();
    showScreen('chat');
    chat.show();
    refreshFooter();
}

function afterInstall(record, recipe) {
    // Logout during an install clears the pairing and moves to the connect screen. The
    // install still finishes, but there is no paired server to show Startklar for, so the
    // connect screen stays instead of being hidden behind a ready screen the user cannot
    // act on.
    if (state.stage === 'connect' || !state.status?.paired) {
        return;
    }

    ready.show(record?.slug || recipe?.slug || state.install?.slug, { record });
}

function reconnect() {
    connect.show({ url: state.url || S.defaultUrl });
    showScreen('connect');
}

function goLibrary() {
    showScreen('library');
    library.load();
}

function goChat() {
    showScreen('chat');
    chat.show();
}

async function routePendingDeepLink() {
    const result = await call('take_pending_deep_link');

    if (!result.ok || !result.value) {
        return;
    }

    await routeDeepLink(result.value);
}

// lanomat://install/<slug> and lanomat://launch/<slug> from the shell. A link that
// arrives unpaired is only announced: the shell keeps it pending and replays it after
// pairing, so the UI stores nothing and cannot act on a stale token.
async function routeDeepLink(payload) {
    const action = String(payload?.action || '').toLowerCase();
    const slug = String(payload?.slug || '');

    if (!slug || (action !== 'install' && action !== 'launch')) {
        return;
    }

    // A deep link never interrupts a running install, whichever link it is: the install
    // screen keeps its progress and its cancel, and the link gets the busy toast.
    if (install.isRunning()) {
        toast(S.deepLink.busy);

        return;
    }

    if (deepLinkBusy) {
        return;
    }

    deepLinkBusy = true;

    try {
        if (!(await ensurePaired())) {
            toast(S.deepLink.needConnection);

            return;
        }

        if (action === 'install') {
            await deepLinkInstall(slug);
        } else {
            await deepLinkLaunch(slug);
        }
    } finally {
        deepLinkBusy = false;
    }
}

async function ensurePaired() {
    if (state.status?.paired) {
        return true;
    }

    const result = await call('get_status');

    if (!result.ok) {
        toast(
            result.error.kind === 'network'
                ? S.connect.networkText
                : S.library.errorText,
            'error',
        );

        return false;
    }

    applyStatus(result.value);

    return Boolean(result.value?.paired);
}

async function deepLinkInstall(slug) {
    if (install.isRunning()) {
        toast(S.deepLink.busy);

        return;
    }

    const result = await call('list_games');

    if (!result.ok) {
        toast(
            result.error.kind === 'network'
                ? S.connect.networkText
                : S.library.errorText,
            'error',
        );

        return;
    }

    state.games = Array.isArray(result.value) ? result.value : [];

    const recipe = state.games.find((game) => game && game.slug === slug);

    if (!recipe) {
        toast(S.deepLink.unknown(slug));
        goLibrary();

        return;
    }

    // The catalogue lists recipes this build cannot install yet. A link for one
    // of them must say so instead of starting an install that cannot finish.
    if (recipe.launcher_supported === false || recipe.installable === false) {
        toast(S.deepLink.notSupported(slug));
        goLibrary();

        return;
    }

    await install.begin(recipe);
}

async function deepLinkLaunch(slug) {
    // A launch link must not hide a running install: its progress, file and cancel stay
    // on the install screen, and the link is answered with a calm busy toast.
    if (install.isRunning()) {
        toast(S.deepLink.busy);

        return;
    }

    if (!Array.isArray(state.games) || state.games.length === 0) {
        const games = await call('list_games');

        if (games.ok) {
            state.games = Array.isArray(games.value) ? games.value : state.games;
        }
    }

    if (!hasInstallRecord(slug)) {
        const result = await call('get_status');

        if (result.ok) {
            applyStatus(result.value);
        } else if (result.error.kind === 'network') {
            toast(S.connect.networkText, 'error');

            return;
        }
    }

    if (!hasInstallRecord(slug)) {
        toast(S.deepLink.notInstalled);
        goLibrary();

        return;
    }

    ready.show(slug, { launch: true });
}

function hasInstallRecord(slug) {
    const records = Array.isArray(state.status?.installs)
        ? state.status.installs
        : [];

    return records.some((record) => record && record.slug === slug);
}

function applyStatus(status) {
    if (!status) {
        return;
    }

    state.status = status;

    if (status.url) {
        state.url = status.url;
    }

    const paired = Boolean(status.paired);

    setHidden('who', !paired);
    setHidden('logout', !paired || !hasTauri);

    if (!paired) {
        setText('who-text', '');
        paintServerName('');

        return;
    }

    const server = serverName(status);

    setText('who-text', S.header.connectedTo(server, status.user?.name || ''));
    paintServerName(server);
}

// The library headline names the server the games come from. It is machine data, so only
// the name goes into the mono span; without a paired server the whole line stays hidden.
function paintServerName(server) {
    const name = String(server || '').trim();

    setText('library-server-name', name);
    setHidden('library-server', !name);
}

function serverName(status) {
    return status.server?.name || hostOf(status.url || state.url);
}

function wireHeader() {
    const logoutButton = $('logout');

    logoutButton.addEventListener('click', onLogout);
    $('offline-retry').addEventListener('click', retryCurrent);

    // tooltip for sighted users, description for the screen reader; both from strings.js
    paintLogout();
}

function wireConnectivity() {
    let wasOffline = false;

    onConnectivity((online) => {
        setHidden('offline-banner', online);
        paintWhoDot(online);

        if (!online) {
            // The banner has role="status" and announces itself when it appears.
            wasOffline = true;
        } else if (wasOffline) {
            wasOffline = false;
            announce(S.header.online);
        }
    });
}

// The who dot carries the same signal as the banner: ok while the last command reached
// the server, down while the connection is known to be gone.
function paintWhoDot(online) {
    const dot = $('who-dot');

    if (!dot) {
        return;
    }

    dot.classList.toggle('ok', online);
    dot.classList.toggle('down', !online);
}

// ---- footer: the running build and the server's published launcher build ----

function wireFooter() {
    $('build-update')?.addEventListener('click', openUpdatePage);
}

// Decoration for support reports. It never delays boot and never raises a screen
// error: when the server cannot answer, the footer stays as it was.
function refreshFooter() {
    loadBuildInfo().catch(() => {});
    checkForUpdate().catch(() => {});
}

async function loadBuildInfo() {
    const result = await call('get_environment');

    if (!result.ok || !result.value) {
        return;
    }

    const version = result.value.version || '?';
    const commit =
        result.value.commit && result.value.commit !== 'unknown'
            ? String(result.value.commit).slice(0, 12)
            : S.footer.unknownCommit;

    setText('build-info', S.footer.build(version, commit));
    applyEnvironment(result.value);
}

// The shell decides what this window is: a desk launcher or an arcade cabinet
// (`--arcade`/`LANOMAT_ARCADE=1`, locked with `--kiosk`). One class on <html>
// switches the CSS (fullscreen-sized type, no settings clutter); the button copy
// follows, and Escape/gamepad B may leave the fullscreen only when the mode is
// not kiosk — Rust enforces that in `leave_fullscreen`.
function applyEnvironment(view) {
    state.arcade = Boolean(view.arcade);
    state.kiosk = Boolean(view.kiosk);

    document.documentElement.classList.toggle('arcade', state.arcade);
    setHidden('arcade-badge', !state.arcade);
    setText('arcade-badge', S.arcade.badge);
    paintLogout();
    paintDevice(view.host);
}

// "Abmelden / Nächstes Gerät" is the arcade wording: the same window serves many
// guests, and signing out only drops this machine's pairing — the installed
// games stay, the next guest scans the QR.
function logoutLabel() {
    return state.arcade ? S.arcade.logout : S.header.logout;
}

function logoutConfirmLabel() {
    return state.arcade ? S.arcade.logoutConfirm : S.header.logoutConfirm;
}

function logoutHint() {
    return state.arcade ? S.arcade.logoutHint : S.header.logoutHint;
}

function paintLogout() {
    const button = $('logout');

    if (!button) {
        return;
    }

    const hint = logoutHint();

    button.title = hint;
    setText('logout-hint', hint);

    if (!confirmingLogout) {
        button.textContent = logoutLabel();
    }
}

// The header names this terminal (`lan-pc`, …). A LAN party has one launcher
// window per table; each pairs on its own and keeps its own installs, so the
// machine name — not the event, not the server — is what tells them apart.
function paintDevice(host) {
    const name = String(host || '').trim() || S.header.deviceUnknown;
    const device = $('device');

    setText('device-label', S.header.deviceLabel);
    setText('device-name', name);
    setText('device-hint', S.header.deviceHint);
    setHidden(device, false);

    if (device) {
        device.title = S.header.deviceHint;
    }
}

async function checkForUpdate() {
    const result = await call('check_launcher_update');
    const view = result.ok ? result.value : null;
    const link = $('build-update');

    if (!view || !view.available || !view.latest) {
        updatePage = null;
        setHidden(link, true);

        return;
    }

    updatePage = safeHttpUrl(view.page);

    if (!updatePage) {
        setHidden(link, true);

        return;
    }

    const text = S.footer.update(view.latest);

    link.href = updatePage;
    setText(link, text);
    setHidden(link, false);

    if (announcedUpdate !== view.latest) {
        announcedUpdate = view.latest;
        announce(text);
    }
}

async function openUpdatePage(event) {
    event.preventDefault();

    if (!updatePage) {
        return;
    }

    const result = await call('open_url', { url: updatePage });

    if (!result.ok) {
        toast(S.footer.openError, 'error');
    }
}

function onLogout() {
    const button = $('logout');

    if (!confirmingLogout) {
        confirmingLogout = true;
        button.textContent = logoutConfirmLabel();
        button.classList.remove('ghost');
        button.classList.add('danger');
        announce(logoutConfirmLabel());
        logoutTimer = window.setTimeout(resetLogout, 5000);

        return;
    }

    logout();
}

async function logout() {
    window.clearTimeout(logoutTimer);
    logoutTimer = null;

    const result = await call('logout');

    if (!result.ok) {
        resetLogout();
        announce(result.error.raw || S.connect.failedText);

        return;
    }

    const url = state.url || S.defaultUrl;

    resetLogout();
    state.status = null;
    state.games = [];
    state.servers = null;
    state.install = null;
    state.ready = null;
    chat.clear();
    setHidden('who', true);
    setHidden('logout', true);
    paintServerName('');
    connect.show({ url });
    showScreen('connect');

    // The arcade terminal already knows its server: a fresh code and QR for the
    // next guest appear without anyone pressing "Verbinden".
    if (state.arcade) {
        connect.nextDevice();
    }

    announce(state.arcade ? S.arcade.loggedOut : S.header.loggedOut);
}

function resetLogout() {
    window.clearTimeout(logoutTimer);
    logoutTimer = null;
    confirmingLogout = false;

    const button = $('logout');

    button.textContent = logoutLabel();
    button.classList.remove('danger');
    button.classList.add('ghost');
}

// Escape / gamepad B. In an arcade cabinet the fullscreen is the first step
// back; once it is gone the same key goes back a screen. A kiosk cabinet keeps
// its fullscreen (Rust answers false) and says why. On the desk this is the
// plain back action, and the connect screen closes its device card first.
async function goBack() {
    if (state.arcade) {
        const result = await call('leave_fullscreen');

        if (result.ok && result.value === true) {
            announce(S.arcade.fullscreenLeft);

            return true;
        }

        if (state.kiosk) {
            toast(S.arcade.kioskHint);

            return true;
        }
    }

    if (state.stage === 'connect') {
        return connect.back();
    }

    if (state.stage === 'install' || state.stage === 'ready' || state.stage === 'chat') {
        goLibrary();

        return true;
    }

    return false;
}

// The footer dot is local; the heartbeat is what the orga monitor shows. A
// failed send never surfaces — the next heartbeat repeats the state anyway.
function reportGamepad(connected) {
    if (!hasTauri) {
        return;
    }

    call('set_gamepad', { connected });
}

function retryCurrent() {
    if (state.stage === 'connect') {
        connect.retry();
    } else if (state.stage === 'chat') {
        chat.retry();
    } else if (state.stage === 'library') {
        library.load();
    } else if (state.stage === 'install') {
        install.retry();
    } else if (state.stage === 'ready') {
        ready.retry();
    } else {
        boot();
    }
}
