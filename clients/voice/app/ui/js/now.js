// Jetzt: "What do I do now?" The prioritised cards of the now feed, most urgent first, plus the
// client-side voice card. Polls the feed calmly while visible and keeps the tab badges.
import { matchCard } from './matches.js';
import { go, pageHeader, setBadge } from './nav.js';
import { eventSlug, noEventState, notificationTarget } from './shared.js';
import { S } from './strings.js';
import {
    button,
    call,
    chip,
    clear,
    dayTime,
    dot,
    el,
    emptyState,
    errorState,
    euro,
    on,
    percent,
    plainMessage,
    relative,
    skeleton,
    staleHint,
    state,
    toast,
} from './util.js';
import { bindPtt, inRoom, toggleMute } from './voice.js';

const MAX_SHOWN = 5;
let root = null;
let listBox = null;
let feed = null; // last good answer
let phase = 'loading';
let error = null;
let lastJson = '';
let showAll = false;
let timer = null;
const done = new Map(); // card id -> {title, lines, until}
const voiceCard = {};

export function init(section) {
    root = section;
    listBox = el('div', { class: 'stack' });
    root.append(pageHeader('jetzt'), listBox);
    on('room-view', paintVoiceCard);
    on('tab', schedule);
    on('online', () => refresh());
    document.addEventListener('visibilitychange', schedule);
    render();
}

export async function show() {
    if (!feed) {
        phase = 'loading';
        render();
    }

    await refresh();
}

export function hide() {
    done.clear();
    lastJson = '';
}

export function start() {
    schedule();
}

// Calm polling: 30 s while Jetzt is shown, 60 s for the badges elsewhere, nothing while hidden.
function schedule() {
    clearTimeout(timer);

    if (document.visibilityState !== 'visible' || !state.status) {
        return;
    }

    timer = setTimeout(
        async () => {
            await refresh();
            schedule();
        },
        state.tab === 'jetzt' ? 30000 : 60000,
    );
}

export async function refresh() {
    const slug = eventSlug();

    if (!slug) {
        phase = 'noevent';
        feed = null;
        updateBadges();
        render();

        return;
    }

    const r = await call('now_feed', { event: slug });

    if (!r.ok) {
        error = r.error;
        phase = 'error';
        render();

        return;
    }

    feed = r.value;
    phase = 'ready';
    error = null;
    updateBadges();

    const json = JSON.stringify(feed);

    if (json !== lastJson || done.size) {
        lastJson = json;
        render();
    }
}

function allCards() {
    return feed ? [...(feed.cards || []), ...(feed.more || [])] : [];
}

function updateBadges() {
    const cards = allCards();
    const count = (f) => cards.filter(f).length;
    setBadge(
        'jetzt',
        count((c) =>
            ['match', 'vote', 'order', 'notification'].includes(c.kind),
        ),
    );
    setBadge(
        'spielen',
        count((c) => c.kind === 'match'),
    );
    setBadge(
        'essen',
        count(
            (c) =>
                c.kind === 'order' ||
                (c.kind === 'notification' &&
                    c.data &&
                    c.data.category === 'catering'),
        ),
    );
    setBadge(
        'mehr',
        count((c) => c.kind === 'vote'),
    );
}

function markDone(id, title, lines) {
    done.set(id, { title, lines, until: Date.now() + 60000 });
    lastJson = '';
}

// ---- rendering ----

