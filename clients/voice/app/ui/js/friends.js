// Mehr > Freunde: friends, incoming and outgoing requests, suggestions and blocked people, with
// every change confirmed inside the page. Nothing here tells who blocked you: the server never
// does, so neither does the screen.
import { avatar, inlineConfirm } from './shared.js';
import { S } from './strings.js';
import {
    button,
    call,
    clear,
    el,
    emptyState,
    errorState,
    plainMessage,
    say,
    skeleton,
    staleHint,
    toast,
} from './util.js';

let live = false;

export function unmount() {
    live = false;
}

export function mount(box, { back }) {
    live = true;
    const body = el('div', { class: 'stack' });
    box.append(
        el(
            'div',
            { class: 'page-head' },
            el(
                'div',
                { class: 'row' },
                button(S.back, { kind: 'ghost', onclick: back }),
                el('h1', { text: S.friends.title }),
            ),
        ),
        body,
    );

    let data = null; // the last good answer
    let loadError = null;
    let notice = null; // the server's message for the last action that failed
    let confirming = null; // 'remove:13' while a row asks "really?"
    let busy = null; // 'accept:501' while a request is on its way

    const load = async () => {
        const [friends, requests, suggestions, blocked] = await Promise.all([
            call('list_friends'),
            call('list_friend_requests'),
            call('list_friend_suggestions'),
            call('list_blocked_users'),
        ]);

        if (!live) {
            return;
        }

        const failed = [friends, requests, suggestions, blocked].find(
            (r) => !r.ok,
        );

        if (failed) {
            loadError = failed.error;
        } else {
            loadError = null;
            data = {
                friends: friends.value || [],
                incoming: (requests.value || {}).incoming || [],
                outgoing: (requests.value || {}).outgoing || [],
                suggestions: suggestions.value || [],
                blocked: blocked.value || [],
            };
        }

        paint();
    };

    const reload = () => {
        data = null;
        loadError = null;
        paint();

        return load();
    };

    // Runs one change, shows the server's own German message when it refuses, and re-reads the
    // lists either way so the screen never shows what is no longer true.
    const run = async (key, work, success) => {
        busy = key;
        confirming = null;
        notice = null;
        paint();
        const r = await work();
        busy = null;

        if (!live) {
            return;
        }

        if (r.ok) {
            const text = success(r.value);
            toast(text);
            say(text);
        } else {
            notice = plainMessage(r.error);
        }

        await load();
        const target = body.querySelector('[data-first]');

        if (target) {
            target.focus();
        }
    };

    const ask = (key) => {
        confirming = key;
        notice = null;
        paint();
    };

    const user = (u) =>
        el(
            'div',
            { class: 'row grow' },
            avatar(u.name),
            el('div', { class: 'name', text: u.name }),
        );

    const row = (key, content, actions, confirmBox) => {
        if (confirming === key && confirmBox) {
            return el('li', { class: 'pr' }, confirmBox);
        }

        return el(
            'li',
            { class: 'pr' },
            content,
            el('div', { class: 'actions' }, ...actions),
        );
    };

    const small = (label, name, opts) =>
        el('button', {
            class: `btn small-btn ${opts.kind || ''}`.trim(),
            type: 'button',
            text: label,
            'aria-label': S.friends.aria(label, name),
            disabled: busy !== null,
            onclick: opts.onclick,
        });

    const section = (title, items) =>
        items.length
            ? el(
                  'section',
                  { class: 'stack-sm', 'aria-label': title },
                  el('h2', { text: title, tabindex: '-1', 'data-first': '' }),
                  el('ul', { class: 'rows' }, ...items),
              )
            : null;

    const yesNo = (key, title, text, yes, danger, onYes) =>
        inlineConfirm({
            title,
            text,
            yes,
            no: S.friends.keep,
            danger,
            onYes,
            onNo: () => {
                confirming = null;
                paint();
                body.querySelector(`[data-key="${key}"]`)?.focus();
            },
        });

    function paint() {
        clear(body);

        if (loadError && !data) {
            body.append(errorState(loadError, reload, S.friends.failed));

            return;
        }

        if (!data) {
            body.append(skeleton(4));

            return;
        }

        if (loadError) {
            body.append(staleHint(load));
        }

        if (notice) {
            body.append(
                el('div', {
                    class: 'inline-error',
                    role: 'alert',
                    text: notice,
                }),
            );
        }

        const { friends, incoming, outgoing, suggestions, blocked } = data;

        if (
            !friends.length &&
            !incoming.length &&
            !outgoing.length &&
            !suggestions.length &&
            !blocked.length
        ) {
            body.append(emptyState(S.friends.allEmpty, S.friends.allEmptyHint));

            return;
        }

        body.append(
            section(
                S.friends.incoming,
                incoming.map((r) => {
                    const name = r.user.name;

                    return row(
                        `block:${r.user.id}`,
                        user(r.user),
                        [
                            el('button', {
                                class: 'btn small-btn primary',
                                type: 'button',
                                text: S.friends.accept,
                                'aria-label': S.friends.aria(
                                    S.friends.accept,
                                    name,
                                ),
                                disabled: busy !== null,
                                'data-key': `accept:${r.id}`,
                                onclick: () =>
                                    run(
                                        `accept:${r.id}`,
                                        () =>
                                            call('accept_friend_request', {
                                                requestId: r.id,
                                            }),
                                        () => S.friends.accepted(name),
                                    ),
                            }),
                            small(S.friends.decline, name, {
                                onclick: () =>
                                    run(
                                        `decline:${r.id}`,
                                        () =>
                                            call('decline_friend_request', {
                                                requestId: r.id,
                                            }),
                                        () => S.friends.declined,
                                    ),
                            }),
                            small(S.friends.block, name, {
                                kind: 'danger',
                                onclick: () => ask(`block:${r.user.id}`),
                            }),
                        ],
                        yesNo(
                            `block:${r.user.id}`,
                            S.friends.confirmBlockTitle(name),
                            S.friends.confirmBlockText,
                            S.friends.block,
                            true,
                            () =>
                                run(
                                    `block:${r.user.id}`,
                                    () =>
                                        call('block_user', {
                                            userId: r.user.id,
                                        }),
                                    () => S.friends.blockedDone(name),
                                ),
                        ),
                    );
                }),
            ),
        );

        const friendRows = friends.map((u) => {
            const removeKey = `remove:${u.id}`;
            const blockKey = `block:${u.id}`;
            const which = confirming === blockKey ? blockKey : removeKey;
            const confirmBox =
                which === blockKey
                    ? yesNo(
                          blockKey,
                          S.friends.confirmBlockTitle(u.name),
                          S.friends.confirmBlockText,
                          S.friends.block,
                          true,
                          () =>
                              run(
                                  blockKey,
                                  () => call('block_user', { userId: u.id }),
                                  () => S.friends.blockedDone(u.name),
                              ),
                      )
                    : yesNo(
                          removeKey,
                          S.friends.confirmRemoveTitle(u.name),
                          S.friends.confirmRemoveText,
                          S.friends.remove,
                          true,
                          () =>
                              run(
                                  removeKey,
                                  () => call('remove_friend', { userId: u.id }),
                                  () => S.friends.removed(u.name),
                              ),
                      );

            return row(
                which,
                user(u),
                [
                    el('button', {
                        class: 'btn small-btn',
                        type: 'button',
                        text: S.friends.remove,
                        'aria-label': S.friends.aria(S.friends.remove, u.name),
                        'data-key': removeKey,
                        disabled: busy !== null,
                        onclick: () => ask(removeKey),
                    }),
                    small(S.friends.block, u.name, {
                        kind: 'danger',
                        onclick: () => ask(blockKey),
                    }),
                ],
                confirmBox,
            );
        });

        if (friendRows.length) {
            body.append(section(S.friends.friends, friendRows));
        } else {
            body.append(
                el(
                    'section',
                    { class: 'stack-sm', 'aria-label': S.friends.friends },
                    el('h2', {
                        text: S.friends.friends,
                        tabindex: '-1',
                        'data-first': '',
                    }),
                    emptyState(S.friends.noFriends, S.friends.noFriendsHint),
                ),
            );
        }

        body.append(
            section(
                S.friends.suggestions,
                suggestions.map((s) =>
                    row(
                        `suggest:${s.id}`,
                        el(
                            'div',
                            { class: 'row grow' },
                            avatar(s.name),
                            el(
                                'div',
                                { class: 'grow' },
                                el('div', { class: 'name', text: s.name }),
                                (s.reason_labels || []).length
                                    ? el('div', {
                                          class: 'small muted',
                                          text: s.reason_labels.join(', '),
                                      })
                                    : null,
                            ),
                        ),
                        [
                            el('button', {
                                class: 'btn small-btn primary',
                                type: 'button',
                                text: S.friends.send,
                                'aria-label': S.friends.aria(
                                    S.friends.send,
                                    s.name,
                                ),
                                disabled: busy !== null,
                                'data-key': `suggest:${s.id}`,
                                onclick: () =>
                                    run(
                                        `suggest:${s.id}`,
                                        () =>
                                            call('send_friend_request', {
                                                userId: s.id,
                                            }),
                                        (v) =>
                                            v && v.status === 'accepted'
                                                ? S.friends.sentAccepted(s.name)
                                                : S.friends.sent(s.name),
                                    ),
                            }),
                        ],
                    ),
                ),
            ),
            section(
                S.friends.outgoing,
                outgoing.map((r) =>
                    row(
                        `out:${r.id}`,
                        el(
                            'div',
                            { class: 'row grow' },
                            avatar(r.user.name),
                            el(
                                'div',
                                { class: 'grow' },
                                el('div', { class: 'name', text: r.user.name }),
                                el('div', {
                                    class: 'small muted',
                                    text: S.friends.pendingTag,
                                }),
                            ),
                        ),
                        [
                            small(S.friends.cancel, r.user.name, {
                                onclick: () =>
                                    run(
                                        `cancel:${r.id}`,
                                        () =>
                                            call('cancel_friend_request', {
                                                requestId: r.id,
                                            }),
                                        () => S.friends.cancelled,
                                    ),
                            }),
                        ],
                    ),
                ),
            ),
            section(
                S.friends.blocked,
                blocked.map((u) =>
                    row(`unblock:${u.id}`, user(u), [
                        small(S.friends.unblock, u.name, {
                            onclick: () =>
                                run(
                                    `unblock:${u.id}`,
                                    () =>
                                        call('unblock_user', {
                                            userId: u.id,
                                        }),
                                    () => S.friends.unblocked(u.name),
                                ),
                        }),
                    ]),
                ),
            ),
            el('p', { class: 'hint', text: S.friends.blockedHint }),
        );
    }

    paint();
    load();
}
