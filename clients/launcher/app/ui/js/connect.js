// Verbinden: pair this PC with the LANoMAT account through the device code flow.
// The Rust side starts and polls the login; this module only renders the states
// pending, slow_down, expired, denied and error and never sleeps the core.
import { state } from './state.js';
import { S } from './strings.js';
import { render as renderQr } from './qr.js';
import {
    $,
    announce,
    call,
    countdown,
    httpStatus,
    safeHttpUrl,
    setHidden,
    setText,
} from './util.js';

let pollTimer = null;
let ticker = null;
let intervalSeconds = 5;
let expiresAt = 0;
let running = false;
let generation = 0;
let busy = false;
let verifyUrl = '';
let lastUrl = S.defaultUrl;
let onConnected = () => {};

export function init({ connected }) {
    onConnected = connected;

    $('connect-form').addEventListener('submit', (event) => {
        event.preventDefault();
        start();
    });
    $('connect-open').addEventListener('click', openVerification);
    $('connect-link').addEventListener('click', (event) => {
        event.preventDefault();
        openVerification();
    });
    $('connect-cancel').addEventListener('click', () => cancel());
    $('connect-retry').addEventListener('click', start);
    $('connect-change').addEventListener('click', changeAddress);
}

export function show({ url, error, kind } = {}) {
    stopPoll();
    running = false;
    busy = false;
    verifyUrl = '';

    if (url) {
        lastUrl = url;
        $('connect-url').value = url;
    }

    clearFormError();
    setText('connect-form-error', '');
    setText('connect-code', '');
    setText('connect-countdown', '');
    setText('connect-slow-secs', '');
    setHidden('connect-qr-wrap', true);
    setHidden('connect-qr-fallback', true);
    $('connect-qr').replaceChildren();
    setBusy(false);
    showForm();

    if (error) {
        showError(kind || 'other', error);
    } else {
        $('connect-url').focus();
    }
}

export function retry() {
    if (running) {
        return;
    }

    if (!$('connect-error').hidden) {
        start();
    } else if (!$('connect-form').hidden) {
        start();
    }
}

/**
 * Arcade: this terminal already knows its server, so the next guest does not
 * have to press "Verbinden" — the device flow starts right away and the QR code
 * is on screen. Same path as the form submit, so nothing about the poll changes.
 */
export function nextDevice() {
    if (running || busy || $('connect-form').hidden) {
        return;
    }

    start();
}

/**
 * Escape / gamepad B on the connect screen: close the device card (and stop the
 * polling) or leave a failed attempt before anything else. Returns true when the
 * step was consumed, so `main.js` does not also go back a screen.
 */
export function back() {
    if (!$('connect-device').hidden || !$('connect-error').hidden) {
        cancel({ quiet: true });

        return true;
    }

    return false;
}

function normalizeUrl(raw) {
    const text = String(raw ?? '').trim();

    if (!text) {
        return null;
    }

    const candidate = /^[a-z][a-z0-9+.-]*:\/\//i.test(text)
        ? text
        : `https://${text}`;

    try {
        const parsed = new URL(candidate);

        if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
            return null;
        }

        if (!parsed.hostname) {
            return null;
        }

        return parsed.toString().replace(/\/$/, '');
    } catch {
        return null;
    }
}

async function start() {
    if (busy) {
        return;
    }

    const raw = $('connect-url').value.trim();

    if (!raw) {
        formError(S.connect.emptyUrl);
        $('connect-url').focus();

        return;
    }

    const url = normalizeUrl(raw);

    if (!url) {
        formError(S.connect.invalidUrlText);
        $('connect-url').focus();

        return;
    }

    lastUrl = url;
    $('connect-url').value = url;
    clearFormError();
    stopPoll();
    running = false;
    setBusy(true);

    const result = await call('connect_start', { serverUrl: url });

    if (!result.ok) {
        setBusy(false);

        if (/invalid server URL/i.test(result.error.raw)) {
            formError(S.connect.invalidUrlText);
        } else if (result.error.kind === 'network') {
            formError(S.connect.networkText);
        } else {
            formError(result.error.raw || S.connect.failedText);
        }

        return;
    }

    beginDevice(result.value || {});
}

