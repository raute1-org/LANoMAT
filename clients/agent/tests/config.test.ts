import { chmod, mkdir, readdir, stat, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    CONFIG_BACKUP_SUFFIX,
    CONFIG_VERSION,
    configBackupPath,
    configDir,
    configPath,
    defaultAvatarsDir,
    defaultSkillsDir,
    deleteConfig,
    enforceConfigPermissions,
    inspectConfigPermissions,
    isTokenExpired,
    loadConfig,
    loadConfigDetailed,
    modeToString,
    piAgentDir,
    readConfigDocument,
    saveConfig,
} from '../src/config.js';
import { readJson, removeDir, tempDir } from './helpers.js';

const config = {
    url: 'http://lanomat.local',
    token: 'secret-token-value',
    token_expires_at: '2030-01-01T00:00:00.000Z',
    user: { id: 7, name: 'Rene' },
};

function envFor(dir: string): NodeJS.ProcessEnv {
    return { LANOMAT_CONFIG_DIR: join(dir, 'cfg') } as NodeJS.ProcessEnv;
}

describe('config', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('writes agent.json with 0600 and the directory with 0700', async () => {
        const env = envFor(dir);
        const path = await saveConfig(config, { env });
        const file = await stat(path);
        const folder = await stat(join(dir, 'cfg'));

        expect(path).toBe(join(dir, 'cfg', 'agent.json'));
        expect(file.mode & 0o777).toBe(0o600);
        expect(folder.mode & 0o777).toBe(0o700);
        expect(modeToString(file.mode & 0o777)).toBe('0600');
    });

    it('round-trips the config and preserves optional model fields', async () => {
        const env = envFor(dir);
        const withModel = { ...config, model: 'venice/foo', modelRoles: { chat: 'venice/foo' } };

        await saveConfig(withModel, { env });

        expect(await loadConfig({ env })).toEqual(withModel);
    });

    it('stamps the current schema version without exposing it on the config object', async () => {
        const env = envFor(dir);
        await saveConfig(config, { env });

        const document = await readJson(configPath({ env }));
        const loaded = await loadConfigDetailed({ env });

        expect(CONFIG_VERSION).toBe(2);
        expect(document.version).toBe(CONFIG_VERSION);
        expect(document.url).toBe(config.url);
        expect(loaded.version).toBe(CONFIG_VERSION);
        expect(loaded.migrated).toBe(false);
        expect(loaded.config).not.toHaveProperty('version');
    });

    it('migrates a v1 file (no version, expires_at, user_name) and writes it back as v2', async () => {
        const env = envFor(dir);
        const path = configPath({ env });
        await mkdir(join(dir, 'cfg'), { recursive: true, mode: 0o700 });
        await writeFile(
            path,
            JSON.stringify({
                url: 'http://lanomat.local/',
                token: 'v1-token',
                expires_at: '2029-05-01T00:00:00.000Z',
                user_id: 7,
                user_name: 'Rene',
                unknownKey: { keep: true },
            }),
            { mode: 0o600 },
        );

        const outcome = await loadConfigDetailed({ env });
        const document = await readJson(path);

        expect(outcome.version).toBe(1);
        expect(outcome.migrated).toBe(true);
        expect(outcome.config).toMatchObject({
            url: 'http://lanomat.local',
            token: 'v1-token',
            token_expires_at: '2029-05-01T00:00:00.000Z',
            user: { id: 7, name: 'Rene' },
        });
        expect(document.version).toBe(2);
        expect(document.unknownKey).toEqual({ keep: true });
        expect(document).not.toHaveProperty('expires_at');
        expect(document).not.toHaveProperty('user_name');
        expect((await stat(path)).mode & 0o777).toBe(0o600);

        const again = await loadConfigDetailed({ env });

        expect(again.migrated).toBe(false);
        expect(again.config?.token).toBe('v1-token');
    });

    it('refuses a config written by a newer agent instead of downgrading it', async () => {
        const env = envFor(dir);
        const path = configPath({ env });
        await mkdir(join(dir, 'cfg'), { recursive: true, mode: 0o700 });
        await writeFile(
            path,
            JSON.stringify({ ...config, version: 99, futureOnly: { nested: true } }),
            { mode: 0o600 },
        );

        await expect(loadConfigDetailed({ env })).rejects.toThrow(
            'written by a newer lanomat-agent (version 99',
        );
        expect((await readJson(path)).version).toBe(99);
        expect((await readdir(join(dir, 'cfg'))).some((name) => name.includes('.corrupt-'))).toBe(false);
    });

    it('preserves unrelated keys when saving over an existing file', async () => {
        const env = envFor(dir);
        const path = configPath({ env });
        await mkdir(join(dir, 'cfg'), { recursive: true, mode: 0o700 });
        await writeFile(
            path,
            JSON.stringify({ ...config, version: CONFIG_VERSION, unrelated: 'keep-me' }),
            { mode: 0o600 },
        );

        await saveConfig({ ...config, token: 'rotated-token' }, { env });

        const document = await readJson(path);

        expect(document.unrelated).toBe('keep-me');
        expect(document.token).toBe('rotated-token');
        expect(await readConfigDocument({ env })).toMatchObject({ unrelated: 'keep-me' });
    });

    it('backs up the previous good config before overwriting it', async () => {
        const env = envFor(dir);

        await saveConfig(config, { env });
        await saveConfig({ ...config, token: 'second-token' }, { env });

        const backup = await readJson(configBackupPath({ env }));

        expect(backup.token).toBe('secret-token-value');
        expect((await stat(configBackupPath({ env }))).mode & 0o777).toBe(0o600);
    });

    it('quarantines corrupt JSON and restores the last good backup', async () => {
        const env = envFor(dir);
        const path = configPath({ env });
        const backup = configBackupPath({ env });
        await mkdir(join(dir, 'cfg'), { recursive: true, mode: 0o700 });
        await writeFile(path, '{"url": "http://lan.local", "token":', { mode: 0o600 });
        await writeFile(backup, JSON.stringify({ ...config, version: CONFIG_VERSION }), { mode: 0o600 });

        const outcome = await loadConfigDetailed({ env });
        const names = await readdir(join(dir, 'cfg'));

        expect(outcome.recoveredFromBackup).toBe(true);
        expect(outcome.corruptBackupPath).toBeDefined();
        expect(names.some((name) => name.includes('.corrupt-'))).toBe(true);
        expect(outcome.config?.token).toBe('secret-token-value');
        expect((await readJson(path)).version).toBe(2);
        expect((await stat(path)).mode & 0o777).toBe(0o600);
    });

    it('returns null for corrupt JSON without a backup and still quarantines it', async () => {
        const env = envFor(dir);
        const path = configPath({ env });
        await mkdir(join(dir, 'cfg'), { recursive: true, mode: 0o700 });
        await writeFile(path, 'not json at all', { mode: 0o600 });

        const outcome = await loadConfigDetailed({ env });
        const names = await readdir(join(dir, 'cfg'));

        expect(outcome.config).toBeNull();
        expect(outcome.recoveredFromBackup).toBe(false);
        expect(outcome.corruptBackupPath).toBeDefined();
        expect(names.filter((name) => name.includes('.corrupt-'))).toHaveLength(1);
        expect(names).not.toContain('agent.json');
        expect(await loadConfig({ env })).toBeNull();
    });

    it('treats a structurally invalid backup as signed out instead of throwing', async () => {
        const env = envFor(dir);
        const path = configPath({ env });
        await mkdir(join(dir, 'cfg'), { recursive: true, mode: 0o700 });
        await writeFile(path, '{"url":', { mode: 0o600 });
        await writeFile(configBackupPath({ env }), '{"unrelated":true}', { mode: 0o600 });

        const outcome = await loadConfigDetailed({ env });

        expect(outcome.config).toBeNull();
        expect(outcome.recoveredFromBackup).toBe(true);
        await expect(loadConfig({ env })).resolves.toBeNull();
    });

    it('quarantines a corrupt file before saveConfig overwrites it', async () => {
        const env = envFor(dir);
        const path = configPath({ env });
        await mkdir(join(dir, 'cfg'), { recursive: true, mode: 0o700 });
        await writeFile(path, 'garbage{', { mode: 0o600 });

        await saveConfig(config, { env });

        const names = await readdir(join(dir, 'cfg'));

        expect(names.filter((name) => name.includes('.corrupt-'))).toHaveLength(1);
        expect((await readJson(path)).token).toBe('secret-token-value');
    });

    it('tightens loose file and directory permissions on load', async () => {
        const env = envFor(dir);
        const path = await saveConfig(config, { env });

        await chmod(path, 0o644);
        await chmod(join(dir, 'cfg'), 0o755);

        const outcome = await loadConfigDetailed({ env });

        expect(outcome.permissionsRepaired).toBe(true);
        expect((await stat(path)).mode & 0o777).toBe(0o600);
        expect((await stat(join(dir, 'cfg'))).mode & 0o777).toBe(0o700);
        expect((await inspectConfigPermissions({ env })).secure).toBe(true);
    });

    it('enforceConfigPermissions is idempotent', async () => {
        const env = envFor(dir);
        const path = await saveConfig(config, { env });
        await chmod(path, 0o604);

        expect(await enforceConfigPermissions({ env })).toBe(true);
        expect(await enforceConfigPermissions({ env })).toBe(false);
    });

    it('leaves no temporary files behind (atomic writes)', async () => {
        const env = envFor(dir);

        await saveConfig(config, { env });
        expect(await readdir(join(dir, 'cfg'))).toEqual(['agent.json']);

        await saveConfig({ ...config, token: 'rotated' }, { env });

        const names = (await readdir(join(dir, 'cfg'))).sort();

        expect(names).toEqual(['agent.json', `agent.json${CONFIG_BACKUP_SUFFIX}`]);
        expect(names.some((name) => name.endsWith('.tmp'))).toBe(false);
    });

    it('reports insecure permissions', async () => {
        const env = envFor(dir);
        const path = await saveConfig(config, { env });
        await chmod(path, 0o644);

        const perms = await inspectConfigPermissions({ env });

        expect(perms.configExists).toBe(true);
        expect(perms.fileMode).toBe(0o644);
        expect(perms.secure).toBe(false);
    });

    it('deletes the token, its backups and reports a missing file', async () => {
        const env = envFor(dir);
        await saveConfig(config, { env });
        await saveConfig({ ...config, token: 'rotated' }, { env });
        await writeFile(join(dir, 'cfg', 'agent.json.corrupt-2026-01-01T00-00-00-000Z'), '{}');

        expect(await deleteConfig({ env })).toBe(true);
        expect(await readdir(join(dir, 'cfg'))).toEqual([]);
        expect(await deleteConfig({ env })).toBe(false);
        expect(await loadConfig({ env })).toBeNull();
        expect(await inspectConfigPermissions({ env })).toEqual({ configExists: false, secure: false });
    });

    it('detects an expired token', () => {
        expect(isTokenExpired(config, () => new Date('2031-01-01T00:00:00Z'))).toBe(true);
        expect(isTokenExpired(config, () => new Date('2029-01-01T00:00:00Z'))).toBe(false);
        expect(isTokenExpired({ ...config, token_expires_at: '' })).toBe(false);
    });

    it('uses XDG and LANOMAT overrides for every path', () => {
        const env = {
            XDG_CONFIG_HOME: '/xdg/config',
            XDG_DATA_HOME: '/xdg/data',
            LOCALAPPDATA: 'C:\\Users\\x\\AppData\\Local',
            LANOMAT_PI_DIR: '/pi',
            LANOMAT_SKILLS_DIR: '/skills',
            LANOMAT_AVATARS_DIR: '/avatars',
        } as NodeJS.ProcessEnv;

        expect(configDir({ env })).toBe('/xdg/config/lanomat');
        expect(configPath({ env })).toBe('/xdg/config/lanomat/agent.json');
        expect(configBackupPath({ env })).toBe(`/xdg/config/lanomat/agent.json${CONFIG_BACKUP_SUFFIX}`);
        expect(piAgentDir({ env })).toBe('/pi');
        expect(defaultSkillsDir({ env })).toBe('/skills');
        expect(defaultAvatarsDir({ env })).toBe('/avatars');
    });

    it('uses the documented avatar and skills paths per platform', () => {
        expect(defaultAvatarsDir({ env: {}, home: '/home/r', platform: 'linux' })).toBe(
            '/home/r/.local/share/lanomat/avatars',
        );
        expect(
            defaultAvatarsDir({
                env: { LOCALAPPDATA: 'C:\\Users\\r\\AppData\\Local' },
                home: 'C:\\Users\\r',
                platform: 'win32',
            }),
        ).toBe(join('C:\\Users\\r\\AppData\\Local', 'LANoMAT', 'avatars'));
        expect(defaultSkillsDir({ env: {}, home: '/home/r' })).toBe('/home/r/.agents/skills');
    });
});
