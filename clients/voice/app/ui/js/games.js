// Spiele: the library of game recipes, read only. Cards with availability on the LAN host and the
// local state, search and filters, and a detail view with the plan summary. Installing and
// starting arrive in a later phase: the detail only says so.
import {
    cardModel,
    filterRecipes,
    FILTERS,
    genresOf,
    loadDetail,
    loadLibrary,
    sortRecipes,
} from './games-model.js';
import { pageHeader } from './nav.js';
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
} from './util.js';

const G = S.games;
let root = null;
let listView = null;
let detailView = null;
let body = null;
let toolbar = null;
let search = null;
let genreSel = null;
let segBox = null;
let meta = null;
let lib = null; // result of loadLibrary
const filter = { query: '', genre: '', only: 'all' };
let lastSlug = null;
let seq = 0;

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
    segBox = el('div', {
        class: 'seg',
        role: 'group',
        'aria-label': G.sections,
    });

    for (const f of FILTERS) {
        segBox.append(
            el('button', {
                class: 'seg-btn',
                type: 'button',
                'data-filter': f,
                text: G.filter[f],
                'aria-pressed': String(f === filter.only),
                onclick: () => {
                    filter.only = f;
                    paintSeg();
                    paintList();
                },
            }),
        );
    }

    toolbar = el(
        'div',
        { class: 'games-tools' },
        el(
            'div',
            { class: 'field grow' },
            el('label', { for: 'games-search', text: G.search }),
            search,
        ),
        el(
            'div',
            { class: 'field' },
            el('label', { for: 'games-genre', text: G.genre }),
            genreSel,
        ),
        segBox,
    );
    meta = el('div', { class: 'games-meta small muted', role: 'status' });
    body = el('div', { class: 'stack' });
    listView = el('div', { class: 'stack' }, toolbar, meta, body);
    detailView = el('div', { class: 'stack', hidden: true });
    root.append(pageHeader('spiele', G.title), listView, detailView);
}

export async function show() {
    closeDetail();
    await load();
}

export function focus() {
    if (!detailView.hidden) {
        closeDetail();
    }
}

function paintSeg() {
    for (const b of segBox.children) {
        b.setAttribute(
            'aria-pressed',
            String(b.dataset.filter === filter.only),
        );
    }
}

async function load() {
    const mine = ++seq;
    toolbar.hidden = true;
    meta.hidden = true;
    clear(body);
    body.append(skeleton(4));
    body.setAttribute('aria-busy', 'true');
    lib = await loadLibrary(call);

    if (mine !== seq) {
        return;
    }

    body.removeAttribute('aria-busy');
    paintLibrary();
}

function paintLibrary() {
    clear(body);
    toolbar.hidden = lib.phase !== 'ready';
    meta.hidden = lib.phase !== 'ready';

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
    } else if (lib.phase === 'empty') {
        body.append(emptyState(G.empty.title, G.empty.hint));
    } else {
        fillGenres();
        paintList();
    }
}

function fillGenres() {
    const keep = filter.genre;
    clear(genreSel);
    genreSel.append(el('option', { value: '', text: G.allGenres }));

    for (const g of genresOf(lib.recipes)) {
        genreSel.append(el('option', { value: g, text: g }));
    }

    genreSel.value = [...genreSel.options].some((o) => o.value === keep)
        ? keep
        : '';
    filter.genre = genreSel.value;
}

function paintList() {
    if (!lib || lib.phase !== 'ready') {
        return;
    }

    const shown = sortRecipes(filterRecipes(lib.recipes, filter));
    clear(body);
    meta.textContent = '';
    meta.append(
        el('span', { text: G.count(shown.length, lib.recipes.length) }),
        lib.fetchedAt
            ? el('span', {
                  text: ` · ${G.fetchedAt(dayTime(lib.fetchedAt))}`,
              })
            : null,
    );
    meta.append(
        el('button', {
            class: 'btn ghost small-btn',
            type: 'button',
            text: G.refresh,
            onclick: load,
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
            ...shown.map((r) => el('li', {}, card(cardModel(r)))),
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

function cover(m, cls) {
    return m.coverUrl
        ? el('img', {
              class: cls,
              src: m.coverUrl,
              alt: '',
              width: 96,
              height: 54,
              loading: 'lazy',
              decoding: 'async',
          })
        : null;
}

function card(m) {
    const sz = size(m.sizeBytes);

    return el(
        'button',
        {
            class: 'card game',
            type: 'button',
            'data-slug': m.slug,
            'aria-label': G.open(m.title),
            onclick: () => openDetail(m.slug),
        },
        cover(m, 'game-cover'),
        el(
            'span',
            { class: 'game-body' },
            el('span', { class: 'game-title', text: m.title }),
            m.inLineup
                ? el('span', { class: 'game-lineup', text: G.lineup })
                : null,
            m.summary
                ? el('span', { class: 'game-summary', text: m.summary })
                : null,
            el('span', {
                class: 'game-facts small muted',
                text: [m.genre, m.players, m.platforms]
                    .filter(Boolean)
                    .join(' · '),
            }),
            el(
                'span',
                { class: 'game-state' },
                tag(m.availability.label, m.availability.tone),
                tag(m.local.label, m.local.tone),
                sz
                    ? el('span', { class: 'mono small muted', text: sz })
                    : el('span', { class: 'small muted', text: G.sizeUnknown }),
            ),
            m.lastPlayedAt
                ? el('span', {
                      class: 'small muted',
                      text: G.lastPlayed(dayTime(m.lastPlayedAt)),
                  })
                : null,
        ),
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
        lastSlug && body.querySelector(`[data-slug="${CSS.escape(lastSlug)}"]`);

    if (c) {
        c.focus();
    } else {
        search.focus();
    }
}

async function openDetail(slug) {
    const mine = ++seq;
    lastSlug = slug;
    listView.hidden = true;
    detailView.hidden = false;
    clear(detailView);
    detailView.append(backButton(), skeleton(3));
    scrollTop();
    const d = await loadDetail(call, slug);

    if (mine !== seq) {
        return;
    }

    clear(detailView);
    detailView.append(backButton());

    if (d.phase === 'error') {
        detailView.append(
            errorState(
                { message: d.error.text },
                d.error.retryable ? () => openDetail(slug) : null,
                G.errors.detailTitle,
            ),
        );
    } else {
        detailView.append(...detailBody(d));
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

function detailBody({ recipe: r, plan }) {
    const m = cardModel(r);
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
        installNote(),
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
