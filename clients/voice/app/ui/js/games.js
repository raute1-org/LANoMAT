// Spiele: one library of the games on this PC (Steam, Epic, GOG, Ubisoft) and the game recipes of
// the LAN, merged by the Rust side. Cards with one action, search and filters, a detail view.
// It works signed out: the local scan needs no server, the recipes' reason is one muted line.
import {
    cardModel,
    entryCardModel,
    filterEntries,
    FILTERS,
    genresOfEntries,
    loadDetail,
    loadLibrary,
    SOURCES,
    sortEntries,
    sourceLabel,
    storeStatusLabel,
} from './games-model.js';
import * as connect from './connect.js';
import { offlineMode, pageHeader } from './nav.js';
import { S } from './strings.js';
import {
    bytes,
    call,
    clear,
    dayTime,
    el,
    emptyState,
    errorState,
    say,
    skeleton,
    state,
    toast,
} from './util.js';

const G = S.games;

// `append` turns a null into the text "null": optional children (a missing note, an absent
// action) are dropped here.
const put = (parent, ...kids) =>
    parent.append(
        ...kids.filter((k) => k !== null && k !== undefined && k !== false),
    );
let root = null;
let listView = null;
let detailView = null;
let body = null;
let toolbar = null;
let search = null;
let genreSel = null;
let genreField = null;
let segBox = null;
let sourceBox = null;
let signIn = null;
let note = null;
let meta = null;
let lib = null; // result of loadLibrary
const filter = { query: '', genre: '', only: 'all', source: 'all' };
let lastKey = null;
let seq = 0;
const RELAUNCH_MS = 2500;

function seg(label, ids, labelOf, key) {
    const box = el('div', { class: 'seg', role: 'group', 'aria-label': label });

    for (const id of ids) {
        box.append(
            el('button', {
                class: 'seg-btn',
                type: 'button',
                'data-id': id,
                text: labelOf(id),
                'aria-pressed': String(id === filter[key]),
                onclick: () => {
                    filter[key] = id;
                    paintSeg();
                    paintList();
                },
            }),
        );
    }

    return box;
}

export function init(section) {
    root = section;
    search = el('input', {
        type: 'search',
        id: 'games-search',
        placeholder: G.searchPlaceholder,
        autocomplete: 'off',
        oninput: () => {
            filter.query = search.value;
            paintList();
        },
    });
    genreSel = el('select', {
        id: 'games-genre',
        onchange: () => {
            filter.genre = genreSel.value;
            paintList();
        },
    });
    genreField = el(
        'div',
        { class: 'field' },
        el('label', { for: 'games-genre', text: G.genre }),
        genreSel,
    );
    segBox = seg(G.sections, FILTERS, (f) => G.filter[f], 'only');
    sourceBox = seg(G.sources, SOURCES, sourceLabel, 'source');
    toolbar = el(
        'div',
        { class: 'games-tools' },
        el(
            'div',
            { class: 'field grow' },
            el('label', { for: 'games-search', text: G.search }),
            search,
        ),
        genreField,
        sourceBox,
        segBox,
    );
    note = el('p', { class: 'games-note small muted', role: 'status' });
    meta = el('div', { class: 'games-meta small muted', role: 'status' });
    body = el('div', { class: 'stack' });
    listView = el('div', { class: 'stack' }, toolbar, note, meta, body);
    detailView = el('div', { class: 'stack', hidden: true });
    // Signed out the way back to pairing is in the header, never hidden in a menu.
    signIn = el('button', {
        class: 'btn small-btn',
        type: 'button',
        text: G.signIn,
        hidden: true,
        onclick: () =>
            connect.open({
                url: state.status && state.status.server_url,
            }),
    });
    root.append(pageHeader('spiele', G.title, signIn), listView, detailView);
}

export async function show() {
    signIn.hidden = !offlineMode();
    closeDetail();
    // Opening the tab looks at the disk again: a game that finished updating, a new install or a
    // removed folder shows at once, not after "Neu einlesen" or a restart.
    await load({ rescan: true });
}

