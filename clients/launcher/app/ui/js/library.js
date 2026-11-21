// Bibliothek: two lists that must never depend on each other.
//
//  * "Meine Bibliothek" — this terminal's own installs, straight from the local
//    `get_status.installs` ledger. Every machine pairs on its own and installs
//    locally, so this list renders (and starts) while the server is unreachable:
//    `launch` never calls the network.
//  * "Spiele vom Server" — the shared event catalogue from `list_games`. Every
//    published recipe is listed; only the recipes this launcher build can
//    install get a primary Install button. The rest say "Bald im Launcher" and
//    link to the game's `/games/{slug}` page — never a dead Install button.
import * as install from './install.js';
import * as ready from './ready.js';
import { state } from './state.js';
import { GAMES_PATH, S } from './strings.js';
import {
    $,
    announce,
    call,
    el,
    httpStatus,
    isAuthError,
    serverBase,
    setHidden,
    setText,
} from './util.js';

let loading = false;
let onStatus = () => {};
let onSignedOut = () => {};

// The ledger says "installed"; the disk is the truth. One local check per
// recorded game names the quiet action ("Reparieren" vs "Neu installieren") and
// the record's state without downloading anything (shell `verify_install`).
const installChecks = new Map();

async function refreshChecks() {
    const records = Array.isArray(state.status?.installs)
        ? state.status.installs
        : [];
    const slugs = [
        ...new Set(records.map((record) => record?.slug).filter(Boolean)),
    ];

    installChecks.clear();

    await Promise.all(
        slugs.map(async (slug) => {
            const result = await call('verify_install', { slug });

            if (!result.ok || !result.value) {
                return;
            }

            installChecks.set(slug, {
                ok: result.value.ok === true,
                exists: result.value.exists !== false,
                missing: Array.isArray(result.value.missing)
                    ? result.value.missing
                    : [],
            });
        }),
    );
}

// A present but incomplete install is repaired; a gone folder (or an unknown
// check, e.g. an older shell) is reinstalled. Either way the install command is
// the same: it resumes downloads and re-extracts idempotently.
function needsRepair(check) {
    return Boolean(check && !check.ok && check.exists);
}

function checkLabel(check) {
    if (!check) {
        return '';
    }

    if (check.ok) {
        return S.library.stateReady;
    }

    return check.exists ? S.library.stateRepair : S.library.stateMissing;
}

function installTarget(record, title) {
    return (
        (state.games || []).find((game) => game && game.slug === record.slug) || {
            slug: record.slug,
            title,
            version: record.version,
        }
    );
}

export function init({ status, signedOut }) {
    onStatus = status;
    onSignedOut = signedOut;

    $('library-refresh').addEventListener('click', () => load());
    $('library-empty-retry').addEventListener('click', () => load());
    $('library-retry').addEventListener('click', () => load());
    $('library-reconnect').addEventListener('click', () => onSignedOut());
}

export async function load() {
    if (loading) {
        return;
    }

    loading = true;
    const firstLoad = state.games.length === 0;

    setLoading(true);
    setHidden('library-error', true);

    if (firstLoad) {
        setHidden('library-empty', true);
    }

    const [statusResult, gamesResult] = await Promise.all([
        call('get_status'),
        call('list_games'),
    ]);

    loading = false;
    setLoading(false);

    if (!statusResult.ok) {
        fail(statusResult.error);

        return;
    }

    const status = statusResult.value || {};

    onStatus(status);

    if (!status.paired) {
        onSignedOut();

        return;
    }

    // The catalogue's titles feed the local tiles; take them when the server
    // answered, but never make the local library depend on it.
    if (gamesResult.ok) {
        state.games = Array.isArray(gamesResult.value) ? gamesResult.value : [];
    }

    // The local library needs no server answer: paint it before the catalogue
    // is judged, so an offline server never hides what is already installed.
    // The check is local, so it works in the same offline case.
    await refreshChecks();
    paintInstalled();

    if (!gamesResult.ok) {
        fail(gamesResult.error);

        return;
    }

    setHidden('library-loading', true);
    paint();

    if (state.stage === 'library') {
        announce(S.library.loaded(state.games.length));
    }
}

function setLoading(value) {
    const button = $('library-refresh');

    button.disabled = value;
    button.textContent = value ? S.loading : S.library.refresh;
    $('library-loading').hidden = !value;

    if (value && state.games.length > 0) {
        $('library-loading').hidden = true;
    }
}

function fail(error) {
    setHidden('library-loading', true);
    setHidden('library-error', false);

    let text = error.raw || S.library.errorText;
    const status = httpStatus(error.raw);

    if (error.kind === 'network') {
        text = S.connect.networkText;
    } else if (isAuthError(error)) {
        text = S.library.authText;
    } else if (status) {
        text = S.httpError(status);
    }

    setText('library-error-text', text);

    if (state.games.length === 0) {
        setHidden('library-empty', true);
    }

    announce(S.library.errorTitle);
}