function beginDevice(device) {
    const serverInterval = Number(device.interval);
    const expiresIn = Number(device.expires_in);

    intervalSeconds = Number.isFinite(serverInterval)
        ? Math.max(1, serverInterval)
        : 5;
    expiresAt =
        Date.now() +
        (Number.isFinite(expiresIn) ? Math.max(intervalSeconds, expiresIn) : 300) *
            1000;

    const complete = safeHttpUrl(device.verification_uri_complete);

    verifyUrl = complete || safeHttpUrl(device.verification_uri) || '';

    const link = $('connect-link');

    setText('connect-code', device.user_code || '—');
    setText('connect-wait', S.connect.waiting);
    setText('connect-slow-secs', intervalSeconds);
    setHidden('connect-slow', true);
    setHidden('connect-open-error', true);
    setHidden('connect-form', true);
    setHidden('connect-error', true);
    setHidden('connect-device', false);

    if (verifyUrl) {
        link.textContent = verifyUrl;
        link.href = verifyUrl;
        link.removeAttribute('aria-disabled');
    } else {
        link.textContent = device.verification_uri || '';
        link.removeAttribute('href');
        link.setAttribute('aria-disabled', 'true');
    }

    paintQr(safeHttpUrl(device.qr_payload) || complete || verifyUrl);

    setBusy(false);

    running = true;
    generation += 1;

    paintCountdown();
    startTicker();
    schedule(intervalSeconds);

    $('connect-open').focus();
    announce(
        `${S.connect.deviceTitle}. ${device.user_code || ''}. ${S.connect.waiting}.`,
    );
}

// Draw the sign-in QR from the payload Rust chose (`qr_payload`), so the link a
// phone scans is exactly the link the server approved. Without a drawable code
// the printed code and the link below stay the fallback.
function paintQr(url) {
    const container = $('connect-qr');
    const wrap = $('connect-qr-wrap');
    const fallback = $('connect-qr-fallback');
    const drawn = Boolean(url) && renderQr(container, url);

    setHidden(wrap, !drawn);

    if (!url) {
        setHidden(fallback, true);

        return;
    }

    setText('connect-qr-fallback', drawn ? S.qr.fallback : S.qr.failed);
    setHidden(fallback, false);
}

function schedule(seconds) {
    stopPollTimer();

    if (!running) {
        return;
    }

    pollTimer = window.setTimeout(poll, Math.max(1, seconds) * 1000);
}

async function poll() {
    if (!running) {
        return;
    }

    const current = generation;

    if (Date.now() >= expiresAt) {
        fail('expired');

        return;
    }

    const result = await call('connect_poll');

    if (!running || current !== generation) {
        return;
    }

    if (!result.ok) {
        if (result.error.kind === 'network') {
            setText('connect-wait', S.header.offline);
            paintCountdown();
            schedule(intervalSeconds);

            return;
        }

        fail('other', result.error.raw);

        return;
    }

    setText('connect-wait', S.connect.waiting);

    const view = result.value || {};

    if (view.state === 'pending') {
        paintCountdown();
        schedule(intervalSeconds);

        return;
    }

    if (view.state === 'slow_down') {
        const next = Number(view.interval);
        intervalSeconds = Number.isFinite(next) ? Math.max(1, next) : 5;
        setText('connect-slow-secs', intervalSeconds);
        setHidden('connect-slow', false);
        announce(S.connect.slow(intervalSeconds));
        schedule(intervalSeconds);

        return;
    }

    if (view.state === 'connected') {
        complete(view);

        return;
    }

    if (view.state === 'failed') {
        fail(classify(view.message), view.message);

        return;
    }

    fail('other', S.connect.unknownState(view.state));
}