export function focus() {
    if (!detailView.hidden) {
        closeDetail();
    }
}

function paintSeg() {
    for (const [box, key] of [
        [segBox, 'only'],
        [sourceBox, 'source'],
    ]) {
        for (const b of box.children) {
            b.setAttribute(
                'aria-pressed',
                String(b.dataset.id === filter[key]),
            );
        }
    }
}

async function load({ rescan = false } = {}) {
    const mine = ++seq;
    toolbar.hidden = true;
    meta.hidden = true;
    note.hidden = true;
    clear(body);
    body.append(skeleton(4));
    body.setAttribute('aria-busy', 'true');
    lib = await loadLibrary(call, { rescan, offline: offlineMode() });

    if (mine !== seq) {
        return;
    }

    body.removeAttribute('aria-busy');
    paintLibrary();
}

const rescan = () => load({ rescan: true });

function paintLibrary() {
    clear(body);
    const ready = lib.phase === 'ready';
    toolbar.hidden = !ready;
    meta.hidden = !ready;
    paintNote();

    if (lib.phase === 'error') {
        body.append(
            errorState(
                { message: lib.error.text },
                lib.error.retryable ? load : null,
                lib.error.title,
            ),
        );
    } else if (lib.phase === 'disabled') {
        body.append(emptyState(lib.info.title, lib.info.hint));
    } else if (lib.phase === 'empty' && !lib.local) {
        // No scan on this platform: the PC's source lines would only say "nicht erkannt".
        body.append(emptyState(G.emptyLan.title, G.emptyLan.hint));
    } else if (lib.phase === 'empty') {
        body.append(
            emptyState(
                G.empty.title,
                G.empty.hint,
                el(
                    'div',
                    { class: 'stack-sm' },
                    el(
                        'ul',
                        { class: 'plain-list small muted' },
                        ...lib.sources.map((x) =>
                            el('li', {
                                text: G.sourceState(
                                    sourceLabel(x.store),
                                    x.present,
                                    x.found,
                                    x.skipped,
                                ),
                            }),
                        ),
                    ),
                    el('button', {
                        class: 'btn small-btn align-start',
                        type: 'button',
                        text: G.actions.scan,
                        onclick: rescan,
                    }),
                ),
            ),
        );
    } else {
        fillGenres();
        fillSources();
        paintList();
    }
}

// One muted line above the list: the recipes are missing, the local games are not.
function paintNote() {
    const text = lib && lib.recipesNote;
    note.hidden = !text || lib.phase === 'disabled' || lib.phase === 'error';
    note.textContent = text || '';
}

function fillGenres() {
    const keep = filter.genre;
    const genres = genresOfEntries(lib.entries);
    clear(genreSel);
    genreSel.append(el('option', { value: '', text: G.allGenres }));

    for (const g of genres) {
        genreSel.append(el('option', { value: g, text: g }));
    }

    genreSel.value = genres.includes(keep) ? keep : '';
    filter.genre = genreSel.value;
    genreField.hidden = !genres.length;
    segBox.hidden = !lib.entries.some((e) => e.recipe);
}

// A source chip only exists when it would show something.
function fillSources() {
    const has = (id) =>
        id === 'all' ||
        (id === 'lan'
            ? lib.entries.some((e) => e.recipe)
            : lib.entries.some((e) =>
                  e.storeGames.some((g) => g.store === id),
              ));

    for (const b of sourceBox.children) {
        b.hidden = !has(b.dataset.id);
    }

    if (!has(filter.source)) {
        filter.source = 'all';
    }

    sourceBox.hidden =
        [...sourceBox.children].filter((b) => !b.hidden).length < 3;
    paintSeg();
}