function render() {
    if (!listBox) {
        return;
    }

    clear(listBox);

    if (phase === 'noevent') {
        listBox.append(noEventState());
        appendVoice(listBox);

        return;
    }

    if (phase === 'loading') {
        listBox.append(skeleton(3));

        return;
    }

    if (phase === 'error' && !feed) {
        listBox.append(errorState(error, refresh, S.now.failed));

        return;
    }

    if (phase === 'error') {
        listBox.append(staleHint(refresh));
    }

    const now = Date.now();

    for (const [id, d] of done) {
        if (d.until < now) {
            done.delete(id);
        }
    }

    for (const d of done.values()) {
        listBox.append(doneCard(d));
    }

    const cards = allCards();
    const urgent = cards.filter((c) => c.kind !== 'schedule');
    const schedule = cards.find((c) => c.kind === 'schedule');
    const firstSoft = urgent.findIndex((c) => c.kind === 'notification');
    const items = urgent.map((c) => ({ kind: 'feed', card: c }));
    const voiceAt = firstSoft === -1 ? items.length : firstSoft;
    // The voice card is always among the first five, even when the feed is full.
    items.splice(Math.min(voiceAt, MAX_SHOWN - 1), 0, { kind: 'voice' });
    const shown = showAll ? items : items.slice(0, MAX_SHOWN);

    if (!urgent.length && !done.size) {
        listBox.append(emptyState(S.now.nothing, S.now.nothingHint));
    }

    for (const it of shown) {
        listBox.append(it.kind === 'voice' ? getVoiceCard() : cardFor(it.card));
    }

    if (items.length > shown.length) {
        listBox.append(
            button(S.now.more(items.length - shown.length), {
                onclick: () => {
                    showAll = true;
                    render();
                },
            }),
        );
    }

    if (schedule) {
        listBox.append(scheduleCard(schedule, !urgent.length));
    }
}

function appendVoice(box) {
    box.append(getVoiceCard());
}

function head(titleText, chips = [], tone = '') {
    return el(
        'div',
        { class: 'row between wrap card-head' },
        el(
            'div',
            { class: 'row' },
            tone ? dot(tone, tone === 'live') : null,
            el('h2', { text: titleText }),
        ),
        el('div', { class: 'row wrap' }, ...chips),
    );
}

function doneCard(d) {
    return el(
        'article',
        { class: 'card done', role: 'status' },
        head(d.title, [chip(S.now.done, 'ok')], 'ok'),
        ...d.lines.map((l) => el('div', { class: 'small', text: l })),
    );
}

function cardFor(c) {
    if (c.kind === 'match') {
        return matchFeedCard(c);
    }

    if (c.kind === 'vote') {
        return voteCard(c);
    }

    if (c.kind === 'order') {
        return orderCard(c);
    }

    if (c.kind === 'notification') {
        return notificationCard(c);
    }

    return el(
        'article',
        { class: 'card' },
        head(c.title),
        c.body ? el('p', { class: 'small', text: c.body }) : null,
    );
}

// ---- match ----

function matchFeedCard(c) {
    const data = c.data || {};

    if (c.state === 'checkin_open' && data.tournament) {
        const t = data.tournament;
        const msg = el('div', {
            class: 'inline-error',
            role: 'alert',
            hidden: true,
        });

        return el(
            'article',
            { class: 'card match' },
            head(
                c.title || S.now.checkinOpen,
                [
                    c.at ? chip(S.now.closes(relative(c.at)), 'warn') : null,
                ].filter(Boolean),
                'warn',
            ),
            el('div', {
                class: 'small muted',
                text: [t.name, t.game].filter(Boolean).join(' · '),
            }),
            c.body ? el('p', { text: c.body }) : null,
            el(
                'div',
                { class: 'row' },
                button(S.matches.checkIn, {
                    kind: 'primary',
                    onclick: async (ev) => {
                        const btn = ev.currentTarget;
                        btn.disabled = true;
                        const r = await call('tournament_checkin', {
                            tournamentId: t.id,
                        });

                        if (!r.ok) {
                            btn.disabled = false;
                            msg.hidden = false;
                            msg.textContent = plainMessage(r.error);

                            return;
                        }

                        markDone(c.id, S.matches.checkedIn, [t.name]);
                        refresh();
                    },
                }),
            ),
            msg,
        );
    }

    if (!data.match) {
        return el('article', { class: 'card' }, head(c.title));
    }

    const m = data.match;
    const wrap = matchCard(m, { compact: true, onChange: refresh });
    const tone =
        c.state === 'live'
            ? 'live'
            : c.state === 'confirm_result'
              ? 'warn'
              : 'ok';
    const heading = head(c.title, [], tone);
    wrap.prepend(heading);
    wrap.classList.add('feed');

    return wrap;
}

