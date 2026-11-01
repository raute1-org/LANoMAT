// Verbinden: pair this device with the account (device code flow). Unchanged in behaviour.
import { showScreen } from './nav.js';
import { S } from './strings.js';
import { button, call, el, say, state } from './util.js';

let onConnected = () => {};
let token = 0;
let countdownTimer = null;
const ui = {};
let verifyUrlComplete = '';

export function init(section, { connected }) {
    onConnected = connected;
    ui.error = el('div', { class: 'error', role: 'alert', hidden: true });
    ui.errorTitle = el('strong');
    ui.errorText = el('span');
    ui.error.append(
        el('div', { class: 'stack-sm' }, ui.errorTitle, ui.errorText),
    );
    ui.url = el('input', {
        id: 'server-url',
        type: 'url',
        inputmode: 'url',
        autocomplete: 'off',
        spellcheck: 'false',
        placeholder: S.connect.placeholder,
    });
    ui.submit = el('button', {
        class: 'btn primary block',
        type: 'submit',
        id: 'connect-submit',
        text: S.connect.submit,
    });
    ui.form = el(
        'form',
        { class: 'card stack-sm', novalidate: true, onsubmit: submit },
        el('label', { for: 'server-url', text: S.connect.address }),
        ui.url,
        el('div', { class: 'small muted', text: S.connect.addressHint }),
        ui.submit,
    );
    ui.code = el('div', { class: 'code', 'aria-label': S.connect.yourCode });
    ui.verify = el('div', { class: 'url' });
    ui.device = el('span', { class: 'mono' });
    ui.countdown = el('span', { class: 'countdown' });
    ui.wait = el(
        'div',
        { class: 'card stack-sm', hidden: true },
        el('h2', { text: S.connect.confirmTitle }),
        el('p', { class: 'small muted', text: S.connect.confirmText }),
        ui.code,
        el(
            'div',
            { class: 'row wrap' },
            button(S.connect.copyCode, {
                onclick: (e) =>
                    copy(
                        ui.code.textContent,
                        e.currentTarget,
                        S.connect.codeCopied,
                    ),
            }),
            button(S.connect.copyLink, {
                onclick: (e) =>
                    copy(
                        verifyUrlComplete,
                        e.currentTarget,
                        S.connect.linkCopied,
                    ),
            }),
            button(S.connect.openBrowser, {
                kind: 'primary',
                onclick: async () => {
                    const r = await call('open_url', {
                        url: verifyUrlComplete,
                    });

                    if (!r.ok) {
                        say(r.error.message);
                    }
                },
            }),
        ),
        ui.verify,
        el(
            'div',
            { class: 'small muted' },
            el('span', { text: S.connect.deviceName }),
            ' ',
            ui.device,
        ),
        el(
            'div',
            { class: 'row small', role: 'status' },
            el('span', { class: 'dot warn pulse', 'aria-hidden': 'true' }),
            el(
                'span',
                {},
                el('span', { text: S.connect.waiting }),
                ' ',
                ui.countdown,
                '.',
            ),
        ),
        button(S.cancel, {
            onclick: async () => {
                reset();
                clearInterval(countdownTimer);
                await call('connect_cancel');
            },
        }),
    );
    section.append(
        el(
            'div',
            { class: 'stack-sm' },
            el('h1', { text: S.connect.title }),
            el('p', { class: 'muted', text: S.connect.intro }),
        ),
        ui.error,
        ui.form,
        ui.wait,
    );
}

export function open({ title, text, url } = {}) {
    reset();
    ui.error.hidden = true;

    if (url) {
        ui.url.value = url;
    }

    if (text) {
        showError(title || S.connect.notConnected, text);
    }

    showScreen('connect');
    ui.url.focus({ preventScroll: true });
}

function showError(title, text) {
    ui.errorTitle.textContent = title;
    ui.errorText.textContent = text;
    ui.error.hidden = false;
}

function reset() {
    token++;
    ui.wait.hidden = true;
    ui.form.hidden = false;
    ui.submit.disabled = false;
    ui.submit.textContent = S.connect.submit;
}

async function submit(ev) {
    ev.preventDefault();
    ui.error.hidden = true;
    const url = ui.url.value.trim();

    if (!url) {
        showError(S.connect.missingTitle, S.connect.missingText);
        ui.url.focus();

        return;
    }

    ui.submit.disabled = true;
    ui.submit.textContent = S.connect.connecting;
    const r = await call('connect_start', { serverUrl: url });

    if (!r.ok) {
        reset();
        showError(
            r.error.kind === 'network'
                ? S.connect.unreachable
                : S.connect.cannot,
            r.error.message,
        );

        return;
    }

    const s = r.value;
    verifyUrlComplete = s.verification_url_complete || s.verification_url;
    ui.code.textContent = s.user_code;
    ui.verify.textContent = s.verification_url;
    ui.device.textContent = s.device_name;
    ui.form.hidden = true;
    ui.wait.hidden = false;
    say(S.connect.codeSaid(s.user_code));
    countdown(s.expires_in);
    poll(++token, s.next_in_ms);
}

function countdown(seconds) {
    clearInterval(countdownTimer);
    const end = Date.now() + seconds * 1000;
    const tick = () => {
        const left = Math.max(0, Math.round((end - Date.now()) / 1000));
        ui.countdown.textContent = `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;

        if (left === 0) {
            clearInterval(countdownTimer);
        }
    };
    tick();
    countdownTimer = setInterval(tick, 1000);
}

async function poll(mine, waitMs) {
    await new Promise((r) => setTimeout(r, waitMs));

    if (mine !== token) {
        return;
    }

    const r = await call('connect_poll');

    if (mine !== token) {
        return;
    }

    if (!r.ok) {
        reset();
        showError(S.connect.failed, r.error.message);

        return;
    }

    const p = r.value;

    if (p.status === 'pending') {
        return poll(mine, p.next_in_ms);
    }

    clearInterval(countdownTimer);

    if (p.status === 'connected') {
        reset();
        state.status = {
            ...state.status,
            state: 'connected',
            user_name: p.user_name,
        };
        say(S.connect.connected);
        await onConnected();

        return;
    }

    reset();
    showError(S.connect.kinds[p.kind] || S.connect.failed, p.message);
    ui.submit.textContent = S.retry;
    ui.submit.focus();
}

async function copy(text, btn, done) {
    let ok = false;

    try {
        await navigator.clipboard.writeText(text);
        ok = true;
    } catch {
        const ta = el('textarea', { 'aria-hidden': 'true' });
        ta.value = text;
        document.body.append(ta);
        ta.select();

        try {
            ok = document.execCommand('copy');
        } catch {
            ok = false;
        }

        ta.remove();
    }

    const old = btn.textContent;
    btn.textContent = ok ? done : S.copyFailed;
    say(btn.textContent);
    setTimeout(() => (btn.textContent = old), 1800);
}
