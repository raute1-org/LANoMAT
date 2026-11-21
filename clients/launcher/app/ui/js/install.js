// Installation: the Rust engine emits `install-progress`; the command's promise settles
// the run. This module renders plan/download/extract/patch/verify/ready, the current file,
// bytes, speed, ETA, and the cancel/error/cancelled states. It never fakes progress.
import { showScreen, state } from './state.js';
import { S } from './strings.js';
import {
    $,
    announce,
    bytes,
    call,
    errorKind,
    eta,
    focusNode,
    httpStatus,
    listen,
    percent,
    percentLabel,
    setHidden,
    setText,
    speed,
} from './util.js';

const PHASES = [
    'planning',
    'downloading',
    'extracting',
    'patching',
    'verifying',
    'ready',
];

let phaseNodes = new Map();
let unlisten = null;
let starting = false;
let cancelling = false;
let settled = false;
let readyAnnounced = false;
let lastPhase = 'planning';
let currentRecipe = null;
let onReady = () => {};
let onLibrary = () => {};

export function init({ ready, library }) {
    onReady = ready;
    onLibrary = library;

    for (const node of document.querySelectorAll('#install-phases .phase')) {
        phaseNodes.set(node.dataset.phase, node);
    }

    $('install-cancel').addEventListener('click', cancel);
    $('install-retry').addEventListener('click', retry);
    $('install-retry-cancelled').addEventListener('click', retry);
    $('install-back').addEventListener('click', onLibrary);
    $('install-back-error').addEventListener('click', onLibrary);
    $('install-back-cancelled').addEventListener('click', onLibrary);
}

export async function begin(recipe) {
    if (starting || !recipe) {
        return;
    }

    starting = true;
    currentRecipe = recipe;
    settled = false;
    readyAnnounced = false;
    lastPhase = 'planning';

    state.install = {
        slug: recipe.slug,
        title: recipe.title || recipe.slug,
        version: recipe.version || null,
    };

    resetUi();
    setText('install-game-title', recipe.title || recipe.slug);

    const version = $('install-version');

    if (recipe.version) {
        version.textContent = `v${recipe.version}`;
        version.hidden = false;
    } else {
        version.hidden = true;
    }

    showScreen('install');
    announce(S.install.started(recipe.title || recipe.slug));

    let result;

    // Both the listener and the install call can fail. The finally block is what keeps
    // the screen usable: `starting` must never stay true (cancel/retry would be dead)
    // and no listener may outlive the run it belongs to.
    try {
        unlisten = await listen('install-progress', onProgress);
        result = await call('install', { slug: recipe.slug });
    } catch (error) {
        const raw = errorText(error);

        result = {
            ok: false,
            error: { raw, message: raw, kind: errorKind(raw) },
        };
    } finally {
        starting = false;
        cleanupListener();
    }

    if (result.ok) {
        finishReady(result.value || {});

        return;
    }

    if (settled) {
        return;
    }

    const raw = result.error?.raw || '';

    if (/cancelled/i.test(raw)) {
        showCancelled();
    } else {
        showError(raw);
    }
}

export function retry() {
    if (!currentRecipe || starting) {
        return;
    }

    begin(currentRecipe);
}

export const isRunning = () => starting;

function onProgress(event) {
    const progress = event?.payload || {};
    const eventSlug = String(progress.slug || '');

    if (eventSlug && currentRecipe && eventSlug !== currentRecipe.slug) {
        return;
    }

    const phase = String(progress.phase || '');

    if (phase === 'error') {
        const message = String(progress.message || '');

        if (/cancel/i.test(message)) {
            showCancelled();
        } else {
            showError(message);
        }

        return;
    }

    if (phase === 'cancelled') {
        showCancelled();

        return;
    }

    if (!PHASES.includes(phase)) {
        return;
    }

    lastPhase = phase;
    setPhase(phase);

    const message = progress.message || S.phases[phase] || S.install.message;
    const total = Number(progress.bytes_total) || 0;
    const done = Number(progress.bytes_done) || 0;

    setText('install-message', message);
    setText('install-file', progress.file ? String(progress.file) : '—');
    setText(
        'install-bytes',
        total > 0 ? `${bytes(done)} / ${bytes(total)}` : done > 0 ? bytes(done) : '—',
    );
    setText(
        'install-speed',
        Number(progress.bytes_per_second) > 0
            ? speed(progress.bytes_per_second)
            : '—',
    );
    setText(
        'install-eta',
        progress.eta_secs === null || progress.eta_secs === undefined
            ? '—'
            : eta(progress.eta_secs),
    );

    const known =
        progress.percent === null || progress.percent === undefined
            ? total > 0
                ? percent((done / total) * 100)
                : null
            : percent(progress.percent);

    paintProgress(known, message);

    if (phase === 'ready') {
        setText('install-message', progress.message || S.install.ready);
        paintProgress(100, progress.message || S.install.ready);
        $('install-cancel').disabled = true;
        announceReady();
    }
}