// ---- vote ----

function voteCard(c) {
    const d = c.data || {};
    const msg = el('div', {
        class: 'inline-error',
        role: 'alert',
        hidden: true,
    });
    const opts = el('div', {
        class: 'vote-options',
        role: 'group',
        'aria-label': d.question || c.title,
    });
    const buttons = [];

    for (const o of d.options || []) {
        const b = button(o.label, {
            onclick: async () => {
                for (const x of buttons) {
                    x.disabled = true;
                }

                msg.hidden = true;
                const r = await call('vote_poll', {
                    pollId: d.pollId,
                    optionId: o.id,
                });

                if (!r.ok) {
                    for (const x of buttons) {
                        x.disabled = false;
                    }

                    msg.hidden = false;
                    msg.textContent = plainMessage(r.error);

                    if (
                        r.error.code === 'already_voted' ||
                        r.error.code === 'not_open'
                    ) {
                        refresh();
                    }

                    return;
                }

                const poll = r.value;
                markDone(c.id, S.now.voted(o.label), [
                    d.question,
                    ...(poll.options || []).map(
                        (p) => `${p.label}: ${p.count} (${percent(p.percent)})`,
                    ),
                ]);
                toast(S.now.voteCounted);
                refresh();
            },
        });
        buttons.push(b);
        opts.append(b);
    }

    return el(
        'article',
        { class: 'card vote' },
        head(
            S.now.vote,
            [
                d.closesAt
                    ? chip(S.now.closes(relative(d.closesAt)), 'warn')
                    : null,
            ].filter(Boolean),
        ),
        el('p', { class: 'question', text: d.question || c.title }),
        opts,
        msg,
    );
}

// ---- order ----

function orderCard(c) {
    const d = c.data || {};
    const menu = (d.menu || []).slice(0, 3);
    const msg = el('div', {
        class: 'inline-error',
        role: 'alert',
        hidden: true,
    });
    const row = el('div', { class: 'row wrap' });

    for (const item of menu) {
        row.append(
            button(
                `${item.name} · ${euro(item.priceCents ?? item.price_cents ?? 0)}`,
                {
                    kind: 'primary-soft',
                    onclick: async (ev) => {
                        const btn = ev.currentTarget;
                        btn.disabled = true;
                        const r = await call('place_food_item', {
                            orderId: d.orderId,
                            optionKey: item.key,
                        });
                        btn.disabled = false;

                        if (!r.ok) {
                            msg.hidden = false;
                            msg.textContent = plainMessage(r.error);

                            return;
                        }

                        markDone(c.id, S.now.ordered(item.name), [
                            S.now.orderedNote(d.title),
                        ]);
                        toast(S.now.ordered(item.name));
                        refresh();
                    },
                },
            ),
        );
    }

    return el(
        'article',
        { class: 'card order' },
        head(
            c.title || S.now.order,
            [
                d.closesAt
                    ? chip(S.now.closes(relative(d.closesAt)), 'warn')
                    : null,
            ].filter(Boolean),
        ),
        el('div', { class: 'small muted', text: d.title }),
        menu.length
            ? el('div', { class: 'small', text: S.now.quickOrder })
            : null,
        row,
        el(
            'div',
            { class: 'row' },
            button(S.now.toMenu, { kind: 'ghost', onclick: () => go('essen') }),
        ),
        msg,
    );
}

// ---- notification ----

function notificationCard(c) {
    const d = c.data || {};
    const [tab, card] = notificationTarget(d.category);
    const row = el('div', { class: 'row wrap' });

    row.append(
        button(S.now.open, {
            kind: 'primary-soft',
            onclick: () => go(tab, { card, force: true }),
        }),
        button(S.now.markRead, {
            kind: 'ghost',
            onclick: async (ev) => {
                const btn = ev.currentTarget;
                btn.disabled = true;
                const r = await call('mark_notification_read', {
                    id: d.notificationId,
                });

                if (!r.ok) {
                    btn.disabled = false;
                    toast(plainMessage(r.error), 'error');

                    return;
                }

                refresh();
            },
        }),
    );

    return el(
        'article',
        { class: 'card note' },
        head(
            c.title,
            [
                d.category
                    ? chip(S.categories[d.category] || d.category)
                    : null,
            ].filter(Boolean),
        ),
        c.body ? el('p', { class: 'small', text: c.body }) : null,
        row,
    );
}

