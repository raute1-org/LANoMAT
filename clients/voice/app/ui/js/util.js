// Shared helpers: DOM building, calling the Rust side, formatting, the tiny store and event bus.
// Every dynamic string goes in through textContent, never innerHTML.
import { S } from './strings.js';

const tauri = window.__TAURI__;

export const invoke = (cmd, args) => tauri.core.invoke(cmd, args);
export const listen = (name, cb) => tauri.event.listen(name, cb);

export const $ = (id) => document.getElementById(id);

export function el(tag, props = {}, ...kids) {
    const node = document.createElement(tag);

    for (const [k, v] of Object.entries(props)) {
        if (v === undefined || v === null || v === false) {
            continue;
        }

        if (k === 'class') {
            node.className = v;
        } else if (k === 'text') {
            node.textContent = v;
        } else if (k.startsWith('on')) {
            node.addEventListener(k.slice(2), v);
        } else if (k === 'value' || k === 'checked' || k === 'disabled') {
            node[k] = v;
        } else {
            node.setAttribute(k, v === true ? '' : String(v));
        }
    }

    node.append(...kids.flat().filter((k) => k !== null && k !== undefined));

    return node;
}

export const clear = (node) => node.replaceChildren();

// Appends children, skipping null, undefined and false (conditional parts).
export const add = (parent, ...kids) => {
    parent.append(
        ...kids
            .flat()
            .filter((k) => k !== null && k !== undefined && k !== false),
    );

    return parent;
};

// ---- store and event bus ----

export const state = {
    status: null, // get_status
    config: { modules: {}, realtime: false },
    event: null, // {slug, name, ...} or null
    view: null, // RoomView
    rooms: [],
    settings: null,
    env: null,
    tab: 'jetzt',
    unread: 0,
    quiet: false,
    chatStatus: 'connecting',
    online: true,
    update: null, // get_update_status, see update.js
};

const handlers = new Map();

export function on(name, cb) {
    if (!handlers.has(name)) {
        handlers.set(name, new Set());
    }

    handlers.get(name).add(cb);
}

export function emit(name, payload) {
    for (const cb of handlers.get(name) || []) {
        try {
            cb(payload);
        } catch (e) {
            console.error(name, e);
        }
    }
}

// ---- calling commands ----

export function normalizeError(e) {
    if (e && typeof e === 'object' && 'message' in e) {
        return {
            kind: 'local',
            retryable: false,
            ...e,
            message: String(e.message || S.errors.generic),
        };
    }

    const text = String(e || '');

    return {
        kind: /unknown command|not found/i.test(text) ? 'unknown' : 'local',
        retryable: false,
        message: S.errors.generic,
        raw: text,
    };
}

export async function call(cmd, args) {
    try {
        const value = await invoke(cmd, args);

        if (!state.online) {
            state.online = true;
            emit('online');
        }

        return { ok: true, value };
    } catch (e) {
        const error = normalizeError(e);

        if (error.kind === 'auth') {
            emit('auth-lost', error);
        } else if (error.kind === 'network') {
            state.online = false;
            emit('offline', error);
        }

        return { ok: false, error };
    }
}

export const isUnknownCommand = (error) =>
    Boolean(error) && error.kind === 'unknown';

// A message for a person: the server's German text, never JSON. Rate limits get a calm note.
export function plainMessage(error) {
    if (error.kind === 'rate_limited') {
        const s = error.retry_after_secs;

        return s ? S.errors.rateLimitedIn(s) : S.errors.rateLimited;
    }

    if (error.kind === 'network') {
        return S.errors.network;
    }

    return error.message || S.errors.generic;
}

// ---- messages to the person ----

let toastTimer = null;

export function say(text) {
    const live = $('live');
    live.textContent = '';
    setTimeout(() => (live.textContent = text), 30);
}

export function toast(text, tone = 'info') {
    const t = $('toast');
    t.textContent = text;
    t.dataset.tone = tone;
    t.hidden = false;
    say(text);
    clearTimeout(toastTimer);
    toastTimer = setTimeout(
        () => (t.hidden = true),
        tone === 'error' ? 7000 : 3500,
    );
}

