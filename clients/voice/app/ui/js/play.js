// Spielen: my matches, the tournaments with my enrollment, and the game servers (event and
// permanent) as cards.
import { matchCard, tournamentCard } from './matches.js';
import { moduleOn, pageHeader } from './nav.js';
import {
    eventSlug,
    installHint,
    noEventState,
    serverActions,
    serverLine,
} from './shared.js';
import { S } from './strings.js';
import {
    call,
    chip,
    clear,
    dayTime,
    el,
    emptyState,
    errorState,
    on,
    skeleton,
    staleHint,
    state,
    button,
} from './util.js';

const SUBS = [
    ['matches', 'tournaments'],
    ['tournaments', 'tournaments'],
    ['servers', 'game_servers'],
];
const data = {
    matches: { phase: 'loading', items: [], error: null },
    tournaments: { phase: 'loading', items: [], error: null },
    servers: { phase: 'loading', event: [], permanent: [], error: null },
};
let root = null;
let segBox = null;
let body = null;
let sub = 'matches';
let timer = null;

export function init(section) {
    root = section;
    segBox = el('div', {
        class: 'seg',
        role: 'group',
        'aria-label': S.play.sections,
    });
    body = el('div', { class: 'stack' });
    root.append(pageHeader('spielen'), segBox, body);
    on('tab', () => (state.tab === 'spielen' ? poll() : clearTimeout(timer)));
}

const available = () =>
    SUBS.filter(([, mod]) => moduleOn(mod)).map(([id]) => id);

export async function show(opts = {}) {
    const avail = available();
    const hint = {
        match: 'matches',
        checkin: 'tournaments',
        tournaments: 'tournaments',
        server: 'servers',
    }[opts.card];
    const want = opts.sub || hint;

    if (want && avail.includes(want)) {
        sub = want;
    } else if (!avail.includes(sub)) {
        sub = avail[0];
    }

    paintSeg();
    render();
    await load(sub);
    poll();
}

export function hide() {
    clearTimeout(timer);
}

export function focus(opts) {
    show(opts);
}

function poll() {
    clearTimeout(timer);

    if (state.tab !== 'spielen' || document.visibilityState !== 'visible') {
        return;
    }

    timer = setTimeout(async () => {
        await load(sub, { quiet: true });
        poll();
    }, 20000);
}

function paintSeg() {
    clear(segBox);

    for (const id of available()) {
        segBox.append(
            el('button', {
                class: 'seg-btn',
                type: 'button',
                'aria-pressed': String(id === sub),
                text: S.play.sub[id],
                onclick: () => {
                    sub = id;
                    paintSeg();
                    render();
                    load(id);
                },
            }),
        );
    }
}

async function load(which, { quiet = false } = {}) {
    const slug = eventSlug();

    if (!slug && which !== 'servers') {
        render();

        return;
    }

    const d = data[which];

    if (!quiet && d.phase !== 'ready') {
        d.phase = 'loading';
        render();
    }

    if (which === 'matches') {
        const r = await call('list_my_matches', { event: slug });
        apply(d, r, (v) => (Array.isArray(v) ? v : []));
    } else if (which === 'tournaments') {
        const r = await call('list_tournaments', { event: slug });
        apply(d, r, (v) => (Array.isArray(v) ? v : []));
    } else {
        const [ev, perm] = await Promise.all([
            slug
                ? call('list_event_servers', { event: slug })
                : Promise.resolve({ ok: true, value: [] }),
            call('list_permanent_servers'),
        ]);

        if (!ev.ok && !perm.ok) {
            d.phase = 'error';
            d.error = ev.error;
        } else {
            d.event = ev.ok ? ev.value : [];
            d.permanent = perm.ok ? perm.value : [];
            d.phase = 'ready';
            d.error = null;
            d.stale = false;
        }
    }

    const json = JSON.stringify([
        d.phase,
        d.stale,
        d.items,
        d.event,
        d.permanent,
    ]);
    const same = json === d.json;
    d.json = json;

    // A quiet refresh that changed nothing must not rebuild the page: it would wipe a half-typed score.
    if (sub === which && !(quiet && same)) {
        render();
    }
}

