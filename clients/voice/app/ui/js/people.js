// Wo ist wer: the people of the event who share their location (the "Personen" view of
// Sprache), the compact team strip on Jetzt, and Hinterherjoinen: every place of a person has its own Beitreten
// button, which takes you to where the person is, in voice and in the game. What a person shows is exactly what the API gives: a room
// or server you may not see arrives without details and stays without them.
import { go } from './nav.js';
import { copyText, eventSlug, joinServer, avatar } from './shared.js';
import { S } from './strings.js';
import {
    button,
    call,
    chip,
    clear,
    dot,
    el,
    emptyState,
    errorState,
    plainMessage,
    relative,
    skeleton,
    staleHint,
    toast,
} from './util.js';
import { joinRoom } from './voice.js';

const REFRESH_MS = 30000;

const providerName = (p) => S.people.providers[p] || p || '';

const ago = (iso) => {
    const t = new Date(iso).getTime();

    return Number.isNaN(t)
        ? ''
        : S.people.ago(Math.max(0, Math.round((Date.now() - t) / 60000)));
};

// ---- where somebody is ----

// The per-place button: pushed to the right of its line, disabled for a place you may not see.
function joinButton(p, joinable, run) {
    const btn = el('button', {
        class: `btn small-btn ${joinable ? 'primary' : ''} where-join`.trim(),
        type: 'button',
        text: S.people.join,
        'aria-label': S.people.joinAria(p.name),
        disabled: !joinable,
        title: joinable ? '' : S.people.cannotJoin,
        onclick: () => run(btn),
    });

    return btn;
}

// One line per place: the provider as a chip, the room or game as plain words.
function whereLines(p, onJoin) {
    const lines = [];

    for (const v of p.voice || []) {
        const bits = [
            el('span', {
                class: 'strong',
                text: v.roomLabel || S.people.inVoice,
            }),
        ];

        if (v.since) {
            bits.push(el('span', { class: 'muted', text: relative(v.since) }));
        }

        lines.push(
            el(
                'div',
                { class: 'where' },
                dot('ok'),
                chip(providerName(v.provider)),
                ...bits,
                onJoin
                    ? joinButton(p, Boolean(v.roomRef), (btn) =>
                          onJoin({ kind: 'voice', provider: v.provider }, btn),
                      )
                    : null,
            ),
        );
    }

    if (p.game) {
        const g = p.game;
        const what = [g.game];

        if (g.serverTitle) {
            what.push(g.serverTitle);
        }

        lines.push(
            el(
                'div',
                { class: 'where' },
                dot('ok'),
                chip(S.people.game),
                el('span', { class: 'strong', text: what.join(' · ') }),
                g.since
                    ? el('span', { class: 'muted', text: relative(g.since) })
                    : null,
                onJoin
                    ? joinButton(p, Boolean(g.joinUrl || g.address), (btn) =>
                          onJoin({ kind: 'game' }, btn),
                      )
                    : null,
            ),
        );
    }

    return lines;
}

const hasPlace = (p) => (p.voice || []).length > 0 || Boolean(p.game);

// ---- Hinterherjoinen ----

async function openVoiceLink(v) {
    const url = v.join && v.join.url;

    if (!url) {
        return;
    }

    const name = providerName(v.provider);

    // Discord never opens from here: its channel link only goes to the clipboard.
    if (v.provider !== 'discord') {
        const r = await call('open_join_url', { url });

        if (r.ok) {
            toast(S.people.voiceOpened(name));

            return;
        }

        if (r.error.kind !== 'local' && r.error.kind !== 'usage') {
            toast(plainMessage(r.error), 'error');

            return;
        }

        const c = await call('copy_text', { text: url });
        toast(
            c.ok ? S.people.voiceCopied(name) : plainMessage(c.error),
            c.ok ? 'info' : 'error',
        );

        return;
    }

    await copyText(url, S.people.linkCopied);
}

// The things a person can be followed to, from the follow answer.
function targets(f) {
    const voice = [];

    for (const v of f.voice || []) {
        const room = v.join && v.join.roomId;
        const url = v.join && v.join.url;

        if (!v.joinable || !v.join || (!room && !url)) {
            voice.push({ v, run: null });

            continue;
        }

        voice.push({
            v,
            run: room
                ? () => joinRoom(room, { toTab: true })
                : () => openVoiceLink(v),
        });
    }

    const g = f.game;
    const game =
        g && (g.joinUrl || g.copyText)
            ? {
                  g,
                  join: g.joinUrl
                      ? () =>
                            joinServer({
                                joinUrl: g.joinUrl,
                                copyText: g.copyText,
                                title: g.serverTitle || g.game,
                            })
                      : null,
                  copy: g.copyText
                      ? () => copyText(g.copyText, S.servers.addressCopied)
                      : null,
              }
            : null;

    return { voice, game };
}

