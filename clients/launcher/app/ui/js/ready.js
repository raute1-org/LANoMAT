// Startklar: one installed game, its folder, the launch button and the server browser.
// No claim that the game is still running — only the executable the shell started.
import * as install from './install.js';
import { showScreen, state } from './state.js';
import { SERVERS_PATH, S } from './strings.js';
import {
    $,
    announce,
    call,
    focusNode,
    isAuthError,
    setHidden,
    setText,
} from './util.js';

let current = null;
let launching = false;
let onLibrary = () => {};

export function init({ library }) {
    onLibrary = library;

    $('ready-launch').addEventListener('click', launchGame);
    $('ready-repair').addEventListener('click', repair);
    $('ready-library').addEventListener('click', onLibrary);
    $('ready-empty-library').addEventListener('click', onLibrary);
    $('ready-browser').addEventListener('click', (event) => {
        event.preventDefault();
        openBrowser();
    });
}

export function show(
    slug,
    { launch = false, record = null, title: known = null, join = null } = {},
) {
    const recipe = (state.games || []).find((game) => game.slug === slug);
    const installRecord = record || latestInstall(slug);
    // A terminal whose server is offline has no catalogue: the local library
    // passes the installed title in, so Startklar never falls back to the slug.
    const title =
        known ||
        recipe?.title ||
        (state.install?.slug === slug ? state.install.title : null) ||
        installRecord?.slug ||
        slug;

    current = { slug, title, record: installRecord, join };
    state.ready = current;

    syncBrowserLink();

    setText('ready-game', title);
    setHidden('ready-error', true);
    setHidden('ready-launched', true);
    setText('ready-error', '');
    setText('ready-launched', '');

    const card = $('ready-card');
    const empty = $('ready-empty');

    if (installRecord) {
        card.hidden = false;
        empty.hidden = true;
        setText('ready-path', installRecord.target_dir || '—');

        const versionRow = $('ready-version-row');

        if (installRecord.version) {
            setText('ready-version', installRecord.version);
            versionRow.hidden = false;
        } else {
            setText('ready-version', '');
            versionRow.hidden = true;
        }

        $('ready-launch').disabled = false;
        $('ready-launch').textContent = S.ready.launch;
    } else {
        card.hidden = true;
        empty.hidden = false;
    }

    paintRepair();
    showScreen('ready');

    if (launch && installRecord) {
        launchGame();
    }
}

export function retry() {
    if (state.stage === 'ready' && current?.record) {
        launchGame();
    }
}

// Re-run the one install command for this record: an interrupted download resumes,
// extraction is idempotent, and the local ledger is replaced for this slug only.
function repair() {
    if (!current || !current.record) {
        return;
    }

    const recipe = (state.games || []).find(
        (game) => game && game.slug === current.slug,
    );

    // The command only needs the slug; the title keeps the install screen's
    // heading honest when the catalogue is not loaded. A repair keeps the
    // server the player came for, so the ready screen launches onto it again.
    install.begin(recipe || { slug: current.slug, title: current.title }, {
        join: current.join,
    });
}

// A quiet repair action is always available on a recorded game. After a launch
// that failed on a moved or incomplete install it becomes the clear next step.
function paintRepair({ promote = false } = {}) {
    const button = $('ready-repair');
    const launchButton = $('ready-launch');
    const recorded = Boolean(current?.record);

    button.hidden = !recorded;
    button.textContent = promote ? S.ready.repair : S.ready.repairShort;
    button.title = S.ready.repairHint;
    button.classList.toggle('primary', recorded && promote);
    launchButton.classList.toggle('primary', !(recorded && promote));
}

function latestInstall(slug) {
    const records = Array.isArray(state.status?.installs)
        ? state.status.installs
        : [];
    const matches = records.filter((record) => record && record.slug === slug);

    return matches.length ? matches[matches.length - 1] : null;
}