function classify(message) {
    const text = String(message ?? '');

    if (/expired/i.test(text)) {
        return 'expired';
    }

    if (/denied|abgelehnt/i.test(text)) {
        return 'denied';
    }

    if (/network|unreachable|timed out/i.test(text)) {
        return 'network';
    }

    return 'other';
}

function complete(view) {
    stopPoll();
    running = false;
    setHidden('connect-device', true);
    setHidden('connect-error', true);

    state.url = lastUrl;
    state.status = {
        paired: true,
        url: lastUrl,
        user: view.user || null,
        server: view.server || { name: '', url: lastUrl, version: '' },
        installs: [],
    };

    announce(S.connect.connected);
    onConnected(view);
}

function fail(kind, raw) {
    stopPoll();
    running = false;
    setHidden('connect-device', true);
    setHidden('connect-error', false);
    setHidden('connect-open-error', true);

    let title = S.connect.failedTitle;
    let text = raw || S.connect.failedText;
    const status = httpStatus(raw);

    if (kind === 'expired') {
        title = S.connect.expiredTitle;
        text = S.connect.expiredText;
    } else if (kind === 'denied') {
        title = S.connect.deniedTitle;
        text = S.connect.deniedText;
    } else if (kind === 'network') {
        text = S.connect.networkText;
    } else if (/network error/i.test(String(raw))) {
        text = S.connect.networkText;
    } else if (/invalid server URL/i.test(String(raw))) {
        title = S.connect.invalidUrlTitle;
        text = S.connect.invalidUrlText;
    } else if (status) {
        text = S.httpError(status);
    }

    setText('connect-error-title', title);
    setText('connect-error-text', text);
    announce(`${title} ${text}`);
    $('connect-retry').focus();
}

function cancel({ quiet = false } = {}) {
    if (!running && $('connect-error').hidden) {
        return;
    }

    stopPoll();
    running = false;
    verifyUrl = '';
    showForm();

    if (!quiet) {
        announce(S.connect.cancelled);
    }

    $('connect-url').focus();
}

function changeAddress() {
    cancel({ quiet: true });
}

function showForm() {
    setHidden('connect-form', false);
    setHidden('connect-device', true);
    setHidden('connect-error', true);
    setHidden('connect-open-error', true);
    setHidden('connect-slow', true);
    setBusy(false);
}

async function openVerification() {
    if (!verifyUrl) {
        setHidden('connect-open-error', false);
        setText('connect-open-error', S.ready.browserError);

        return;
    }

    const result = await call('open_url', { url: verifyUrl });

    if (!result.ok) {
        setHidden('connect-open-error', false);
        setText('connect-open-error', S.ready.browserError);
        announce(S.ready.browserError);
    }
}

function showError(kind, raw) {
    setHidden('connect-form', true);
    fail(kind, raw);
}

function formError(text) {
    setText('connect-form-error', text);
    setHidden('connect-form-error', false);
}

function clearFormError() {
    setText('connect-form-error', '');
    setHidden('connect-form-error', true);
}

function setBusy(value) {
    busy = value;
    $('connect-url').disabled = value;
    $('connect-submit').disabled = value;
    $('connect-submit').textContent = value
        ? S.connect.submitting
        : S.connect.submit;
    $('connect-form').setAttribute('aria-busy', String(value));
}

function paintCountdown() {
    setText('connect-countdown', countdown(expiresAt - Date.now()));
}

function startTicker() {
    clearInterval(ticker);
    ticker = window.setInterval(() => {
        if (!running) {
            return;
        }

        const left = expiresAt - Date.now();

        setText('connect-countdown', countdown(left));

        if (left <= 0) {
            fail('expired');
        }
    }, 1000);
}

function stopPollTimer() {
    if (pollTimer !== null) {
        window.clearTimeout(pollTimer);
        pollTimer = null;
    }
}

function stopPoll() {
    stopPollTimer();
    clearInterval(ticker);
    ticker = null;
}