function paintList() {
    if (!lib || lib.phase !== 'ready') {
        return;
    }

    const shown = sortEntries(filterEntries(lib.entries, filter));
    clear(body);
    meta.textContent = '';
    put(
        meta,
        el('span', { text: G.count(shown.length, lib.entries.length) }),
        lib.scannedAt
            ? el('span', {
                  text: ` · ${G.scannedAt(dayTime(lib.scannedAt))}`,
              })
            : null,
        el('button', {
            class: 'btn ghost small-btn',
            type: 'button',
            text: G.actions.scan,
            onclick: rescan,
        }),
    );

    if (!shown.length) {
        body.append(
            emptyState(
                G.noMatch.title,
                G.noMatch.hint,
                el('button', {
                    class: 'btn small-btn',
                    type: 'button',
                    text: G.noMatch.reset,
                    onclick: () => {
                        filter.query = '';
                        filter.genre = '';
                        filter.only = 'all';
                        filter.source = 'all';
                        search.value = '';
                        genreSel.value = '';
                        paintSeg();
                        paintList();
                    },
                }),
            ),
        );

        return;
    }

    body.append(
        el(
            'ul',
            { class: 'games-grid', 'aria-label': G.title },
            ...shown.map((e) => el('li', {}, card(e))),
        ),
    );
}

const tag = (label, tone = '') =>
    el(
        'span',
        { class: `tag ${tone}`.trim() },
        el('span', { class: `dot ${tone}`.trim(), 'aria-hidden': 'true' }),
        label,
    );

const size = (n) => (n ? bytes(n) : null);

function cover(url, cls) {
    return url
        ? el('img', {
              class: cls,
              src: url,
              alt: '',
              width: 96,
              height: 54,
              loading: 'lazy',
              decoding: 'async',
          })
        : null;
}

// The one action of a card. "Spielen" is the amber one; everything else stays quiet.
function action(m) {
    const p = m.primary;

    if (!p) {
        return null;
    }

    // Installing from the app is not there yet: no dead button on the card, the detail says why.
    if (p.id === 'install_recipe') {
        return null;
    }

    const play = p.id === 'play';

    return el('button', {
        class: play ? 'btn primary small-btn' : 'btn small-btn',
        type: 'button',
        text: p.label,
        'aria-label': `${p.label}: ${m.title}`,
        onclick: async (e) => {
            const b = e.currentTarget;
            b.disabled = true;
            const r = play
                ? await call('library_launch', { key: m.launchKey })
                : await call('library_open_store', { key: m.key });

            if (r.ok) {
                say(`${p.label}: ${m.title}`);
                // The handoff returns at once while the game takes seconds to appear: a second
                // click in that time would start it twice.
                setTimeout(() => (b.disabled = false), RELAUNCH_MS);

                return;
            }

            b.disabled = false;
            toast(
                r.error.message ||
                    (play ? G.actions.failed : G.actions.storeFailed),
                'error',
            );
        },
    });
}

function card(entry) {
    const m = entryCardModel(entry);
    const act = action(m);
    const r = m.recipe;
    const sz = r && size(r.sizeBytes);
    const facts = r ? [r.genre, r.players, r.platforms].filter(Boolean) : [];

    return el(
        'article',
        { class: 'card game' },
        el(
            'button',
            {
                class: 'game-open',
                type: 'button',
                'data-key': m.key,
                'aria-label': G.open(m.title),
                onclick: () => openDetail(entry),
            },
            cover(r && r.coverUrl, 'game-cover'),
            el(
                'span',
                { class: 'game-body' },
                el('span', { class: 'game-title', text: m.title }),
                m.inLineup
                    ? el('span', { class: 'game-lineup', text: G.lineup })
                    : null,
                m.badges.length
                    ? el(
                          'span',
                          { class: 'game-stores' },
                          ...m.badges.map((b) =>
                              el('span', {
                                  class: `store-chip store-${b.store}`,
                                  text: b.label,
                              }),
                          ),
                      )
                    : null,
                r && r.summary
                    ? el('span', { class: 'game-summary', text: r.summary })
                    : null,
                facts.length
                    ? el('span', {
                          class: 'game-facts small muted',
                          text: facts.join(' · '),
                      })
                    : null,
                el(
                    'span',
                    { class: 'game-state' },
                    m.status ? tag(m.status.label, m.status.tone) : null,
                    r ? tag(r.availability.label, r.availability.tone) : null,
                    sz
                        ? el('span', { class: 'mono small muted', text: sz })
                        : null,
                ),
            ),
        ),
        act ? el('div', { class: 'game-actions' }, act) : null,
    );
}

