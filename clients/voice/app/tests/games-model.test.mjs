// node --test app/tests/games-model.test.mjs
// The Spiele tab logic without a DOM or a backend: every state of the loading flow with a mock
// `call`, plus filter, sort and label mapping. Fixtures follow the command contract.
import assert from 'node:assert/strict';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const dir = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../ui/js',
);
const m = await import(path.join(dir, 'games-model.js'));
const { S } = await import(path.join(dir, 'strings.js'));

const recipe = (o = {}) => ({
    slug: 'ut2004',
    title: 'Unreal Tournament 2004',
    summary: 'Arena shooter',
    genre: 'Shooter',
    players: 2,
    players_max: 16,
    platforms: ['windows', 'linux'],
    size_bytes: 6_000_000_000,
    installable: true,
    launcher_supported: true,
    in_lineup: false,
    availability: 'on_lan',
    local: { status: 'not_installed', path: null, last_played_at: null },
    ...o,
});
const list = (recipes) => ({ recipes, fetched_at: '2026-11-22T10:00:00Z' });
const ok = (value) => ({ ok: true, value });
const fail = (error) => ({ ok: false, error });
const mock = (map) => async (cmd) => {
    const v = map[cmd];

    return typeof v === 'function' ? v() : v;
};

test('library: each disabled reason has its own text', async () => {
    for (const reason of ['module_off', 'server_too_old', 'not_paired']) {
        const r = await m.loadLibrary(
            mock({ games_state: ok({ enabled: false, reason }) }),
        );
        assert.equal(r.phase, 'disabled');
        assert.equal(r.info.reason, reason);
        assert.equal(r.info.title, S.games.disabled[reason].title);
    }

    const odd = await m.loadLibrary(
        mock({ games_state: ok({ enabled: false, reason: 'x' }) }),
    );
    assert.equal(odd.info.reason, 'other');
});

test('library: empty, normal and a failing list', async () => {
    const on = ok({ enabled: true, reason: null });
    assert.equal(
        (
            await m.loadLibrary(
                mock({ games_state: on, games_list: ok(list([])) }),
            )
        ).phase,
        'empty',
    );
    const r = await m.loadLibrary(
        mock({ games_state: on, games_list: ok(list([recipe()])) }),
    );
    assert.equal(r.phase, 'ready');
    assert.equal(r.recipes.length, 1);
    assert.equal(r.fetchedAt, '2026-11-22T10:00:00Z');
    const e = await m.loadLibrary(
        mock({
            games_state: on,
            games_list: fail({ message: 'server_error: 500' }),
        }),
    );
    assert.equal(e.phase, 'error');
    assert.equal(e.error.code, 'server_error');
    assert.equal(e.error.text, S.games.errors.server_error);
    assert.equal(e.error.retryable, true);
});

test('errors: codes from a string, unknown stays generic, never raw text', () => {
    assert.equal(m.errorCode({ raw: 'offline' }), 'offline');
    assert.equal(
        m.errorCode({ message: 'plan_version_unsupported' }),
        'plan_version_unsupported',
    );
    assert.equal(m.errorCode({ message: 'boom {"x":1}' }), 'generic');
    assert.equal(m.errorCode(null), 'generic');
    assert.equal(
        m.errorInfo({ raw: 'plan_version_unsupported' }).retryable,
        false,
    );
    assert.equal(m.errorInfo({ raw: 'not_paired' }).retryable, false);
    assert.equal(m.errorInfo({ raw: 'offline' }).retryable, true);
});

test('state errors surface as an error phase', async () => {
    const r = await m.loadLibrary(
        mock({ games_state: fail({ message: 'offline' }) }),
    );
    assert.equal(r.phase, 'error');
    assert.equal(r.error.code, 'offline');
});

test('normalising is defensive: nulls mean unknown', () => {
    const r = m.normalizeRecipe({
        slug: 's',
        installable: null,
        launcher_supported: null,
    });
    assert.equal(r.title, 's');
    assert.equal(r.installable, null);
    assert.equal(r.launcherSupported, null);
    assert.equal(r.availability, 'unknown');
    assert.equal(r.local.status, 'not_installed');
    assert.deepEqual(r.platforms, []);
    assert.equal(r.sizeBytes, null);
    assert.equal(r.coverUrl, null);
    assert.equal(
        m.normalizeRecipe({ slug: 's', cover_url: 'javascript:1' }).coverUrl,
        null,
    );
    assert.equal(
        m.normalizeRecipe({ slug: 's', cover_url: 'https://x/y.png' }).coverUrl,
        'https://x/y.png',
    );
    assert.deepEqual(m.normalizeList(null).recipes, []);
    assert.equal(
        m.normalizeList({ recipes: [{ title: 'no slug' }] }).recipes.length,
        0,
    );
});

