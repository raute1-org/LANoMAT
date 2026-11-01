// Building blocks used by more than one screen: game server actions with the install hint,
// copying, the "no event" state, and where a notification category leads.
import { S } from './strings.js';
import {
    button,
    call,
    chip,
    dot,
    el,
    emptyState,
    plainMessage,
    state,
    toast,
} from './util.js';

export const eventSlug = () => (state.event ? state.event.slug : null);

export function noEventState() {
    return emptyState(S.noEvent.title, S.noEvent.hint);
}

export async function copyText(text, done = S.copied) {
    const r = await call('copy_text', { text });

    if (r.ok) {
        toast(done);
    } else {
        toast(plainMessage(r.error), 'error');
    }

    return r.ok;
}

// Beitreten: the Steam handler when there is one, otherwise the address on the clipboard.
export async function joinServer(server) {
    if (!server.joinUrl) {
        return;
    }

    const r = await call('open_join_url', { url: server.joinUrl });

    if (r.ok) {
        toast(S.servers.opening(server.title || S.servers.thisServer));

        return;
    }

    if (r.error.kind === 'local' && server.copyText) {
        const copied = await call('copy_text', { text: server.copyText });

        toast(
            copied.ok
                ? S.servers.noSteamCopied(server.copyText)
                : plainMessage(r.error),
            copied.ok ? 'info' : 'error',
        );

        return;
    }

    toast(plainMessage(r.error), 'error');
}

export function serverStatus(server) {
    const map = {
        ready: ['ok', S.servers.status.ready],
        provisioning: ['warn', S.servers.status.provisioning],
        not_responding: ['warn', S.servers.status.not_responding],
        offline: ['down', S.servers.status.offline],
    };

    return map[server.status] || ['', server.status || ''];
}

export function installHint(server) {
    const h = server.installHint;

    if (!h || (!h.steamUrl && !h.shareUrl && !h.versionNote)) {
        return null;
    }

    return el(
        'details',
        { class: 'hint-details' },
        el('summary', { text: S.servers.howToGet }),
        el(
            'div',
            { class: 'stack-sm hint-body' },
            el('p', { class: 'small', text: S.servers.howToGetText }),
            h.versionNote
                ? el('p', { class: 'small mono', text: h.versionNote })
                : null,
            el(
                'div',
                { class: 'row wrap' },
                h.steamUrl
                    ? button(S.servers.copySteamLink, {
                          onclick: () =>
                              copyText(h.steamUrl, S.servers.linkCopied),
                      })
                    : null,
                h.shareUrl
                    ? button(S.servers.copyShareLink, {
                          onclick: () =>
                              copyText(h.shareUrl, S.servers.linkCopied),
                      })
                    : null,
            ),
        ),
    );
}

// Join and copy buttons: only what the server's answer allows (offline has neither).
export function serverActions(
    server,
    { primary = true, joinLabel = S.servers.join } = {},
) {
    const row = el('div', { class: 'row wrap' });

    if (server.joinUrl) {
        row.append(
            button(joinLabel, {
                kind: primary ? 'primary' : '',
                onclick: () => joinServer(server),
            }),
        );
    }

    if (server.copyText) {
        row.append(
            button(S.servers.copyAddress, {
                onclick: () =>
                    copyText(server.copyText, S.servers.addressCopied),
            }),
        );
    }

    return row.childElementCount ? row : null;
}

export function serverLine(server) {
    const [tone, text] = serverStatus(server);
    const bits = [];

    if (server.players !== null && server.players !== undefined) {
        bits.push(
            server.maxPlayers
                ? S.servers.players(server.players, server.maxPlayers)
                : String(server.players),
        );
    }

    if (server.map) {
        bits.push(server.map);
    }

    return el(
        'div',
        { class: 'row wrap server-line' },
        dot(tone, tone === 'warn'),
        el('span', { class: 'strong', text }),
        ...bits.map((b) => chip(b)),
    );
}

// Where a notification leads: tab and card hint, by its category.
export function notificationTarget(category) {
    const map = {
        match: ['spielen', 'match'],
        checkin: ['spielen', 'checkin'],
        catering: ['essen', 'orders'],
        direct: ['sprache', 'chat'],
        vote: ['mehr', 'vote'],
        schedule: ['jetzt', 'schedule'],
        teams: ['spielen', 'tournaments'],
        friends: ['mehr', 'friends'],
    };

    return map[category] || ['mehr', 'notifications'];
}

// ---- people: the monogram avatar and the in-page confirmation ----

// The avatar the server sends is a remote picture, and the window's content security policy
// loads nothing from the internet (and a CDN would learn who looks at whom): a monogram stands
// in, the same for everybody.
export function initials(name) {
    const words = String(name || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (!words.length) {
        return '?';
    }

    const first = [...words[0]][0] || '';
    const second = words.length > 1 ? [...words[words.length - 1]][0] : '';

    return (first + second).toUpperCase();
}

export function avatar(name, { me = false, small = false } = {}) {
    return el('span', {
        class: `avatar${me ? ' me' : ''}${small ? ' sm' : ''}`,
        'aria-hidden': 'true',
        text: initials(name),
    });
}

// A confirmation that replaces the row it belongs to (no browser dialog). Escape or the
// safe button cancel; focus starts on the safe button.
export function inlineConfirm({ title, text, yes, no, onYes, onNo, danger }) {
    const keep = button(no, { onclick: onNo });
    const go = button(yes, {
        kind: danger ? 'danger' : 'primary',
        onclick: onYes,
    });
    const box = el(
        'div',
        { class: 'confirm stack-sm', role: 'alertdialog', 'aria-label': title },
        el('div', { class: 'strong', text: title }),
        text ? el('div', { class: 'small', text }) : null,
        el('div', { class: 'row wrap' }, keep, go),
    );
    box.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            e.stopPropagation();
            onNo();
        }
    });
    queueMicrotask(() => keep.focus());

    return box;
}