// ---- detail ----

function closeDetail() {
    seq++;
    detailView.hidden = true;
    listView.hidden = false;
    clear(detailView);
}

function backToList() {
    closeDetail();
    const c =
        lastKey && body.querySelector(`[data-key="${CSS.escape(lastKey)}"]`);

    if (c) {
        c.focus();
    } else {
        search.focus();
    }
}

async function openDetail(entry) {
    const mine = ++seq;
    lastKey = entry.key;
    listView.hidden = true;
    detailView.hidden = false;
    clear(detailView);
    scrollTop();

    if (!entry.recipe) {
        put(detailView, backButton(), ...storeOnlyBody(entry));
        say(entry.title);
        focusHeading();

        return;
    }

    detailView.append(backButton(), skeleton(3));
    const d = await loadDetail(call, entry.recipe.slug);

    if (mine !== seq) {
        return;
    }

    clear(detailView);
    detailView.append(backButton());

    if (d.phase === 'error') {
        detailView.append(
            errorState(
                { message: d.error.text },
                d.error.retryable ? () => openDetail(entry) : null,
                G.errors.detailTitle,
            ),
        );
    } else {
        put(
            detailView,
            ...detailBody(d, entryCardModel(entry)),
            storeSection(entry),
        );
        say(d.recipe.title);
    }

    focusHeading();
}

const backButton = () =>
    el('button', {
        class: 'btn ghost small-btn align-start',
        type: 'button',
        text: G.detail.back,
        onclick: backToList,
    });

function scrollTop() {
    window.scrollTo({ top: 0 });
}

function focusHeading() {
    const h = detailView.querySelector('h2');

    if (h) {
        h.tabIndex = -1;
        h.focus();
    }
}

// The installed copies of a game, one block per store, the folder in mono.
function storeSection(entry) {
    if (!entry.storeGames.length) {
        return null;
    }

    const D = G.storeDetail;

    return el(
        'section',
        { class: 'stack-sm', 'aria-labelledby': 'g-stores' },
        el('h3', { id: 'g-stores', text: D.about }),
        ...entry.storeGames.map((g) =>
            el(
                'dl',
                { class: 'facts' },
                fact(D.store, sourceLabel(g.store)),
                fact(D.status, storeStatusLabel(g.status)),
                fact(D.path, g.installPath, true),
            ),
        ),
    );
}

function storeOnlyBody(entry) {
    const m = entryCardModel(entry);
    const act = action(m);

    return [
        el(
            'header',
            { class: 'stack-sm' },
            el('h2', { text: entry.title }),
            el(
                'div',
                { class: 'game-state' },
                m.status ? tag(m.status.label, m.status.tone) : null,
            ),
        ),
        lib && lib.recipesNote
            ? null
            : el('p', { class: 'game-about', text: G.storeDetail.note }),
        storeSection(entry),
        act ? el('div', { class: 'game-actions' }, act) : null,
    ];
}

function fact(label, value, mono = false) {
    return value
        ? el(
              'div',
              { class: 'fact' },
              el('dt', { text: label }),
              el('dd', { class: mono ? 'mono' : '', text: value }),
          )
        : null;
}