test('cards: a label and a tone for every availability and local status', () => {
    for (const a of ['on_lan', 'missing', 'unknown']) {
        const c = m.cardModel(m.normalizeRecipe(recipe({ availability: a })));
        assert.equal(c.availability.label, S.games.availability[a]);
    }

    for (const s of [
        'not_installed',
        'installing',
        'ready',
        'repair_needed',
        'broken',
        'offline',
    ]) {
        const c = m.cardModel(
            m.normalizeRecipe(recipe({ local: { status: s } })),
        );
        assert.equal(c.local.label, S.games.local[s]);
    }

    assert.equal(m.localTone('broken'), 'down');
    assert.equal(m.localTone('repair_needed'), 'warn');
    assert.equal(m.availabilityTone('missing'), 'down');
    assert.equal(
        m.playersLabel({ players: 2, playersMax: 16 }),
        '2 bis 16 Spieler',
    );
    assert.equal(m.playersLabel({ players: null, playersMax: 8 }), '8 Spieler');
    assert.equal(
        m.playersLabel({ players: null, playersMax: null }),
        S.games.playersUnknown,
    );
    assert.equal(
        m.platformsLabel(['windows', 'linux', 'amiga']),
        'Windows, Linux, amiga',
    );
    assert.equal(m.platformsLabel([]), S.games.platformsUnknown);
    assert.equal(m.installableLabel(null), S.games.installable.unknown);
});

const sample = () =>
    [
        recipe({ slug: 'b', title: 'Beta', genre: 'Rennen', in_lineup: false }),
        recipe({
            slug: 'a',
            title: 'Äther',
            genre: 'Shooter',
            in_lineup: false,
            local: { status: 'ready' },
        }),
        recipe({
            slug: 'c',
            title: 'Charlie',
            genre: 'Shooter',
            in_lineup: true,
            availability: 'missing',
            installable: false,
        }),
        recipe({
            slug: 'd',
            title: 'Delta',
            genre: '',
            in_lineup: true,
            installable: null,
            availability: 'unknown',
        }),
    ].map(m.normalizeRecipe);

test('sorting puts the lineup first, then titles with German collation', () => {
    assert.deepEqual(
        m.sortRecipes(sample()).map((r) => r.slug),
        ['c', 'd', 'a', 'b'],
    );
});

test('filtering: search, genre, ready and installable', () => {
    const all = sample();
    const slugs = (f) => m.filterRecipes(all, f).map((r) => r.slug);
    assert.deepEqual(slugs({}), ['b', 'a', 'c', 'd']);
    assert.deepEqual(slugs({ query: 'äth' }), ['a']);
    assert.deepEqual(slugs({ query: '  CHAR ' }), ['c']);
    assert.deepEqual(slugs({ genre: 'Shooter' }), ['a', 'c']);
    assert.deepEqual(slugs({ only: 'ready' }), ['a']);
    // installable: files on the LAN host, installable, not yet ready; null is not "yes"
    assert.deepEqual(slugs({ only: 'installable' }), ['b']);
    assert.deepEqual(slugs({ query: 'nix' }), []);
    assert.deepEqual(m.genresOf(all), ['Rennen', 'Shooter']);
});

test('detail: with a plan, missing files flagged', async () => {
    const detail = {
        recipe: {
            ...recipe(),
            description: 'Long',
            aliases: ['ut2k4'],
            homepage: 'https://x.test',
            license: 'Proprietary',
            portable: false,
            supports_multiplayer: true,
        },
        plan_summary: {
            plan_version: 1,
            files: [
                {
                    label: 'Base game',
                    size_bytes: 5_000_000_000,
                    source: 'lan',
                    required: true,
                    status: 'available',
                },
                {
                    label: 'Patch',
                    size_bytes: 1000,
                    source: 'external',
                    required: false,
                    status: 'missing',
                },
                {
                    label: 'Voices',
                    size_bytes: null,
                    source: 'lan',
                    required: true,
                    status: '',
                },
            ],
            missing: ['Voices'],
        },
        local: {
            status: 'repair_needed',
            path: '/games/ut',
            last_played_at: '2026-11-21T20:00:00Z',
        },
    };
    const r = await m.loadDetail(mock({ game_detail: ok(detail) }), 'ut2004');
    assert.equal(r.phase, 'ready');
    assert.equal(r.recipe.local.status, 'repair_needed');
    assert.equal(r.recipe.aliases[0], 'ut2k4');
    assert.deepEqual(
        r.plan.files.map((f) => f.gone),
        [false, true, true],
    );
    assert.equal(r.plan.files[1].sourceLabel, S.games.detail.source.external);
    assert.equal(
        r.plan.files[0].statusLabel,
        S.games.detail.fileStatus.available,
    );
    assert.equal(r.plan.files[1].required, false);
    assert.equal(r.plan.version, 1);
});

test('detail: without a plan, and errors', async () => {
    const r = await m.loadDetail(
        mock({
            game_detail: ok({
                recipe: recipe(),
                plan_summary: null,
                local: null,
            }),
        }),
        'x',
    );
    assert.equal(r.plan, null);
    assert.equal(r.recipe.local.status, 'not_installed');
    const e = await m.loadDetail(
        mock({ game_detail: fail({ message: 'plan_version_unsupported' }) }),
        'x',
    );
    assert.equal(e.phase, 'error');
    assert.equal(e.error.retryable, false);
});
