// Mehr: the hub of everything that is not needed every minute. Each entry opens its own page
// inside this tab; Friends and Profil appear only when the Rust side has their commands.
import * as files from './files.js';
import { moduleOn, pageHeader } from './nav.js';
import * as notifications from './notifications.js';
import * as polls from './polls.js';
import { mountAccount, mountSettings } from './settings.js';
import { S } from './strings.js';
import {
    clear,
    el,
    invoke,
    isUnknownCommand,
    normalizeError,
    on,
    state,
} from './util.js';

let root = null;
let box = null;
let route = 'hub';
let logoutFn = () => {};
const optional = { friends: false, profile: false };

// A command the Rust side does not have yet rejects with "unknown command". Any other answer
// (even an error) means the command exists. Nothing is faked: the entries stay hidden otherwise.
const OPTIONAL_PROBES = {
    friends: 'list_friends',
    profile: 'get_profile',
};

export async function probeOptional() {
    for (const [id, cmd] of Object.entries(OPTIONAL_PROBES)) {
        try {
            await invoke(cmd);
            optional[id] = true;
        } catch (e) {
            optional[id] = !isUnknownCommand(normalizeError(e));
        }
    }
}

export function init(section, { logout }) {
    root = section;
    logoutFn = logout;
    box = el('div', { class: 'stack' });
    root.append(box);
    files.init();
    on('unread-local', () => route === 'hub' && renderHub());
    on('unread', () => route === 'hub' && renderHub());
}

export async function show(opts = {}) {
    const hint = {
        vote: 'polls',
        notifications: 'notifications',
        friends: 'friends',
    }[opts.card];
    open(hint || route);
}

export function hide() {
    files.unmount();
}

export function focus(opts) {
    show(opts);
}

function open(next) {
    route = next;
    files.unmount();
    clear(box);
    const back = () => open('hub');

    if (next === 'hub') {
        renderHub();
    } else if (next === 'polls') {
        polls.mount(box, { back });
    } else if (next === 'files') {
        files.mount(box, { back });
    } else if (next === 'notifications') {
        notifications.mount(box, { back });
    } else if (next === 'settings') {
        mountSettings(box, { back });
    } else if (next === 'account') {
        mountAccount(box, { back, logout: logoutFn });
    } else if (next === 'friends' || next === 'profile') {
        box.append(
            el(
                'div',
                { class: 'page-head' },
                el(
                    'div',
                    { class: 'row' },
                    el('button', {
                        class: 'btn ghost',
                        type: 'button',
                        text: S.back,
                        onclick: back,
                    }),
                    el('h1', { text: S.more[next] }),
                ),
            ),
            el(
                'div',
                { class: 'empty', role: 'status' },
                el('div', { class: 'strong', text: S.more.pending }),
                el('div', { class: 'small', text: S.more.pendingHint }),
            ),
        );
    }

    const main = document.getElementById('main');
    main.scrollTop = 0;
}

function entry(id, title, hint, badge) {
    return el(
        'button',
        { class: 'list-row hub', type: 'button', onclick: () => open(id) },
        el(
            'div',
            { class: 'grow' },
            el('div', { class: 'name', text: title }),
            el('div', { class: 'small muted', text: hint }),
        ),
        badge
            ? el('span', { class: 'badge inline', text: String(badge) })
            : null,
        el('span', { class: 'chev', 'aria-hidden': 'true', text: '›' }),
    );
}

function renderHub() {
    clear(box);
    box.append(pageHeader('mehr'));
    const list = el('div', { class: 'stack-sm' });
    const votes =
        Number(document.getElementById('badge-mehr')?.textContent) || 0;

    if (moduleOn('voting')) {
        list.append(entry('polls', S.more.polls, S.more.pollsHint, votes));
    }

    if (moduleOn('files')) {
        list.append(entry('files', S.more.files, S.more.filesHint));
    }

    list.append(
        entry(
            'notifications',
            S.more.notifications,
            S.more.notificationsHint,
            state.unread,
        ),
    );

    if (optional.friends) {
        list.append(entry('friends', S.more.friends, S.more.friendsHint));
    }

    if (optional.profile) {
        list.append(entry('profile', S.more.profile, S.more.profileHint));
    }

    list.append(
        entry('settings', S.more.settings, S.more.settingsHint),
        entry('account', S.more.account, S.more.accountHint),
    );
    box.append(list);
}

export const refreshHub = () => route === 'hub' && renderHub();