function detailBody({ recipe: r, plan }, entryModel) {
    const m = cardModel(r);
    const act = action(entryModel);
    const D = G.detail;
    const yesNo = (v, yes, no) => (v === true ? yes : v === false ? no : '');
    const sz = size(r.sizeBytes);

    return [
        el(
            'header',
            { class: 'stack-sm' },
            el('h2', { text: r.title }),
            el(
                'div',
                { class: 'game-state' },
                r.inLineup ? tag(G.lineup, 'live') : null,
                tag(m.availability.label, m.availability.tone),
                tag(m.local.label, m.local.tone),
            ),
        ),
        // The same one action as on the card, so a matched game can be started from here too.
        act ? el('div', { class: 'game-actions' }, act) : null,
        r.description || r.summary
            ? el('p', { class: 'game-about', text: r.description || r.summary })
            : null,
        el(
            'section',
            { class: 'stack-sm', 'aria-labelledby': 'g-facts' },
            el('h3', { id: 'g-facts', text: D.facts }),
            el(
                'dl',
                { class: 'facts' },
                fact(D.genre, r.genre),
                fact(D.players, m.players),
                fact(D.platforms, m.platforms),
                fact(D.size, sz || G.sizeUnknown, Boolean(sz)),
                fact(
                    D.multiplayer,
                    yesNo(
                        r.supportsMultiplayer,
                        D.multiplayerYes,
                        D.multiplayerNo,
                    ),
                ),
                fact(
                    D.portable,
                    yesNo(r.portable, D.portableYes, D.portableNo),
                ),
                fact(D.license, r.license),
                fact(D.aliases, r.aliases.join(', ')),
                fact(D.path, r.local.path, true),
                r.local.lastPlayedAt
                    ? fact(D.lastPlayed, dayTime(r.local.lastPlayedAt))
                    : null,
            ),
            r.homepage
                ? el('p', {
                      class: 'small muted',
                      text: `${D.homepage}: ${r.homepage}`,
                  })
                : null,
        ),
        planSection(plan, m),
        // Only while there is no installed copy to start: the placeholder says why not yet.
        entryModel.launchKey ? null : installNote(),
    ];
}

function planSection(plan, m) {
    const D = G.detail;

    if (!plan) {
        return el(
            'section',
            { class: 'stack-sm', 'aria-labelledby': 'g-plan' },
            el('h3', { id: 'g-plan', text: D.plan }),
            emptyState(D.noPlan.title, D.noPlan.hint),
        );
    }

    const gone = plan.files.filter((f) => f.gone).length || plan.missing.length;

    return el(
        'section',
        { class: 'stack-sm', 'aria-labelledby': 'g-plan' },
        el('h3', { id: 'g-plan', text: D.plan }),
        plan.version !== null
            ? el(
                  'p',
                  { class: 'small muted' },
                  el('span', {
                      class: 'mono',
                      text: D.planVersion(plan.version),
                  }),
              )
            : null,
        gone || m.availability.id === 'missing'
            ? el(
                  'div',
                  { class: 'hint warn stack-sm', role: 'status' },
                  el('strong', { text: D.missingTitle }),
                  plan.missing.length
                      ? el(
                            'ul',
                            { class: 'plain-list' },
                            ...plan.missing.map((x) => el('li', { text: x })),
                        )
                      : null,
                  el('span', { text: D.missingHint }),
              )
            : null,
        plan.files.length
            ? el(
                  'ul',
                  { class: 'plan-files', 'aria-label': D.files },
                  ...plan.files.map(fileRow),
              )
            : null,
    );
}

function fileRow(f) {
    const D = G.detail;
    const sz = size(f.sizeBytes);

    return el(
        'li',
        { class: `plan-file${f.gone ? ' gone' : ''}` },
        el('span', { class: 'plan-file-name', text: f.label }),
        el(
            'span',
            { class: 'plan-file-meta small' },
            f.required ? D.required : D.optional,
            f.sourceLabel ? ` · ${f.sourceLabel}` : '',
            f.statusLabel
                ? tag(f.statusLabel, f.gone ? 'down' : f.status ? 'ok' : '')
                : null,
            sz ? el('span', { class: 'mono muted', text: sz }) : null,
        ),
    );
}

// The one place that will become "Installieren" and "Starten": for now an explained, disabled
// placeholder. aria-disabled keeps it reachable by keyboard so the reason is read out.
function installNote() {
    const D = G.detail;

    return el(
        'div',
        { class: 'install-note stack-sm' },
        el('button', {
            class: 'btn',
            type: 'button',
            text: D.installSoon,
            'aria-disabled': 'true',
            'aria-describedby': 'g-install-why',
            onclick: (e) => e.preventDefault(),
        }),
        el('p', {
            id: 'g-install-why',
            class: 'small muted',
            text: D.installSoonWhy,
        }),
    );
}