async function launchGame() {
    if (!current || !current.record || launching) {
        return;
    }

    launching = true;

    const button = $('ready-launch');

    button.disabled = true;
    button.textContent = S.ready.launching;
    setHidden('ready-error', true);
    setHidden('ready-launched', true);

    const join = current.join || null;
    const address = typeof join?.address === 'string' ? join.address : null;
    const joinUrl = typeof join?.url === 'string' ? join.url : null;

    // UT2004 takes the server as its first argument. Other recipes launch the
    // way their recipe says and then open their own join handler.
    const result = await call('launch', { slug: current.slug, join: address });

    launching = false;
    button.disabled = false;
    button.textContent = S.ready.launch;

    if (!result.ok) {
        const text = launchText(result.error);
        const stalled = repairable(result.error);

        showError(text);
        paintRepair({ promote: stalled });
        announce(text);

        // The button was disabled while the command ran, so focus fell to
        // <body>: a repairable failure points at the repair, every other one at
        // the launch action the user came from.
        if (state.stage === 'ready') {
            focusNode(stalled ? $('ready-repair') : button);
        }

        return;
    }

    let joined = true;

    if (!address && joinUrl !== null) {
        const opened = await call('open_url', { url: joinUrl });

        if (!opened.ok) {
            joined = false;
            showError(S.start.joinError);
            announce(S.start.joinError);
        }
    }

    // The button was disabled while the command ran, so focus fell to <body>: bring it
    // back to the launch action, which is where the user was.
    if (state.stage === 'ready') {
        focusNode(button);
    }

    if (joined) {
        setText('ready-launched', S.ready.launched(result.value || ''));
        setHidden('ready-launched', false);
        announce(S.ready.launched(result.value || ''));
    }
}

// The install command fixes all of these: the recorded folder was moved or
// deleted, the System/ payload is incomplete, or the ledger lost the record.
function repairable(error) {
    const raw = String(error?.raw || '');

    return (
        /installationsordner fehlt/i.test(raw) ||
        /^launch failed:.*(fehlt|missing)/i.test(raw) ||
        /the install is incomplete/i.test(raw) ||
        /noch nicht installiert/i.test(raw)
    );
}

async function openBrowser() {
    const url = browserUrl();

    if (!url) {
        syncBrowserLink();

        return;
    }

    const result = await call('open_url', { url });

    if (!result.ok) {
        showError(S.ready.browserError);
        announce(S.ready.browserError);

        return;
    }

    announce(S.ready.browser);
}

// The server browser belongs to the paired server: a LAN-only installation has no
// public lan.raute1.org, so the URL is derived from the connection and the link is
// hidden while no http(s) base is known.
function browserUrl() {
    const base = serverBase();

    return base ? `${base}${SERVERS_PATH}` : null;
}

function serverBase() {
    const raw = String(state.url || state.status?.server?.url || '').trim();
    const trimmed = raw.replace(/\/+$/, '');

    return /^https?:\/\/[^/\s]+(\/[^\s]*)?$/i.test(trimmed) ? trimmed : null;
}

function syncBrowserLink() {
    const link = $('ready-browser');
    const url = browserUrl();

    link.hidden = !url;

    if (url) {
        link.href = url;
    } else {
        link.removeAttribute('href');
    }
}

function showError(text) {
    setText('ready-error', text);
    setHidden('ready-error', false);
}

function launchText(error) {
    if (!error) {
        return S.ready.launchError;
    }

    if (error.kind === 'network') {
        return S.connect.networkText;
    }

    if (isAuthError(error)) {
        return S.library.authText;
    }

    const raw = String(error.raw || '');
    const failed = raw.match(/^launch failed:\s*(.+)$/i);

    if (failed) {
        return `${S.ready.launchError} ${failed[1]}`;
    }

    if (/not installed|noch nicht installiert/i.test(raw)) {
        return raw;
    }

    return raw || S.ready.launchError;
}
