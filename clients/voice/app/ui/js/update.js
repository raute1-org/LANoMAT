// The update notice. `get_update_status` is asked on connect, every 30 minutes, and after the
// window was away for more than ten minutes. An offered update is a dismissible strip under the
// header ("Später" hides it for that version until the app starts again); an incompatible client
// gets a stronger strip that cannot be dismissed. Errors and unknown states show nothing.
// Gerät und Konto shows the build, the server and a manual check.
import { S } from './strings.js';
import {
    CHECK_EVERY_MS,
    manualLine,
    noticeFor,
    shouldCheckAfterFocus,
} from './update-policy.js';
import { $, call, clear, el, plainMessage, state, toast } from './util.js';

// Versions the person hid with "Später". Memory only: the next app start shows them again.
const dismissed = new Set();
let timer = null;
let awaySince = null;
let shownKey = '';
let inFlight = null;

// Starts the periodic check (once) and checks now. Called after every connect.
export function start() {
    if (timer === null) {
        timer = setInterval(() => check(), CHECK_EVERY_MS);
    }

    return check();
}

// Signed out or logged out: no more checks, no notice.
export function stop() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }

    awaySince = null;
    state.update = null;
    render();
}

// The window lost or regained focus. A return after more than ten minutes away checks again.
export function focusChanged(focused) {
    if (timer === null) {
        return;
    }

    if (!focused) {
        awaySince = Date.now();

        return;
    }

    const away = awaySince;
    awaySince = null;

    if (shouldCheckAfterFocus(away, Date.now())) {
        check();
    }
}

// One status request at a time. The result of `call` is returned for the manual check.
export function check() {
    if (inFlight === null) {
        inFlight = call('get_update_status')
            .then((r) => {
                state.update = r.ok ? r.value : null;
                render();

                return r;
            })
            .finally(() => {
                inFlight = null;
            });
    }

    return inFlight;
}

// Shows or hides the strip for the current screen (called by the navigation on every switch).
export function paintUpdate() {
    render();
}

function render() {
    const box = $('update-banner');
    const signedIn = !$('tabs').hidden;
    const n = noticeFor(state.update, dismissed);
    const key = n ? `${n.kind}:${n.latest || n.min}` : '';

    if (key !== shownKey) {
        shownKey = key;
        clear(box);

        if (n) {
            build(box, n);
        }
    }

    box.hidden = !(signedIn && n);
    box.classList.toggle('required', Boolean(n && n.kind === 'incompatible'));
}

function build(box, n) {
    const current = state.env ? state.env.version : '';

    if (n.kind === 'incompatible') {
        box.append(
            el('p', {
                class: 'update-text',
                text: S.update.incompatible(n.min),
            }),
            el('div', { class: 'row wrap' }, downloadButton()),
        );

        return;
    }

    box.append(
        el('p', {
            class: 'update-text',
            text: S.update.available(n.latest, current),
        }),
        el(
            'div',
            { class: 'row wrap' },
            downloadButton(),
            el('button', {
                class: 'btn ghost',
                type: 'button',
                text: S.update.later,
                onclick: () => {
                    dismissed.add(n.latest);
                    render();
                    $('main').focus({ preventScroll: true });
                },
            }),
        ),
    );
}

function downloadButton() {
    return el('button', {
        class: 'btn primary',
        type: 'button',
        text: S.update.download,
        onclick: async () => {
            const r = await call('open_client_download');

            if (!r.ok) {
                toast(plainMessage(r.error), 'error');
            }
        },
    });
}

// Gerät und Konto: the build, the server it talks to and a manual check.
export function versionCard() {
    const env = state.env || {};
    const serverValue = el('span', {
        class: 'mono',
        text: S.update.serverUnknown,
    });
    const status = el('div', { class: 'small', role: 'status' });
    const button = el('button', {
        class: 'btn',
        type: 'button',
        text: S.update.check,
        onclick: async () => {
            button.disabled = true;
            button.textContent = S.update.checking;
            const r = await check();
            button.disabled = false;
            button.textContent = S.update.check;

            if (!r.ok) {
                status.textContent = plainMessage(r.error);

                return;
            }

            const line = manualLine(r.value, dismissed);

            if (line.kind === 'still') {
                status.textContent = S.update.stillAvailable(line.latest);
            } else if (line.kind === 'upToDate') {
                status.textContent = S.update.upToDate;
            } else {
                status.textContent = '';
            }
        },
    });

    call('get_config').then((r) => {
        if (r.ok && r.value.server_version) {
            serverValue.textContent = r.value.server_version;
        }
    });

    return el(
        'section',
        { class: 'card stack-sm', 'aria-label': S.update.title },
        el('h2', { text: S.update.title }),
        el('strong', {
            text: S.update.thisApp(env.version || '', env.commit || ''),
        }),
        el(
            'div',
            { class: 'row between' },
            el('span', { class: 'muted', text: S.update.server }),
            serverValue,
        ),
        el('div', { class: 'row wrap' }, button),
        status,
    );
}