// ---- schedule ----

function scheduleCard(c, calm) {
    const item = (c.data && c.data.item) || {};
    const when = c.at || item.starts_at || item.startsAt;

    return el(
        'article',
        { class: 'card schedule' + (calm ? ' calm' : '') },
        head(S.now.schedule, [
            chip(c.state === 'running' ? S.now.running : S.now.next),
        ]),
        el('div', { class: 'strong', text: c.title || item.title }),
        c.body || item.description
            ? el('div', {
                  class: 'small muted',
                  text: c.body || item.description,
              })
            : null,
        when
            ? el('div', {
                  class: 'small mono',
                  text: `${dayTime(when)} · ${relative(when)}`,
              })
            : null,
    );
}

// ---- the client-side voice card, always shown while connected ----

function getVoiceCard() {
    if (!voiceCard.wrap) {
        voiceCard.dot = dot();
        voiceCard.title = el('h2');
        voiceCard.sub = el('div', { class: 'small muted' });
        voiceCard.ptt = el('button', {
            class: 'btn ptt-mini',
            type: 'button',
            'aria-pressed': 'false',
        });
        bindPtt(voiceCard.ptt);
        voiceCard.mute = el('button', {
            class: 'btn',
            type: 'button',
            onclick: toggleMute,
        });
        voiceCard.open = button(S.now.toVoice, {
            kind: 'primary-soft',
            onclick: () => go('sprache'),
        });
        voiceCard.who = el('div', { class: 'small' });
        voiceCard.row = el(
            'div',
            { class: 'row wrap' },
            voiceCard.ptt,
            voiceCard.mute,
            voiceCard.open,
        );
        voiceCard.wrap = el(
            'article',
            { class: 'card voice' },
            el('div', { class: 'row' }, voiceCard.dot, voiceCard.title),
            voiceCard.sub,
            voiceCard.who,
            voiceCard.row,
        );
    }

    paintVoiceCard();

    return voiceCard.wrap;
}

function paintVoiceCard() {
    if (!voiceCard.wrap) {
        return;
    }

    const v = state.view;
    const inside = inRoom();
    voiceCard.title.textContent = inside ? v.room.name : S.now.voiceTitle;
    voiceCard.dot.className =
        'dot ' +
        (inside
            ? v.talking
                ? 'live'
                : v.phase === 'connected'
                  ? 'ok'
                  : 'warn'
            : '');
    voiceCard.sub.textContent = inside ? S.now.inRoomSub : S.now.noRoom;
    voiceCard.ptt.hidden = !inside;
    voiceCard.mute.hidden = !inside;
    voiceCard.who.hidden = !inside;

    if (inside) {
        const speaking = v.participants
            .filter((p) => p.speaking)
            .map((p) => p.name);
        voiceCard.who.textContent = speaking.length
            ? S.now.speakingNow(speaking.join(', '))
            : S.now.quietRoom;
        voiceCard.ptt.disabled =
            v.listen_only || v.muted || v.phase !== 'connected';
        voiceCard.ptt.classList.toggle('talking', v.talking);
        voiceCard.ptt.setAttribute('aria-pressed', String(v.talking));
        voiceCard.ptt.textContent = v.talking
            ? S.voice.sending
            : S.voice.holdToTalk;
        voiceCard.mute.textContent = v.muted ? S.voice.unmute : S.voice.mute;
        voiceCard.mute.classList.toggle('pressed', v.muted);
    }

    voiceCard.open.textContent = inside ? S.now.toVoice : S.now.pickRoom;
}