// Asks where the person is now, then does the one thing the clicked place asks for: enter that
// voice room (by provider) or join / copy that game server. Errors show inside the row.
async function joinPlace(person, place, host, btn) {
    const slug = eventSlug();

    if (!slug) {
        return;
    }

    clear(host);
    btn.disabled = true;
    const label = btn.textContent;
    btn.textContent = S.people.following;
    const r = await call('follow_person', { event: slug, userId: person.id });
    btn.disabled = false;
    btn.textContent = label;

    if (!r.ok) {
        host.append(
            el('div', {
                class: 'inline-error',
                role: 'alert',
                text: plainMessage(r.error),
            }),
        );

        return;
    }

    const t = targets(r.value);
    const run =
        place.kind === 'game'
            ? t.game && (t.game.join || t.game.copy)
            : (t.voice.find((x) => x.v.provider === place.provider) || {}).run;

    if (!run) {
        host.append(
            el('div', {
                class: 'small muted',
                role: 'status',
                text:
                    place.kind === 'game'
                        ? S.people.serverHidden
                        : S.people.notJoinable,
            }),
        );

        return;
    }

    await run();
}

// ---- one person ----

function personRow(p, { compact = false, hiddenSelf = false } = {}) {
    const host = el('div', { class: 'follow-host' });
    const lines = whereLines(
        p,
        !p.isMe ? (place, btn) => joinPlace(p, place, host, btn) : null,
    );
    const chips = [];

    // A teammate's team label carries the one accent; without a label a plain "Team" does.
    if (p.team) {
        chips.push(chip(p.team, !p.isMe && p.isTeammate ? 'live' : ''));
    } else if (!p.isMe && p.isTeammate) {
        chips.push(chip(S.people.teammate, 'live'));
    }

    if (!p.isMe && p.isFriend) {
        chips.push(chip(S.people.friend));
    }

    const nowhere = [];

    if (!lines.length) {
        if (p.isMe && hiddenSelf) {
            nowhere.push(
                el('div', { class: 'small muted' }, S.people.hiddenHint),
            );
        } else {
            nowhere.push(
                el('div', {
                    class: 'small muted',
                    text: p.lastSeenAt
                        ? `${S.people.nowhere} ${S.people.lastSeen(ago(p.lastSeenAt))}`
                        : S.people.nowhere,
                }),
            );
        }
    }

    const action = hiddenSelf
        ? button(S.people.toProfile, {
              kind: 'small-btn',
              onclick: () => go('mehr', { card: 'profile', force: true }),
          })
        : null;

    return el(
        'li',
        {
            class: `pr stacked${p.isMe ? ' self' : ''}${compact ? ' compact' : ''}`,
        },
        el(
            'div',
            { class: 'row wrap fill' },
            avatar(p.name, {
                me: p.isMe,
                small: compact,
                src: p.avatarUrl,
            }),
            el(
                'div',
                { class: 'grow' },
                el(
                    'div',
                    { class: 'row wrap' },
                    el('span', {
                        class: 'name',
                        text: p.isMe ? `${p.name} (${S.people.you})` : p.name,
                    }),
                    p.isMe && hiddenSelf ? chip(S.people.hidden, 'warn') : null,
                    ...chips,
                ),
                ...lines,
                ...nowhere,
            ),
            action ? el('div', { class: 'actions' }, action) : null,
        ),
        host,
    );
}

// ---- the Personen view ----

let viewTimer = null;
let viewLive = false;

export function unmount() {
    viewLive = false;
    clearTimeout(viewTimer);
}