// ---- building blocks shared by the screens ----

export const dot = (tone = '', pulse = false) =>
    el('span', {
        class: `dot ${tone}${pulse ? ' pulse' : ''}`,
        'aria-hidden': 'true',
    });

export const chip = (text, tone = '') =>
    el('span', { class: `chip ${tone}`.trim(), text });

export function button(label, { kind = '', onclick, disabled, title } = {}) {
    return el('button', {
        class: `btn ${kind}`.trim(),
        type: 'button',
        text: label,
        onclick,
        disabled,
        title,
    });
}

export function skeleton(n = 3) {
    return el(
        'div',
        { class: 'stack-sm', 'aria-busy': 'true', 'aria-label': S.loading },
        ...Array.from({ length: n }, () => el('div', { class: 'skeleton' })),
    );
}

export function emptyState(text, hint, action) {
    return el(
        'div',
        { class: 'empty', role: 'status' },
        el('div', { class: 'strong', text }),
        hint ? el('div', { class: 'small', text: hint }) : null,
        action || null,
    );
}

// A refresh failed but there is data from before: keep it, say so calmly, offer a retry.
export function staleHint(retry) {
    return el(
        'div',
        { class: 'hint warn row between wrap', role: 'status' },
        el('span', { text: S.stale }),
        button(S.retry, { onclick: retry }),
    );
}

export function errorState(error, retry, title = S.errors.loadFailed) {
    return el(
        'div',
        { class: 'error', role: 'alert' },
        el(
            'div',
            { class: 'stack-sm' },
            el('strong', { text: title }),
            el('span', { text: plainMessage(error) }),
            retry ? button(S.retry, { onclick: retry }) : null,
        ),
    );
}

// ---- formatting ----

// Money is integer cents everywhere; the formatting is done on integers only.
export function euro(cents) {
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(Math.trunc(cents));
    const whole = String(Math.floor(abs / 100)).replace(
        /\B(?=(\d{3})+(?!\d))/g,
        '.',
    );

    return `${sign}${whole},${String(abs % 100).padStart(2, '0')} €`;
}

export function bytes(n) {
    if (!Number.isFinite(n) || n < 0) {
        return '';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let v = n;
    let i = 0;

    while (v >= 1024 && i < units.length - 1) {
        v /= 1024;
        i++;
    }

    const text = i === 0 || v >= 100 ? Math.round(v) : v.toFixed(1);

    return `${String(text).replace('.', ',')} ${units[i]}`;
}

export function percent(p) {
    return `${String(Math.round(p * 10) / 10).replace('.', ',')}\u00a0%`;
}

export function speed(bps) {
    return bps > 0 ? `${bytes(bps)}/s` : '';
}

const pad = (n) => String(n).padStart(2, '0');

export function clock(iso) {
    const d = new Date(iso);

    return Number.isNaN(d.getTime())
        ? ''
        : `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function dayTime(iso) {
    const d = new Date(iso);

    if (Number.isNaN(d.getTime())) {
        return '';
    }

    const same = d.toDateString() === new Date().toDateString();

    return same
        ? `${S.today} ${clock(iso)}`
        : `${S.weekdays[d.getDay()]} ${clock(iso)}`;
}

// "in 4 Min", "seit 3 Min", "in 2 Std"
export function relative(iso, now = Date.now()) {
    const t = new Date(iso).getTime();

    if (Number.isNaN(t)) {
        return '';
    }

    const diff = Math.round((t - now) / 60000);
    const abs = Math.abs(diff);

    if (abs === 0) {
        return S.now;
    }

    const unit = abs >= 120 ? `${Math.round(abs / 60)} Std` : `${abs} Min`;

    return diff > 0 ? `in ${unit}` : `seit ${unit}`;
}

export function mentions(body, name) {
    if (!name) {
        return false;
    }

    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    return new RegExp(`@${escaped}(?!\\w)`, 'i').test(body);
}
