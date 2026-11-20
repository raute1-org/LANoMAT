import { chmod, stat } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    configDir,
    configPath,
    defaultAvatarsDir,
    defaultSkillsDir,
    deleteConfig,
    inspectConfigPermissions,
    isTokenExpired,
    loadConfig,
    modeToString,
    piAgentDir,
    saveConfig,
} from '../src/config.js';
import { removeDir, tempDir } from './helpers.js';

const config = {
    url: 'http://lanomat.local',
    token: 'secret-token-value',
    token_expires_at: '2030-01-01T00:00:00.000Z',
    user: { id: 7, name: 'Rene' },
};

describe('config', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('writes agent.json with 0600 and the directory with 0700', async () => {
        const env = { LANOMAT_CONFIG_DIR: join(dir, 'cfg') } as NodeJS.ProcessEnv;
        const path = await saveConfig(config, { env });
        const file = await stat(path);
        const folder = await stat(join(dir, 'cfg'));

        expect(path).toBe(join(dir, 'cfg', 'agent.json'));
        expect(file.mode & 0o777).toBe(0o600);
        expect(folder.mode & 0o777).toBe(0o700);
        expect(modeToString(file.mode & 0o777)).toBe('0600');
    });

    it('round-trips the config and preserves optional model fields', async () => {
        const env = { LANOMAT_CONFIG_DIR: join(dir, 'cfg') } as NodeJS.ProcessEnv;
        const withModel = { ...config, model: 'venice/foo', modelRoles: { chat: 'venice/foo' } };

        await saveConfig(withModel, { env });

        expect(await loadConfig({ env })).toEqual(withModel);
    });

    it('reports insecure permissions', async () => {
        const env = { LANOMAT_CONFIG_DIR: join(dir, 'cfg') } as NodeJS.ProcessEnv;
        const path = await saveConfig(config, { env });
        await chmod(path, 0o644);

        const perms = await inspectConfigPermissions({ env });

        expect(perms.configExists).toBe(true);
        expect(perms.fileMode).toBe(0o644);
        expect(perms.secure).toBe(false);
    });

    it('deletes the token and reports a missing file', async () => {
        const env = { LANOMAT_CONFIG_DIR: join(dir, 'cfg') } as NodeJS.ProcessEnv;
        await saveConfig(config, { env });

        expect(await deleteConfig({ env })).toBe(true);
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