export function mount(box, { filter = 'all' } = {}) {
    viewLive = true;
    let mode = ['all', 'team', 'friends'].includes(filter) ? filter : 'all';
    let q = '';
    let data = null; // {me, data}
    let loadError = null;
    let loading = true;
    let sharing = true; // the profile's switch; false only when it says so
    let debounce = null;
    const list = el('div', { class: 'stack-sm' });
    const searchBox = el('input', {
        type: 'text',
        id: 'people-search',
        placeholder: S.people.searchPlaceholder,
        'aria-label': S.people.search,
        autocomplete: 'off',
        spellcheck: 'false',
    });
    searchBox.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            q = searchBox.value.trim();
            load();
        }, 300);
    });
    const seg = el('div', {
        class: 'seg',
        role: 'group',
        'aria-label': S.people.filters,
    });
    const paintSeg = () => {
        clear(seg);

        for (const [id, text] of [
            ['all', S.people.all],
            ['team', S.people.team],
            ['friends', S.people.friends],
        ]) {
            seg.append(
                el('button', {
                    class: 'seg-btn',
                    type: 'button',
                    text,
                    'aria-pressed': String(mode === id),
                    onclick: () => {
                        mode = id;
                        paintSeg();
                        load();
                    },
                }),
            );
        }
    };
    paintSeg();
    box.append(
        el(
            'div',
            { class: 'stack' },
            el('p', { class: 'small muted', text: S.people.hint }),
            el(
                'div',
                { class: 'row between wrap' },
                seg,
                el(
                    'div',
                    { class: 'row' },
                    searchBox,
                    button(S.reload, { onclick: () => load() }),
                ),
            ),
            list,
        ),
    );

    function paint() {
        clear(list);

        if (loading && !data) {
            list.append(skeleton(3));

            return;
        }

        if (loadError && !data) {
            list.append(errorState(loadError, () => load(), S.people.failed));

            return;
        }

        if (loadError) {
            list.append(staleHint(() => load()));
        }

        const rows = [];

        if (data.me) {
            rows.push(personRow(data.me, { hiddenSelf: !sharing }));
        }

        for (const p of data.data) {
            rows.push(personRow(p));
        }

        if (!data.data.length) {
            const filtered = mode !== 'all' || q !== '';
            const [title, hint] = !filtered
                ? [S.people.none, S.people.noneHint]
                : q !== ''
                  ? [S.people.noMatch, S.people.noMatchHint]
                  : mode === 'team'
                    ? [S.people.noTeam, S.people.noTeamHint]
                    : [S.people.noFriends, S.people.noFriendsHint];
            list.append(
                el('ul', { class: 'rows' }, ...rows),
                emptyState(title, hint),
            );

            return;
        }

        list.append(el('ul', { class: 'rows' }, ...rows));
    }

    async function load() {
        const slug = eventSlug();

        if (!slug) {
            return;
        }

        clearTimeout(viewTimer);
        loading = true;

        if (!data) {
            paint();
        }

        const [r, prof] = await Promise.all([
            call('list_people', {
                event: slug,
                team: mode === 'team',
                friends: mode === 'friends',
                q: q || null,
            }),
            call('get_profile'),
        ]);

        if (!viewLive) {
            return;
        }

        loading = false;

        if (prof.ok && typeof prof.value.share_location === 'boolean') {
            sharing = prof.value.share_location;
        }

        if (r.ok) {
            data = { me: r.value.me, data: r.value.data || [] };
            loadError = null;
        } else {
            loadError = r.error;
        }

        paint();
        viewTimer = setTimeout(() => viewLive && load(), REFRESH_MS);
    }

    paint();
    load();
}

// ---- the team strip on Jetzt ----

const strip = el('section', {
    class: 'team-strip',
    'aria-label': S.people.stripAria,
    hidden: true,
});
const STRIP_MAX = 3;

export const teamStrip = () => strip;

export async function refreshTeam(enabled) {
    const slug = eventSlug();

    if (!enabled || !slug) {
        strip.hidden = true;
        clear(strip);

        return;
    }

    const r = await call('list_people', { event: slug, team: true });

    // The strip is a bonus: when the list is not there, it is not there.
    if (!r.ok) {
        strip.hidden = true;
        clear(strip);

        return;
    }

    const where = (r.value.data || []).filter(
        (p) => p.isTeammate && hasPlace(p),
    );

    // Do not rebuild while a join answer (an error) is showing in a row.
    if (strip.querySelector('.follow-host:not(:empty)')) {
        return;
    }

    clear(strip);
    strip.hidden = where.length === 0;

    if (!where.length) {
        return;
    }

    strip.append(
        el(
            'div',
            { class: 'row between' },
            el('h2', { text: S.people.stripTitle }),
            button(S.people.stripAll, {
                kind: 'ghost',
                onclick: () =>
                    go('sprache', {
                        view: 'people',
                        filter: 'team',
                        force: true,
                    }),
            }),
        ),
        el(
            'ul',
            { class: 'rows' },
            ...where
                .slice(0, STRIP_MAX)
                .map((p) => personRow(p, { compact: true })),
        ),
    );
}