function apply(d, r, pick) {
    if (r.ok) {
        d.items = pick(r.value);
        d.phase = 'ready';
        d.error = null;
        d.stale = false;
    } else if (d.phase !== 'ready') {
        d.phase = 'error';
        d.error = r.error;
    } else {
        d.stale = true;
    }
}

function render() {
    clear(body);
    const d = data[sub];

    if (!eventSlug() && sub !== 'servers') {
        body.append(noEventState());

        return;
    }

    if (d.phase === 'loading') {
        body.append(skeleton(3));

        return;
    }

    if (d.phase === 'error') {
        body.append(errorState(d.error, () => load(sub)));

        return;
    }

    if (d.stale) {
        body.append(staleHint(() => load(sub)));
    }

    if (sub === 'matches') {
        renderMatches(d);
    } else if (sub === 'tournaments') {
        renderTournaments(d);
    } else {
        renderServers(d);
    }
}

function renderMatches(d) {
    if (!d.items.length) {
        body.append(emptyState(S.play.noMatches, S.play.noMatchesHint));

        return;
    }

    const groups = [
        [S.play.active, d.items.filter((m) => m.phase === 'active')],
        [S.play.upcoming, d.items.filter((m) => m.phase === 'upcoming')],
        [S.play.finished, d.items.filter((m) => m.phase === 'finished')],
    ];

    for (const [title, list] of groups) {
        if (!list.length) {
            continue;
        }

        body.append(
            el('div', { class: 'group-title', text: title }),
            ...list.map((m) =>
                matchCard(m, {
                    onChange: () => load('matches', { quiet: true }),
                }),
            ),
        );
    }
}

function renderTournaments(d) {
    if (!d.items.length) {
        body.append(emptyState(S.play.noTournaments, S.play.noTournamentsHint));

        return;
    }

    const mine = d.items.filter((t) => t.myEntry);
    const rest = d.items.filter((t) => !t.myEntry);

    for (const [title, list] of [
        [S.play.myTournaments, mine],
        [S.play.otherTournaments, rest],
    ]) {
        if (!list.length) {
            continue;
        }

        body.append(
            el('div', { class: 'group-title', text: title }),
            ...list.map((t) =>
                tournamentCard(t, {
                    onChange: () => load('tournaments', { quiet: true }),
                }),
            ),
        );
    }
}

function serverCard(s) {
    const offline = s.status === 'offline';
    const actions = serverActions(s, { joinLabel: S.servers.joinShort });

    return el(
        'article',
        {
            class: 'card server' + (offline ? ' offline' : ''),
            'aria-label': s.title,
        },
        el(
            'div',
            { class: 'row between wrap' },
            el('h3', { text: s.title }),
            s.game ? chip(s.game) : null,
        ),
        serverLine(s),
        s.matchLabel
            ? el('div', { class: 'small muted', text: s.matchLabel })
            : null,
        s.description
            ? el('div', { class: 'small', text: s.description })
            : null,
        s.copyText ? el('div', { class: 'addr mono', text: s.copyText }) : null,
        offline
            ? el('div', {
                  class: 'small muted',
                  text: s.checkedAt
                      ? S.servers.lastSeen(dayTime(s.checkedAt))
                      : S.servers.offlineNote,
              })
            : null,
        actions,
        installHint(s),
    );
}

function renderServers(d) {
    if (!d.event.length && !d.permanent.length) {
        body.append(emptyState(S.play.noServers, S.play.noServersHint));

        return;
    }

    if (d.event.length) {
        body.append(
            el('div', { class: 'group-title', text: S.play.eventServers }),
            ...d.event.map(serverCard),
        );
    }

    if (d.permanent.length) {
        body.append(
            el('div', { class: 'group-title', text: S.play.permanentServers }),
            ...d.permanent.map(serverCard),
        );
    }

    body.append(
        el(
            'div',
            { class: 'row' },
            button(S.reload, { kind: 'ghost', onclick: () => load('servers') }),
        ),
    );
}