function paint() {
    const list = $('game-list');
    const heroSlug = state.games.find(
        (recipe) => recipe && recipe.launcher_supported,
    )?.slug;

    setHidden('library-empty', state.games.length > 0);
    setHidden('library-error', true);

    list.replaceChildren(
        ...state.games.map((recipe) =>
            gameCard(recipe, { hero: recipe.slug === heroSlug }),
        ),
    );
}

// ---- Meine Bibliothek: the local ledger, no network ----

// Newest record per slug, newest first. `installs.json` keeps the newest last.
function latestInstalls() {
    const records = Array.isArray(state.status?.installs)
        ? state.status.installs
        : [];
    const bySlug = new Map();

    for (const record of records) {
        if (record && record.slug) {
            bySlug.set(record.slug, record);
        }
    }

    return [...bySlug.values()].reverse();
}

function paintInstalled() {
    const records = latestInstalls();
    const list = $('library-installed');

    if (!list) {
        return;
    }

    setHidden('library-installed-empty', records.length > 0);
    list.replaceChildren(...records.map(installedCard));

    if (records.length > 0 && state.stage === 'library') {
        announce(S.library.installedLoaded(records.length));
    }
}

/**
 * One tile of the local library. `Starten` is the single primary action and
 * launches the executable from the install ledger, so it works with the server
 * switched off; the folder stays quiet machine data.
 */
function installedCard(record) {
    const recipe = (state.games || []).find(
        (game) => game && game.slug === record.slug,
    );
    const title = recipe?.title || record.slug;
    const verified = Array.isArray(record.verified) ? record.verified.length : 0;
    const check = installChecks.get(record.slug) || null;
    const repair = needsRepair(check);
    const start = el('button', {
        class: 'btn primary big',
        type: 'button',
        text: S.library.start,
    });
    // A record is never a dead end: a moved folder or a broken payload is fixed
    // by the same install command, quietly offered next to Starten.
    const reinstall = el('button', {
        class: 'btn',
        type: 'button',
        text: repair ? S.library.repair : S.library.reinstall,
        title: repair ? S.library.repairHint : S.library.reinstallHint,
    });

    start.addEventListener('click', () =>
        ready.show(record.slug, { launch: true, record, title }),
    );
    reinstall.addEventListener('click', () =>
        install.begin(installTarget(record, title)),
    );

    return el(
        'article',
        { class: 'card game installed' },
        el(
            'div',
            { class: 'game-body' },
            el(
                'div',
                { class: 'row wrap game-title-row' },
                el('h3', { class: 'game-title', text: title }),
                check && !check.ok
                    ? el(
                          'span',
                          { class: 'badge warn' },
                          el('span', {
                              class: 'dot warn',
                              'aria-hidden': 'true',
                          }),
                          el('span', { text: checkLabel(check) }),
                      )
                    : el(
                          'span',
                          { class: 'badge ok' },
                          el('span', {
                              class: 'dot ok',
                              'aria-hidden': 'true',
                          }),
                          el('span', { text: S.library.installed }),
                      ),
                record.version
                    ? el('span', {
                          class: 'chip mono',
                          text: `v${record.version}`,
                      })
                    : null,
            ),
            el(
                'dl',
                { class: 'stats installed-stats' },
                el(
                    'div',
                    { class: 'stat' },
                    el('dt', { text: S.library.folder }),
                    el('dd', {
                        class: 'mono break',
                        text: record.target_dir || '—',
                    }),
                ),
                verified > 0
                    ? el(
                          'div',
                          { class: 'stat' },
                          el('dt', { text: S.library.verifiedLabel }),
                          el('dd', { text: S.library.verified(verified) }),
                      )
                    : null,
            ),
        ),
        el('div', { class: 'row wrap game-actions' }, start, reinstall),
    );
}

// ---- Spiele vom Server: the shared catalogue ----

function latestInstall(slug) {
    const records = Array.isArray(state.status?.installs)
        ? state.status.installs
        : [];

    const matches = records.filter((record) => record && record.slug === slug);

    return matches.length ? matches[matches.length - 1] : null;
}

/**
 * Whether this build may offer `Installieren` for the recipe. The shell sets
 * `launcher_supported` (only the UT2004 engine runs today); `installable` is
 * the server's answer about the recipe itself, where an absent field means
 * "older server, unknown" — and unknown must not read as "no".
 */
function canInstall(recipe) {
    return Boolean(recipe.launcher_supported) && recipe.installable !== false;
}

/**
 * A browser recipe (NFSU2) has no install path: the server sends
 * `installable: false` plus a `play_url`. Its card offers "Spielen" and opens
 * that URL through the shell's `open_url`; no plan, download or verify runs.
 */
function isBrowserRecipe(recipe) {
    return Boolean(
        recipe &&
            recipe.installable === false &&
            typeof recipe.play_url === 'string' &&
            /^https?:\/\//i.test(recipe.play_url.trim()),
    );
}

