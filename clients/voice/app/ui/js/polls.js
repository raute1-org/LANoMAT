// Mehr > Abstimmungen: the polls of the event, one tap to vote, results as the server returns
// them.
import { eventSlug, noEventState } from './shared.js';
import { S } from './strings.js';
import {
    button,
    call,
    chip,
    clear,
    el,
    emptyState,
    errorState,
    percent,
    plainMessage,
    relative,
    say,
    skeleton,
    toast,
} from './util.js';

export function mount(box, { back, openId } = {}) {
    const slug = eventSlug();
    const head = el(
        'div',
        { class: 'page-head' },
        el(
            'div',
            { class: 'row' },
            button(S.back, { kind: 'ghost', onclick: back }),
            el('h1', { text: S.more.polls }),
        ),
    );
    const body = el('div', { class: 'stack' });
    box.append(head, body);

    if (!slug) {
        body.append(noEventState());

        return;
    }

    const showList = async () => {
        clear(body);
        body.append(skeleton(3));
        const r = await call('list_polls', { event: slug });
        clear(body);

        if (!r.ok) {
            body.append(errorState(r.error, showList));

            return;
        }

        const polls = r.value.polls || [];

        if (!polls.length) {
            body.append(emptyState(S.polls.none, S.polls.noneHint));

            return;
        }

        const open = polls.filter((p) => p.is_open);
        const closed = polls.filter((p) => !p.is_open);

        for (const [title, list] of [
            [S.polls.open, open],
            [S.polls.closed, closed],
        ]) {
            if (!list.length) {
                continue;
            }

            body.append(el('div', { class: 'group-title', text: title }));

            for (const p of list) {
                body.append(
                    el(
                        'button',
                        {
                            class: 'list-row',
                            type: 'button',
                            onclick: () => showDetail(p.id),
                        },
                        el(
                            'div',
                            { class: 'grow' },
                            el('div', { class: 'name', text: p.question }),
                            el('div', {
                                class: 'small muted',
                                text: [
                                    S.polls.votes(p.total_votes),
                                    p.is_open && p.closes_at
                                        ? S.polls.closes(relative(p.closes_at))
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            }),
                        ),
                        p.my_vote_option_id
                            ? chip(S.polls.voted, 'ok')
                            : p.is_open
                              ? chip(S.polls.todo, 'live')
                              : chip(S.polls.over),
                    ),
                );
            }
        }
    };

    const showDetail = async (id) => {
        clear(body);
        body.append(skeleton(2));
        const r = await call('get_poll', { pollId: id });
        clear(body);
        body.append(
            button(S.polls.allPolls, { kind: 'ghost', onclick: showList }),
        );

        if (!r.ok) {
            body.append(errorState(r.error, () => showDetail(id)));

            return;
        }

        paintDetail(r.value);
    };

    const paintDetail = (p) => {
        const msg = el('div', {
            class: 'inline-error',
            role: 'alert',
            hidden: true,
        });
        const canVote = p.is_open && !p.my_vote_option_id;
        const list = el('div', {
            class: canVote ? 'vote-options' : 'results',
            role: 'group',
            'aria-label': p.question,
        });
        const buttons = [];

        for (const o of p.options || []) {
            const mine = p.my_vote_option_id === o.id;

            if (canVote) {
                const b = el(
                    'button',
                    {
                        class: 'btn vote-btn',
                        type: 'button',
                        onclick: async () => {
                            for (const x of buttons) {
                                x.disabled = true;
                            }

                            msg.hidden = true;
                            const v = await call('vote_poll', {
                                pollId: p.id,
                                optionId: o.id,
                            });

                            if (!v.ok) {
                                for (const x of buttons) {
                                    x.disabled = false;
                                }

                                msg.hidden = false;
                                msg.textContent = plainMessage(v.error);

                                return;
                            }

                            toast(S.polls.counted);
                            say(S.polls.counted);
                            clear(body);
                            body.append(
                                button(S.polls.allPolls, {
                                    kind: 'ghost',
                                    onclick: showList,
                                }),
                            );
                            paintDetail(v.value);
                        },
                    },
                    el('span', { text: o.label }),
                );
                buttons.push(b);
                list.append(b);
            } else {
                list.append(
                    el(
                        'div',
                        { class: 'result' + (mine ? ' mine' : '') },
                        el(
                            'div',
                            { class: 'row between' },
                            el('span', {
                                class: 'name',
                                text:
                                    o.label +
                                    (mine ? ` (${S.polls.yourVote})` : ''),
                            }),
                            el('span', {
                                class: 'mono small',
                                text: `${o.count} · ${percent(o.percent)}`,
                            }),
                        ),
                        bar(o.percent),
                    ),
                );
            }
        }

        body.append(
            el(
                'article',
                { class: 'card vote' },
                el(
                    'div',
                    { class: 'row between wrap' },
                    el('h2', { text: p.question }),
                    chip(
                        p.is_open ? S.polls.runs : S.polls.over,
                        p.is_open ? 'ok' : '',
                    ),
                ),
                el('div', {
                    class: 'small muted',
                    text: [
                        S.polls.votes(p.total_votes),
                        p.is_open && p.closes_at
                            ? S.polls.closes(relative(p.closes_at))
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · '),
                }),
                p.my_vote_option_id
                    ? el('div', {
                          class: 'hint',
                          role: 'status',
                          text: S.polls.oneVote,
                      })
                    : null,
                list,
                msg,
            ),
        );
    };

    if (openId) {
        showDetail(openId);
    } else {
        showList();
    }
}

export function bar(percent) {
    const fill = el('span', { class: 'bar-fill' });
    fill.style.width = `${Math.max(0, Math.min(100, percent))}%`;

    return el('div', { class: 'bar-track', 'aria-hidden': 'true' }, fill);
}
