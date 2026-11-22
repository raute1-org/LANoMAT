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

const sg = (o = {}) => ({
    key: 'steam:10',
    store: 'steam',
    store_id: '10',
    title: 'Counter-Strike',
    status: 'ready',
    install_path: 'C:\\Games\\Steam\\steamapps\\common\\cs',
    ...o,
});
const entry = (o = {}) => ({
    key: 'steam:10',
    title: 'Counter-Strike',
    recipe: null,
    store_games: [sg()],
    primary: 'play',
    ...o,
});
const lib = (entries, o = {}) => ({
    entries,
    sources: [
        { store: 'steam', present: true, found: entries.length, skipped: 0 },
    ],
    recipes_reason: null,
    scanned_at: '2026-11-22T10:00:00Z',
    ...o,
});

test('library: each disabled reason has its own text (no local scan)', async () => {
    for (const reason of ['module_off', 'server_too_old', 'not_paired']) {
        const r = await m.loadLibrary(
            mock({
                games_state: ok({ enabled: false, reason, local: false }),
            }),
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

test('library: signed out with a local scan lists the games and a one-line note', async () => {
    const r = await m.loadLibrary(
        mock({
            games_state: ok({
                enabled: false,
                reason: 'not_paired',
                local: true,
            }),
            library_list: ok(lib([entry()], { recipes_reason: 'not_paired' })),
        }),
    );
    assert.equal(r.phase, 'ready');
    assert.equal(r.entries.length, 1);
    assert.equal(r.recipesNote, S.games.recipesNote.not_paired);
});

test('library: empty, normal and a failing list', async () => {
    const on = ok({ enabled: true, reason: null, local: true });
    const e0 = await m.loadLibrary(
        mock({ games_state: on, library_list: ok(lib([])) }),
    );
    assert.equal(e0.phase, 'empty');
    assert.equal(e0.sources.length, 1);
    const r = await m.loadLibrary(
        mock({ games_state: on, library_list: ok(lib([entry()])) }),
    );
    assert.equal(r.phase, 'ready');
    assert.equal(r.recipesNote, null);
    assert.equal(r.scannedAt, '2026-11-22T10:00:00Z');
    const e = await m.loadLibrary(
        mock({
            games_state: on,
            library_list: fail({ message: 'server_error: 500' }),
        }),
    );
    assert.equal(e.phase, 'error');
    assert.equal(e.error.code, 'server_error');
    assert.equal(e.error.text, S.games.errors.server_error);
    assert.equal(e.error.retryable, true);
});

test('normalizeLibrary drops keyless entries and unknown primaries become none', () => {
    const n = m.normalizeLibrary(
        lib([
            entry({ primary: 'format_disk' }),
            { title: 'no key', store_games: [] },
            entry({ key: 'gog:5', store_games: [sg({ key: '' })] }),
        ]),
    );
    assert.equal(n.entries.length, 2);
    assert.equal(n.entries[0].primary, 'none');
    assert.equal(n.entries[1].storeGames.length, 0);
    assert.deepEqual(m.normalizeLibrary(null).entries, []);
});

test('entryCardModel: store badges in a fixed order, primary label, launch key', () => {
    const e = m.normalizeLibrary(
        lib([
            entry({
                key: 'recipe:hl',
                recipe: recipe({ slug: 'hl', title: 'Half-Life' }),
                store_games: [
                    sg({ key: 'ubisoft:1', store: 'ubisoft', status: 'ready' }),
                    sg({ key: 'steam:10', store: 'steam', status: 'broken' }),
                    sg({ key: 'epic:Ab', store: 'epic', status: 'ready' }),
                ],
            }),
        ]),
    ).entries[0];
    const c = m.entryCardModel(e);
    assert.deepEqual(
        c.badges.map((b) => b.store),
        ['steam', 'epic', 'ubisoft'],
    );
    assert.equal(c.primary.id, 'play');
    assert.equal(c.primary.label, S.games.actions.play);
    assert.equal(c.launchKey, 'epic:Ab');
    assert.equal(c.status.id, 'ready');
    assert.ok(c.recipe);
    const shop = m.entryCardModel(
        m.normalizeLibrary(
            lib([entry({ primary: 'open_store', store_games: [] })]),
        ).entries[0],
    );
    assert.equal(shop.primary.label, S.games.actions.openStore);
    assert.equal(shop.launchKey, null);
    assert.equal(shop.status, null);
});

test('filterEntries by source, query and ready; sortEntries lineup first', () => {
    const es = m.normalizeLibrary(
        lib([
            entry(),
            entry({
                key: 'epic:A',
                title: 'Alpha',
                store_games: [sg({ key: 'epic:A', store: 'epic' })],
            }),
            entry({
                key: 'recipe:ut',
                title: 'Unreal',
                recipe: recipe({ in_lineup: true, title: 'Unreal' }),
                store_games: [],
                primary: 'install_recipe',
            }),
        ]),
    ).entries;
    const f = (o) => m.filterEntries(es, o).map((e) => e.key);
    assert.deepEqual(f({ source: 'steam' }), ['steam:10']);
    assert.deepEqual(f({ source: 'lan' }), ['recipe:ut']);
    assert.deepEqual(f({ source: 'all' }).length, 3);
    assert.deepEqual(f({ query: 'alp' }), ['epic:A']);
    assert.deepEqual(f({ filter: 'ready' }), ['steam:10', 'epic:A']);
    assert.deepEqual(
        m.sortEntries(es).map((e) => e.key),
        ['recipe:ut', 'epic:A', 'steam:10'],
    );
    assert.deepEqual(m.SOURCES, [
        'all',
        'steam',
        'epic',
        'gog',
        'ubisoft',
        'lan',
    ]);
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

test('a paired client with the server down still lists the local games', async () => {
    // games_state fails for a reason that is not the session: the local list is still asked for.
    const r = await m.loadLibrary(
        mock({
            games_state: fail({ kind: 'network', message: 'offline' }),
            library_list: ok(lib([entry()], { recipes_reason: 'offline' })),
        }),
    );
    assert.equal(r.phase, 'ready');
    assert.equal(r.entries.length, 1);
    assert.equal(r.recipesNote, S.games.recipesNote.offline);
});

test('a rejected session is an error, the library is not asked for', async () => {
    let asked = false;
    const r = await m.loadLibrary(
        mock({
            games_state: fail({ kind: 'auth', message: 'not_paired' }),
            library_list: () => {
                asked = true;

                return ok(lib([entry()]));
            },
        }),
    );
    assert.equal(r.phase, 'error');
    assert.equal(asked, false);
});

test('without a state and without any local source the state error is shown', async () => {
    const r = await m.loadLibrary(
        mock({
            games_state: fail({ kind: 'network', message: 'offline' }),
            library_list: ok(
                lib([], {
                    recipes_reason: 'offline',
                    sources: [
                        {
                            store: 'steam',
                            present: false,
                            found: 0,
                            skipped: 0,
                        },
                    ],
                }),
            ),
        }),
    );
    assert.equal(r.phase, 'error');
    assert.equal(r.error.code, 'offline');
    const both = await m.loadLibrary(
        mock({
            games_state: fail({ kind: 'network', message: 'offline' }),
            library_list: fail({ message: 'server_error' }),
        }),
    );
    assert.equal(both.phase, 'error');
});

test('a platform without a local scan shows the recipes reason, not an empty scan', async () => {
    const off = ok({ enabled: false, reason: 'offline', local: false });
    const r = await m.loadLibrary(
        mock({
            games_state: off,
            library_list: ok(
                lib([], { recipes_reason: 'offline', sources: [] }),
            ),
        }),
    );
    assert.equal(r.phase, 'error');
    assert.equal(r.error.code, 'offline');
    // Nothing listed and no reason: an empty library without the PC's source lines.
    const on = ok({ enabled: true, reason: null, local: false });
    const e = await m.loadLibrary(
        mock({ games_state: on, library_list: ok(lib([], { sources: [] })) }),
    );
    assert.equal(e.phase, 'empty');
    assert.equal(e.local, false);
    const w = await m.loadLibrary(
        mock({
            games_state: ok({ enabled: true, reason: null, local: true }),
            library_list: ok(lib([])),
        }),
    );
    assert.equal(w.local, true);
});

test('the Spiele tab is wanted when the PC can scan, even with the module off', () => {
    assert.equal(
        m.gamesTabWanted({ enabled: false, reason: 'module_off', local: true }),
        true,
    );
    assert.equal(
        m.gamesTabWanted({
            enabled: false,
            reason: 'module_off',
            local: false,
        }),
        false,
    );
    assert.equal(
        m.gamesTabWanted({ enabled: true, reason: null, local: false }),
        true,
    );
    assert.equal(
        m.gamesTabWanted({
            enabled: false,
            reason: 'not_paired',
            local: false,
        }),
        true,
    );
    assert.equal(m.gamesTabWanted(null), false);
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

test('genres of the entries are unique and sorted', () => {
    const rs = ['Shooter', 'Rennen', '', 'Shooter'].map((genre, i) =>
        m.normalizeRecipe(recipe({ slug: `r${i}`, genre })),
    );
    assert.deepEqual(m.genresOf(rs), ['Rennen', 'Shooter']);
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
