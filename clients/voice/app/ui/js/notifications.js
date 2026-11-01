// Mehr > Benachrichtigungen: the history (unread first), mark read, the Ruhe toggle and one
// switch per category. Two layers, in plain words: what lands in the app list (server side) and
// what pops up on this PC (local).
import { go } from './nav.js';
import { notificationTarget } from './shared.js';
import { S } from './strings.js';
import {
    button,
    call,
    chip,
    clear,
    dayTime,
    el,
    emit,
    emptyState,
    errorState,
    on,
    plainMessage,
    say,
    skeleton,
    state,
    toast,
} from './util.js';

const LOCAL_DEFAULT_ON = new Set(['match', 'catering', 'direct', 'vote']);
const SERVER_KEYS = [
    'registration',
    'checkin',
    'match',
    'teams',
    'friends',
    'schedule',
    'catering',
    'orga_ping',
    'discord',
];
const ALL_KEYS = [
    'match',
    'catering',
    'direct',
    'vote',
    'checkin',
    'registration',
    'teams',
    'friends',
    'schedule',
    'orga_ping',
    'discord',
    'account',
    'system',
    'other',
];

on('quiet-changed', () => {
    const b = document.getElementById('quiet-row');

    if (b) {
        b.textContent = state.quiet ? S.quiet.on : S.quiet.off;
        b.setAttribute('aria-pressed', String(state.quiet));
        b.classList.toggle('pressed', state.quiet);
    }
});

export function mount(box, { back } = {}) {
    const head = el(
        'div',
        { class: 'page-head' },
        el(
            'div',
            { class: 'row' },
            button(S.back, { kind: 'ghost', onclick: back }),
            el('h1', { text: S.more.notifications }),
        ),
    );
    const body = el('div', { class: 'stack' });
    const listBox = el('div', { class: 'stack-sm' });
    const toggles = el('div', {});
    box.append(head, body);
    const items = [];
    let page = 1;
    let hasMore = false;
    let phase = 'loading';
    let error = null;

    const quietRow = el(
        'div',
        { class: 'card row between wrap' },
        el(
            'div',
            { class: 'grow' },
            el('div', { class: 'strong', text: S.quiet.rowTitle }),
            el('div', { class: 'small muted', text: S.quiet.rowHint }),
        ),
        el('button', {
            class: 'btn' + (state.quiet ? ' pressed' : ''),
            id: 'quiet-row',
            type: 'button',
            'aria-pressed': String(state.quiet),
            text: state.quiet ? S.quiet.on : S.quiet.off,
            onclick: () => document.getElementById('quiet').click(),
        }),
    );
    const paint = () => {
        clear(listBox);

        if (phase === 'loading' && !items.length) {
            listBox.append(skeleton(3));

            return;
        }

        if (phase === 'error' && !items.length) {
            listBox.append(errorState(error, () => load(1)));

            return;
        }

        if (!items.length) {
            listBox.append(emptyState(S.notes.none, S.notes.noneHint));

            return;
        }

        for (const n of items) {
            const [tab, card] = notificationTarget(n.category);
            listBox.append(
                el(
                    'div',
                    { class: 'note-row' + (n.read ? '' : ' unread') },
                    el(
                        'div',
                        { class: 'grow' },
                        el(
                            'div',
                            { class: 'row wrap' },
                            el('span', { class: 'name', text: n.title }),
                            chip(S.categories[n.category] || n.category),
                        ),
                        n.body
                            ? el('div', { class: 'small', text: n.body })
                            : null,
                        el('div', {
                            class: 'small muted',
                            text: dayTime(n.created_at),
                        }),
                    ),
                    el(
                        'div',
                        { class: 'row wrap' },
                        button(S.now.open, {
                            kind: 'ghost',
                            onclick: () => {
                                if (!n.read) {
                                    markRead(n);
                                }

                                go(tab, { card, force: true });
                            },
                        }),
                        n.read
                            ? null
                            : button(S.now.markRead, {
                                  kind: 'ghost',
                                  onclick: () => markRead(n),
                              }),
                    ),
                ),
            );
        }

        if (hasMore) {
            listBox.append(
                button(S.notes.older, { onclick: () => load(page + 1) }),
            );
        }
    };

    const markRead = async (n) => {
        const r = await call('mark_notification_read', { id: n.id });

        if (!r.ok) {
            toast(plainMessage(r.error), 'error');

            return;
        }

        n.read = true;
        state.unread = Math.max(0, state.unread - 1);
        emit('unread-local');
        paint();
    };

    const load = async (p) => {
        phase = 'loading';

        if (p === 1) {
            items.length = 0;
        }

        paint();
        const r = await call('list_notifications', { page: p, perPage: 20 });

        if (!r.ok) {
            phase = 'error';
            error = r.error;
            paint();

            return;
        }

        page = r.value.page;
        hasMore = r.value.has_more;
        items.push(...r.value.notifications);
        phase = 'ready';
        paint();
    };

    const markAll = button(S.notes.markAll, {
        onclick: async () => {
            const r = await call('mark_all_notifications_read');

            if (!r.ok) {
                toast(plainMessage(r.error), 'error');

                return;
            }

            for (const n of items) {
                n.read = true;
            }

            state.unread = 0;
            emit('unread-local');
            say(S.notes.allRead);
            paint();
        },
    });

    body.append(
        quietRow,
        el(
            'div',
            { class: 'row between' },
            el('h2', { text: S.notes.history }),
            markAll,
        ),
        listBox,
        el('h2', { text: S.notes.settingsTitle }),
        toggles,
    );
    toggles.append(categoryToggles());
    load(1);
}

