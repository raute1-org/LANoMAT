// Shared helpers: DOM building, calling the Rust side, formatting and the connectivity
// signal. Every dynamic string goes in through textContent, never innerHTML.
const tauri = window.__TAURI__;

export const hasTauri = Boolean(tauri && tauri.core && tauri.event);

export const invoke = (cmd, args) => tauri.core.invoke(cmd, args ?? {});
export const listen = (name, cb) =>
    hasTauri ? tauri.event.listen(name, cb) : Promise.resolve(() => {});

export const $ = (id) => document.getElementById(id);

export function el(tag, props = {}, ...kids) {
    const node = document.createElement(tag);

    for (const [key, value] of Object.entries(props)) {
        if (value === undefined || value === null || value === false) {
            continue;
        }

        if (key === 'class') {
            node.className = value;
        } else if (key === 'text') {
            node.textContent = value;
        } else if (key.startsWith('on')) {
            node.addEventListener(key.slice(2), value);
        } else if (key === 'value' || key === 'checked' || key === 'disabled') {
            node[key] = value;
        } else {
            node.setAttribute(key, value === true ? '' : String(value));
        }
    }

    node.append(...kids.flat().filter((kid) => kid !== null && kid !== undefined));

    return node;
}

export const setText = (id, text) => {
    const node = typeof id === 'string' ? $(id) : id;

    if (node) {
        node.textContent = text;
    }
};

export const setHidden = (id, hidden) => {
    const node = typeof id === 'string' ? $(id) : id;

    if (node) {
        node.hidden = Boolean(hidden);
    }
};

// ---- connectivity ----

let online = true;
const connectivity = new Set();

export const isOnline = () => online;

export function onConnectivity(cb) {
    connectivity.add(cb);
    cb(online);

    return () => connectivity.delete(cb);
}

function setOnline(value) {
    if (online === value) {
        return;
    }

    online = value;

    for (const cb of connectivity) {
        try {
            cb(online);
        } catch (error) {
            console.error(error);
        }
    }
}

// ---- calling commands ----

function rawMessage(error) {
    if (typeof error === 'string') {
        return error;
    }

    if (error && typeof error.message === 'string') {
        return error.message;
    }

    return String(error ?? '');
}

// The Rust side returns plain strings. Classify only what we can prove from them, so the
// UI never claims "offline" for a validation error and never invents a server message.
export function errorKind(raw) {
    const text = String(raw ?? '');

    if (
        /network error|failed to connect|connection (refused|reset|closed)|timed out|timeout|unreachable|dns|offline|error sending request/i.test(
            text,
        )
    ) {
        return 'network';
    }

    if (
        /not signed in|server answered HTTP (401|403)|nicht verbunden|keine laufende anmeldung/i.test(
            text,
        )
    ) {
        return 'auth';
    }

    return 'other';
}

export async function call(cmd, args) {
    if (!hasTauri) {
        return {
            ok: false,
            error: {
                raw: 'missing tauri',
                message: 'missing tauri',
                kind: 'other',
            },
        };
    }

    try {
        const value = await invoke(cmd, args ?? {});
        setOnline(true);

        return { ok: true, value };
    } catch (error) {
        const raw = rawMessage(error);
        const kind = errorKind(raw);

        if (kind === 'network') {
            setOnline(false);
        }

        return { ok: false, error: { raw, message: raw, kind } };
    }
}

export function isAuthError(error) {
    return Boolean(error) && error.kind === 'auth';
}

// The shell reports HTTP failures as "server answered HTTP 500". The status code is a fact we
// may repeat; the surrounding sentence is German (see S.httpError).
export function httpStatus(raw) {
    const match = String(raw ?? '').match(/server answered HTTP (\d{3})/i);

    return match ? match[1] : null;
}

// ---- formatting: machine data stays mono in the markup, these are the numbers ----

const decimal = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1 });

export function bytes(value) {
    const number = Number(value);

    if (!Number.isFinite(number) || number <= 0) {
        return '0 B';
    }

    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    let size = number;
    let index = 0;

    while (size >= 1024 && index < units.length - 1) {
        size /= 1024;
        index += 1;
    }

    return `${decimal.format(size)} ${units[index]}`;
}

export function speed(value) {
    const number = Number(value);

    return Number.isFinite(number) && number > 0
        ? `${bytes(number)}/s`
        : '—';
}

export function eta(seconds) {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    const total = Math.max(0, Math.round(Number(seconds)));

    if (!Number.isFinite(total)) {
        return '—';
    }

    if (total < 60) {
        return `${total} s`;
    }

    const minutes = Math.floor(total / 60);

    if (minutes < 60) {
        return `${minutes} min ${total % 60} s`;
    }

    const hours = Math.floor(minutes / 60);

    return `${hours} h ${minutes % 60} min`;
}

export function percent(value) {
    const number = Number(value);

    if (!Number.isFinite(number)) {
        return null;
    }

    return Math.max(0, Math.min(100, number));
}

export function percentLabel(value) {
    const valueNumber = percent(value);

    return valueNumber === null ? '—' : `${Math.round(valueNumber)} %`;
}

export function countdown(ms) {
    const total = Math.max(0, Math.ceil(Number(ms) / 1000));
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

export function basename(path) {
    const text = String(path ?? '').trim();
    const withoutQuery = text.split(/[?#]/)[0];
    const parts = withoutQuery.split(/[/\\]/).filter(Boolean);

    return parts.length ? parts[parts.length - 1] : text;
}

export function hostOf(url) {
    try {
        const parsed = new URL(String(url));

        return parsed.host || String(url);
    } catch {
        return String(url || '');
    }
}

export function safeHttpUrl(raw) {
    try {
        const parsed = new URL(String(raw));

        return parsed.protocol === 'https:' || parsed.protocol === 'http:'
            ? parsed.toString()
            : null;
    } catch {
        return null;
    }
}

// The paired server's http(s) base without a trailing slash. Everything that links
// back into the web app (server browser, game page) derives its URL from it, so a
// LAN-only install never points at a public host. `null` while no base is known.
export function serverBase(raw) {
    const trimmed = String(raw || '')
        .trim()
        .replace(/\/+$/, '');

    return /^https?:\/\/[^/\s]+(\/[^\s]*)?$/i.test(trimmed) ? trimmed : null;
}

// ---- screen reader voice ----

export function announce(text) {
    const live = $('live');

    if (!live) {
        return;
    }

    live.textContent = '';

    window.setTimeout(() => {
        live.textContent = text;
    }, 30);
}

let toastTimer = null;

// A calm visible hint for things that happen outside the current screen, e.g. a deep link
// that needs pairing first. The same text also goes to the screen-reader live region.
export function toast(text, tone = 'info') {
    const node = $('toast');

    if (!node) {
        return;
    }

    node.textContent = text;
    node.dataset.tone = tone;
    node.hidden = false;
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(
        () => {
            node.hidden = true;
        },
        tone === 'error' ? 6000 : 4000,
    );
}

// Focus a node on the next frame: after the DOM change, and with preventScroll so the
// screen does not jump. Used for the heading of a new stage and for the follow-up action
// of a failed state; disabled or hidden nodes simply keep the current focus instead of
// dropping it to <body>.
export function focusNode(node) {
    if (!node || typeof node.focus !== 'function') {
        return;
    }

    window.requestAnimationFrame(() => {
        node.focus({ preventScroll: true });
    });
}

export function focusHeading(node) {
    focusNode(node);
}
