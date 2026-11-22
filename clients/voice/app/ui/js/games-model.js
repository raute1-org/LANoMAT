// Spiele tab, the pure part: defensive normalising of what the Rust side returns, the card and
// detail view models, filtering, sorting and the loading flow. No DOM and no Tauri here, so the
// whole thing runs under `node --test` (see app/tests/games-model.test.mjs).
import { S } from './strings.js';

const AVAILABILITY = ['on_lan', 'missing', 'unknown'];
const LOCAL = [
    'not_installed',
    'installing',
    'ready',
    'repair_needed',
    'broken',
    'offline',
];
const REASONS = ['module_off', 'server_too_old', 'not_paired'];
const ERROR_CODES = [
    'not_paired',
    'server_error',
    'plan_version_unsupported',
    'offline',
];

const AVAILABILITY_TONE = { on_lan: 'ok', missing: 'down', unknown: '' };
const LOCAL_TONE = {
    not_installed: '',
    installing: 'live',
    ready: 'ok',
    repair_needed: 'warn',
    broken: 'down',
    offline: 'warn',
};

const text = (v) => (typeof v === 'string' ? v.trim() : '');
const num = (v) => (Number.isFinite(v) && v >= 0 ? v : null);
const triState = (v) => (typeof v === 'boolean' ? v : null);

export function normalizeLocal(l) {
    const o = l && typeof l === 'object' ? l : {};

    return {
        status: LOCAL.includes(o.status) ? o.status : 'not_installed',
        path: text(o.path) || null,
        lastPlayedAt: text(o.last_played_at) || null,
    };
}

export function normalizeRecipe(r) {
    const o = r && typeof r === 'object' ? r : {};

    return {
        slug: text(o.slug),
        title: text(o.title) || text(o.slug),
        summary: text(o.summary),
        genre: text(o.genre),
        players: num(o.players),
        playersMax: num(o.players_max),
        platforms: Array.isArray(o.platforms)
            ? o.platforms.map(text).filter(Boolean)
            : [],
        sizeBytes: num(o.size_bytes),
        installable: triState(o.installable),
        launcherSupported: triState(o.launcher_supported),
        inLineup: o.in_lineup === true,
        availability: AVAILABILITY.includes(o.availability)
            ? o.availability
            : 'unknown',
        local: normalizeLocal(o.local),
        coverUrl: /^https?:\/\//.test(text(o.cover_url))
            ? text(o.cover_url)
            : null,
        description: text(o.description),
        aliases: Array.isArray(o.aliases)
            ? o.aliases.map(text).filter(Boolean)
            : [],
        homepage: /^https?:\/\//.test(text(o.homepage))
            ? text(o.homepage)
            : null,
        license: text(o.license),
        portable: triState(o.portable),
        supportsMultiplayer: triState(o.supports_multiplayer),
    };
}

export function normalizeList(v) {
    const o = v && typeof v === 'object' ? v : {};
    const recipes = Array.isArray(o.recipes)
        ? o.recipes.map(normalizeRecipe).filter((r) => r.slug)
        : [];

    return { recipes, fetchedAt: text(o.fetched_at) || null };
}

export function normalizeDetail(v) {
    const o = v && typeof v === 'object' ? v : {};
    const base = o.recipe && typeof o.recipe === 'object' ? o.recipe : {};
    const recipe = normalizeRecipe({ ...base, local: o.local ?? base.local });
    const p = o.plan_summary;
    const plan =
        p && typeof p === 'object'
            ? {
                  version: num(p.plan_version),
                  files: (Array.isArray(p.files) ? p.files : []).map((f) => ({
                      label: text(f && f.label),
                      sizeBytes: num(f && f.size_bytes),
                      source: text(f && f.source),
                      required: !f || f.required !== false,
                      status: text(f && f.status),
                  })),
                  missing: (Array.isArray(p.missing) ? p.missing : [])
                      .map(text)
                      .filter(Boolean),
              }
            : null;

    return { recipe, plan };
}

// ---- labels ----

export const availabilityLabel = (a) => S.games.availability[a];
export const availabilityTone = (a) => AVAILABILITY_TONE[a] ?? '';
export const localLabel = (s) => S.games.local[s];
export const localTone = (s) => LOCAL_TONE[s] ?? '';

export function playersLabel(r) {
    const max = r.playersMax ?? r.players;
    const min = r.players ?? r.playersMax;

    return max ? S.games.players(min, max) : S.games.playersUnknown;
}

export function platformsLabel(platforms) {
    return platforms.length
        ? platforms.map((p) => S.games.platform[p] || p).join(', ')
        : S.games.platformsUnknown;
}