// One row per category: "in der App" (server) and "auf diesem PC" (local).
export function categoryToggles() {
    const wrap = el('div', { class: 'card stack-sm' });
    const msg = el('div', { class: 'small', role: 'status' });
    let server = null;

    const local = (k) => {
        const o = state.settings && state.settings.notify_categories;

        return o && k in o ? Boolean(o[k]) : LOCAL_DEFAULT_ON.has(k);
    };

    const saveLocal = async (k, v) => {
        const next = {
            ...state.settings,
            notify_categories: {
                ...(state.settings.notify_categories || {}),
                [k]: v,
            },
        };
        const r = await call('save_settings', { settings: next });

        if (!r.ok) {
            msg.textContent = plainMessage(r.error);

            return false;
        }

        state.settings = r.value.settings;
        msg.textContent = S.saved;

        return true;
    };

    const saveServer = async (k, v) => {
        const r = await call('set_notification_preferences', {
            preferences: { [k]: v },
        });

        if (!r.ok) {
            msg.textContent = plainMessage(r.error);

            return false;
        }

        server = r.value;
        msg.textContent = S.saved;

        return true;
    };

    const draw = () => {
        clear(wrap);
        wrap.append(
            el('div', { class: 'small muted', text: S.notes.settingsHint }),
            el(
                'div',
                {
                    class: 'cat-grid',
                    role: 'table',
                    'aria-label': S.notes.settingsTitle,
                },
                el(
                    'div',
                    { class: 'cat-row head', role: 'row' },
                    el('span', {
                        role: 'columnheader',
                        text: S.notes.category,
                    }),
                    el('span', { role: 'columnheader', text: S.notes.inApp }),
                    el('span', { role: 'columnheader', text: S.notes.onPc }),
                ),
                ...ALL_KEYS.map((k) => {
                    const hasServer =
                        SERVER_KEYS.includes(k) && server && k in server;
                    const sBox = hasServer
                        ? el('input', {
                              type: 'checkbox',
                              checked: Boolean(server[k]),
                              'aria-label': S.notes.inAppAria(S.categories[k]),
                              onchange: async (ev) => {
                                  const box = ev.currentTarget;
                                  const ok = await saveServer(k, box.checked);

                                  if (!ok) {
                                      box.checked = !box.checked;
                                  }
                              },
                          })
                        : el('span', {
                              class: 'muted',
                              text: '–',
                              'aria-label': S.notes.fixed,
                          });
                    const lBox = el('input', {
                        type: 'checkbox',
                        checked: local(k),
                        'aria-label': S.notes.onPcAria(S.categories[k]),
                        onchange: async (ev) => {
                            const box = ev.currentTarget;
                            const ok = await saveLocal(k, box.checked);

                            if (!ok) {
                                box.checked = !box.checked;
                            }
                        },
                    });

                    return el(
                        'div',
                        { class: 'cat-row', role: 'row' },
                        el('span', { role: 'cell', text: S.categories[k] }),
                        el('span', { role: 'cell' }, sBox),
                        el('span', { role: 'cell' }, lBox),
                    );
                }),
            ),
            msg,
        );
    };

    draw();
    call('get_notification_preferences').then((r) => {
        if (r.ok) {
            server = r.value;
        }

        draw();
    });

    return wrap;
}