function setPhase(phase, failed = false) {
    const index = PHASES.indexOf(phase);

    if (index < 0) {
        return;
    }

    for (const [name, node] of phaseNodes) {
        const position = PHASES.indexOf(name);
        const state =
            failed && position === index
                ? 'failed'
                : position < index
                  ? 'done'
                  : position === index
                    ? 'current'
                    : 'pending';

        node.classList.remove('done', 'current', 'failed');

        if (state !== 'pending') {
            node.classList.add(state);
        }

        node.setAttribute(
            'aria-label',
            `${S.phases[name]}, ${S.phaseState[state]}`,
        );

        if (state === 'current') {
            node.setAttribute('aria-current', 'step');
        } else {
            node.removeAttribute('aria-current');
        }
    }
}

function paintProgress(value, message) {
    const bar = $('install-progress');
    const fill = $('install-progress-fill');
    const label = message || S.install.message;

    if (value === null) {
        bar.classList.add('indeterminate');
        bar.removeAttribute('aria-valuenow');
        bar.setAttribute('aria-valuetext', label);
        fill.style.width = '';
        setText('install-percent', '—');

        return;
    }

    bar.classList.remove('indeterminate');
    bar.setAttribute('aria-valuenow', String(Math.round(value)));
    bar.setAttribute('aria-valuetext', `${Math.round(value)} Prozent`);
    fill.style.width = `${value}%`;
    setText('install-percent', percentLabel(value));
}

async function cancel() {
    if (settled || cancelling) {
        return;
    }

    const button = $('install-cancel');

    if (button.disabled) {
        return;
    }

    // aria-disabled instead of disabled: the button keeps focus while the command runs,
    // so focus never falls to <body>. The `cancelling` flag absorbs a second activation.
    cancelling = true;
    button.setAttribute('aria-disabled', 'true');
    button.textContent = S.install.cancelling;
    setText('install-hint', S.install.cancellingHint);

    const result = await call('cancel_install');

    cancelling = false;

    if (!result.ok && !settled) {
        button.removeAttribute('aria-disabled');
        button.disabled = false;
        button.textContent = S.install.cancel;
        setText('install-hint', result.error.raw || S.install.hint);
        announce(result.error.raw || S.install.hint);
        focusAction(button);
    }
}

function finishReady(record) {
    if (settled) {
        return;
    }

    settled = true;
    cleanupListener();
    setPhase('ready');
    setHidden('install-error', true);
    setHidden('install-cancelled', true);
    setHidden('install-back', false);
    $('install-cancel').disabled = true;
    setText('install-message', S.install.ready);
    paintProgress(100, S.install.ready);

    if (state.status && record && record.slug) {
        const records = Array.isArray(state.status.installs)
            ? state.status.installs
            : [];

        state.status.installs = [
            ...records.filter((entry) => entry.slug !== record.slug),
            record,
        ];
    }

    announceReady();
    onReady(record, currentRecipe);
}