async function openPlayUrl(event) {
    if (event) {
        event.preventDefault();
    }

    const url = event?.currentTarget?.dataset?.playUrl;

    if (!url) {
        return;
    }

    const result = await call('open_url', { url });

    if (!result.ok) {
        announce(S.library.playError);
    }
}

/** The game's web page on the paired server, or null without an http(s) base. */
export function gamePageUrl(slug) {
    const base = serverBase(state.url || state.status?.server?.url);

    return base && slug ? `${base}${GAMES_PATH}${encodeURIComponent(slug)}` : null;
}

async function openGamePage(event, slug) {
    event.preventDefault();

    const url = gamePageUrl(slug);

    if (!url) {
        return;
    }

    const result = await call('open_url', { url });

    if (!result.ok) {
        announce(S.library.webError);
    }
}

/**
 * One game tile, shared by the library and the chat answer. The hero variant
 * (the one installable game of the catalogue) carries the screen's primary
 * action; every card has exactly one primary action: Install or Start.
 */
export function gameCard(recipe, { hero = false } = {}) {
    const template = $('tpl-game');
    const node = template.content.firstElementChild.cloneNode(true);
    const record = latestInstall(recipe.slug);
    const check = installChecks.get(recipe.slug) || null;

    node.dataset.slug = recipe.slug;

    if (hero) {
        node.classList.add('hero');
    }

    const title = node.querySelector('[data-field="title"]');

    title.textContent = recipe.title || recipe.slug;

    const summary = node.querySelector('[data-field="summary"]');

    summary.textContent = recipe.summary || S.library.noSummary;

    const version = node.querySelector('[data-field="version"]');

    if (recipe.version) {
        version.textContent = `v${recipe.version}`;
        version.hidden = false;
    }

    const platforms = node.querySelector('[data-field="platforms"]');

    if (Array.isArray(recipe.platforms) && recipe.platforms.length > 0) {
        platforms.textContent = S.library.platforms(recipe.platforms.join(', '));
        platforms.hidden = false;
    }

    const recordNode = node.querySelector('[data-field="record"]');

    if (record) {
        recordNode.hidden = false;
        recordNode.replaceChildren(
            el('span', { text: `${S.library.installedTo} ` }),
            el('span', { class: 'mono break', text: record.target_dir || '—' }),
        );

        if (check) {
            recordNode.append(
                el('span', {
                    class: `install-state ${check.ok ? 'ok' : 'warn'}`,
                    text: ` · ${checkLabel(check)}`,
                }),
            );
        } else if (Array.isArray(record.verified) && record.verified.length > 0) {
            recordNode.append(
                el('span', {
                    class: 'small muted',
                    text: ` · ${S.library.verified(record.verified.length)}`,
                }),
            );
        }
    }

    const installButton = node.querySelector('[data-action="install"]');
    const startButton = node.querySelector('[data-action="start"]');
    const playButton = node.querySelector('[data-action="play"]');
    const reinstallButton = node.querySelector('[data-action="reinstall"]');
    const webLink = node.querySelector('[data-action="web"]');
    const installable = canInstall(recipe);
    const browser = isBrowserRecipe(recipe);
    const repair = needsRepair(check);

    // An installed game always starts, whatever the catalogue says today, and
    // keeps a quiet way back into the install flow. A browser recipe never
    // installs: its one primary action is "Spielen" over `play_url`.
    installButton.hidden = Boolean(record) || !installable || browser;
    startButton.hidden = !record;
    playButton.hidden = !browser;
    reinstallButton.hidden = !record;
    reinstallButton.textContent = repair ? S.library.repair : S.library.reinstall;
    reinstallButton.title = repair
        ? S.library.repairHint
        : S.library.reinstallHint;

    if (browser) {
        playButton.textContent = S.library.play;
        playButton.title = S.library.playTitle(recipe.title || recipe.slug);
        playButton.dataset.playUrl = recipe.play_url;
    }

    installButton.addEventListener('click', () => install.begin(recipe));
    startButton.addEventListener('click', () =>
        ready.show(recipe.slug, { launch: true, record }),
    );
    playButton.addEventListener('click', openPlayUrl);
    reinstallButton.addEventListener('click', () => install.begin(recipe));

    if (!record && !installable && !browser) {
        // "Bald im Launcher": the browser page is the honest next step, and no
        // Install button promises an install this build cannot finish.
        const soon = node.querySelector('[data-field="soon"]');
        const soonHint = node.querySelector('[data-field="soon-hint"]');

        soon.hidden = false;
        soonHint.hidden = false;
        setText(soonHint, S.library.soonHint);

        const url = gamePageUrl(recipe.slug);

        if (url) {
            webLink.href = url;
            webLink.title = S.library.webTitle(recipe.title || recipe.slug);
            webLink.hidden = false;
            webLink.addEventListener('click', (event) =>
                openGamePage(event, recipe.slug),
            );
        }
    }

    return node;
}