export function installableLabel(v) {
    return v === true
        ? S.games.installable.yes
        : v === false
          ? S.games.installable.no
          : S.games.installable.unknown;
}

export function fileSourceLabel(source) {
    return S.games.detail.source[source] || source || '';
}

export function fileStatusLabel(status) {
    return S.games.detail.fileStatus[status] || status || '';
}

// What a card shows, without any DOM. `unknown` stays unknown, it is never turned into a no.
export function cardModel(r) {
    return {
        slug: r.slug,
        title: r.title,
        summary: r.summary,
        genre: r.genre,
        players: playersLabel(r),
        platforms: platformsLabel(r.platforms),
        sizeBytes: r.sizeBytes,
        inLineup: r.inLineup,
        coverUrl: r.coverUrl,
        availability: {
            id: r.availability,
            label: availabilityLabel(r.availability),
            tone: availabilityTone(r.availability),
        },
        local: {
            id: r.local.status,
            label: localLabel(r.local.status),
            tone: localTone(r.local.status),
        },
        lastPlayedAt: r.local.lastPlayedAt,
    };
}

// The files of a plan with the ones the server reports as missing flagged.
export function planModel(plan) {
    if (!plan) {
        return null;
    }

    const missing = new Set(plan.missing);

    return {
        version: plan.version,
        missing: plan.missing,
        files: plan.files.map((f) => {
            const gone = f.status === 'missing' || missing.has(f.label);

            return {
                ...f,
                sourceLabel: fileSourceLabel(f.source),
                statusLabel: fileStatusLabel(f.status),
                gone,
            };
        }),
    };
}

// ---- filter and sort ----

export const FILTERS = ['all', 'ready', 'installable'];

export function genresOf(recipes) {
    return [...new Set(recipes.map((r) => r.genre).filter(Boolean))].sort(
        (a, b) => a.localeCompare(b, 'de'),
    );
}

export function filterRecipes(
    recipes,
    { query = '', genre = '', only = 'all' },
) {
    const q = query.trim().toLocaleLowerCase('de');

    return recipes.filter((r) => {
        if (genre && r.genre !== genre) {
            return false;
        }

        if (only === 'ready' && r.local.status !== 'ready') {
            return false;
        }

        if (
            only === 'installable' &&
            !(
                r.installable === true &&
                r.availability === 'on_lan' &&
                r.local.status !== 'ready'
            )
        ) {
            return false;
        }

        if (!q) {
            return true;
        }

        return [r.title, r.genre, r.summary, r.slug, ...r.aliases].some((t) =>
            t.toLocaleLowerCase('de').includes(q),
        );
    });
}

// Lineup first, then by title.
export function sortRecipes(recipes) {
    return [...recipes].sort(
        (a, b) =>
            Number(b.inLineup) - Number(a.inLineup) ||
            a.title.localeCompare(b.title, 'de'),
    );
}

// ---- errors ----

// The Rust side rejects with a string that carries one of the codes, or with an object whose
// message does. Anything else is "generic": a message for a person, never raw text.
export function errorCode(error) {
    const hay = [
        error && error.raw,
        error && error.code,
        error && error.message,
    ]
        .filter((x) => typeof x === 'string')
        .join(' ');

    return ERROR_CODES.find((c) => hay.includes(c)) || 'generic';
}

export function errorInfo(error) {
    const code = errorCode(error);

    return {
        code,
        title: S.games.errors.title,
        text: S.games.errors[code],
        // An old app does not get better by asking again, an unpaired device neither.
        retryable: code !== 'plan_version_unsupported' && code !== 'not_paired',
    };
}

// ---- loading ----

export function disabledInfo(reason) {
    const key = REASONS.includes(reason) ? reason : 'other';

    return { reason: key, ...S.games.disabled[key] };
}

// `call` is util.call: resolves {ok, value} or {ok: false, error}.
export async function loadLibrary(call) {
    const st = await call('games_state');

    if (!st.ok) {
        return { phase: 'error', error: errorInfo(st.error) };
    }

    if (!st.value || st.value.enabled !== true) {
        return {
            phase: 'disabled',
            info: disabledInfo(st.value && st.value.reason),
        };
    }

    const list = await call('games_list');

    if (!list.ok) {
        return { phase: 'error', error: errorInfo(list.error) };
    }

    const { recipes, fetchedAt } = normalizeList(list.value);

    return recipes.length
        ? { phase: 'ready', recipes, fetchedAt }
        : { phase: 'empty' };
}

export async function loadDetail(call, slug) {
    const r = await call('game_detail', { slug });

    if (!r.ok) {
        return { phase: 'error', error: errorInfo(r.error) };
    }

    const d = normalizeDetail(r.value);

    return { phase: 'ready', recipe: d.recipe, plan: planModel(d.plan) };
}