function showError(raw) {
    if (settled) {
        return;
    }

    settled = true;
    cleanupListener();
    setPhase(lastPhase, true);
    setHidden('install-error', false);
    setHidden('install-cancelled', true);
    setHidden('install-back', false);
    setText('install-message', S.install.stoppedAt(S.phases[lastPhase]));
    freezeProgress();

    const hint = $('install-hint');

    hint.hidden = true;

    const cancelButton = $('install-cancel');

    cancelButton.removeAttribute('aria-disabled');
    cancelButton.disabled = true;
    cancelButton.textContent = S.install.cancel;

    const { text, known } = installText(raw);

    setText('install-error-text', text);

    const rawNode = $('install-error-raw');
    const showRaw =
        Boolean(raw) && !known && !/network error/i.test(raw);

    if (showRaw) {
        rawNode.textContent = raw;
        rawNode.hidden = false;
    } else {
        rawNode.hidden = true;
    }

    announce(`${S.install.errorTitle} ${text}`);
    focusAction($('install-retry'));
}

function showCancelled() {
    if (settled) {
        return;
    }

    settled = true;
    cleanupListener();
    setHidden('install-cancelled', false);
    setHidden('install-error', true);
    setHidden('install-back', false);
    setText('install-message', S.install.stoppedAt(S.phases[lastPhase]));
    freezeProgress();

    const hint = $('install-hint');

    hint.hidden = true;

    const cancelButton = $('install-cancel');

    cancelButton.removeAttribute('aria-disabled');
    cancelButton.disabled = true;
    cancelButton.textContent = S.install.cancel;
    announce(S.install.cancelledTitle);
    focusAction($('install-retry-cancelled'));
}

function freezeProgress() {
    const bar = $('install-progress');

    bar.classList.remove('indeterminate');

    if (!bar.hasAttribute('aria-valuenow')) {
        $('install-progress-fill').style.width = '0%';
    }
}

function resetUi() {
    setPhase('planning');
    setText('install-message', S.install.message);
    setText('install-file', '—');
    setText('install-bytes', '—');
    setText('install-speed', '—');
    setText('install-eta', '—');
    paintProgress(null, S.install.message);

    const cancelButton = $('install-cancel');

    cancelling = false;
    cancelButton.removeAttribute('aria-disabled');
    cancelButton.disabled = false;
    cancelButton.textContent = S.install.cancel;

    $('install-hint').hidden = false;
    setText('install-hint', S.install.hint);

    setHidden('install-error', true);
    setHidden('install-error-raw', true);
    setHidden('install-cancelled', true);
    setHidden('install-back', true);
}

// The install promise and the event listener can both fail before the first progress
// event. Surface whatever the shell said; the caller maps it like any other install error.
function errorText(error) {
    if (typeof error === 'string') {
        return error;
    }

    if (error && typeof error.message === 'string') {
        return error.message;
    }

    return String(error ?? '');
}

// Keep focus inside the install screen: the failed/cancelled state moves it to the retry
// action, and a focus call for a screen that is no longer shown (logout during an install)
// leaves the current screen alone.
function focusAction(node) {
    if (state.stage !== 'install' || !node) {
        return;
    }

    focusNode(node);
}

// One announcement for the ready state: the progress event and the settled promise both
// report it, but the screen reader hears it once.
function announceReady() {
    if (readyAnnounced) {
        return;
    }

    readyAnnounced = true;
    announce(S.install.ready);
}

function cleanupListener() {
    if (unlisten) {
        try {
            unlisten();
        } catch (error) {
            console.error(error);
        }

        unlisten = null;
    }
}

function installText(raw) {
    const text = String(raw ?? '').trim();

    if (!text) {
        return { text: S.install.errorText, known: false };
    }

    let match = text.match(/^missing LAN files:\s*(.+)$/i);

    if (match) {
        return { text: S.install.missingFiles(match[1]), known: true };
    }

    if (/no base file|portable archive/i.test(text)) {
        return { text: S.install.noBaseFile, known: true };
    }

    match = text.match(/^the install is incomplete:\s*(.+)$/i);

    if (match) {
        return { text: S.install.incomplete(match[1]), known: true };
    }

    match = text.match(/^launch failed:\s*(.+)$/i);

    if (match) {
        return { text: S.install.launchFailed(match[1]), known: true };
    }

    if (/network error/i.test(text)) {
        return { text: S.connect.networkText, known: true };
    }

    if (/not signed in|server answered HTTP (401|403)/i.test(text)) {
        return { text: S.library.authText, known: true };
    }

    const status = httpStatus(text);

    if (status) {
        return { text: S.httpError(status), known: true };
    }

    return { text: S.install.errorText, known: false };
}
