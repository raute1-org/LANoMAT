import { chmod, rm, stat } from 'node:fs/promises';
import { homedir } from 'node:os';
import { join, resolve } from 'node:path';
import type { AgentConfig } from './types.js';
import { ensureDir, readJsonFile, writeFileAtomic } from './util.js';

export interface PathEnvironment {
    env?: NodeJS.ProcessEnv;
    home?: string;
    platform?: NodeJS.Platform;
}

function envOf(options: PathEnvironment): NodeJS.ProcessEnv {
    return options.env ?? process.env;
}

function homeOf(options: PathEnvironment): string {
    return options.home ?? homedir();
}

/** `$LANOMAT_CONFIG_DIR`, else `$XDG_CONFIG_HOME/lanomat`, else `~/.config/lanomat`. */
export function configDir(options: PathEnvironment = {}): string {
    const env = envOf(options);

    if (env.LANOMAT_CONFIG_DIR) {
        return resolve(env.LANOMAT_CONFIG_DIR);
    }

    const base = env.XDG_CONFIG_HOME || join(homeOf(options), '.config');

    return join(base, 'lanomat');
}

export function configPath(options: PathEnvironment = {}): string {
    return join(configDir(options), 'agent.json');
}

export function piAgentDir(options: PathEnvironment = {}): string {
    const env = envOf(options);

    if (env.LANOMAT_PI_DIR) {
        return resolve(env.LANOMAT_PI_DIR);
    }

    return join(homeOf(options), '.pi', 'agent');
}

export function piMcpPath(options: PathEnvironment = {}): string {
    return join(piAgentDir(options), 'mcp.json');
}

export function piModelsPath(options: PathEnvironment = {}): string {
    return join(piAgentDir(options), 'models.json');
}

export function piSettingsPath(options: PathEnvironment = {}): string {
    return join(piAgentDir(options), 'settings.json');
}

export function defaultSkillsDir(options: PathEnvironment = {}): string {
    const env = envOf(options);

    if (env.LANOMAT_SKILLS_DIR) {
        return resolve(env.LANOMAT_SKILLS_DIR);
    }

    return join(homeOf(options), '.agents', 'skills');
}

/**
 * Avatar storage. Linux follows XDG (`~/.local/share/lanomat/avatars`), Windows uses
 * `%LOCALAPPDATA%\LANoMAT\avatars`. `LANOMAT_AVATARS_DIR` overrides both.
 */
export function defaultAvatarsDir(options: PathEnvironment = {}): string {
    const env = envOf(options);
    const platform = options.platform ?? process.platform;

    if (env.LANOMAT_AVATARS_DIR) {
        return resolve(env.LANOMAT_AVATARS_DIR);
    }

    if (platform === 'win32') {
        const localAppData = env.LOCALAPPDATA || join(homeOf(options), 'AppData', 'Local');

        return join(localAppData, 'LANoMAT', 'avatars');
    }

    const dataHome = env.XDG_DATA_HOME || join(homeOf(options), '.local', 'share');

    return join(dataHome, 'lanomat', 'avatars');
}

export async function loadConfig(options: PathEnvironment = {}): Promise<AgentConfig | null> {
    const path = configPath(options);
    const parsed = await readJsonFile<AgentConfig>(path);

    if (parsed === null) {
        return null;
    }

    if (typeof parsed.url !== 'string' || typeof parsed.token !== 'string') {
        throw new Error(`Invalid config at ${path}: expected url and token. Run "lanomat-agent login" again.`);
    }

    return parsed;
}

export async function saveConfig(config: AgentConfig, options: PathEnvironment = {}): Promise<string> {
    const dir = configDir(options);
    const path = configPath(options);

    await ensureDir(dir, 0o700);
    await writeFileAtomic(path, `${JSON.stringify(config, null, 2)}\n`, 0o600);
    await chmod(path, 0o600);

    return path;
}

export async function deleteConfig(options: PathEnvironment = {}): Promise<boolean> {
    const path = configPath(options);

    try {
        await rm(path, { force: false });

        return true;
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
            return false;
        }

        throw error;
    }
}

export interface ConfigPermissions {
    configExists: boolean;
    dirMode?: number;
    fileMode?: number;
    secure: boolean;
}

export async function inspectConfigPermissions(options: PathEnvironment = {}): Promise<ConfigPermissions> {
    const dir = configDir(options);
    const path = configPath(options);

    try {
        const [dirStat, fileStat] = await Promise.all([stat(dir), stat(path)]);
        const dirMode = dirStat.mode & 0o777;
        const fileMode = fileStat.mode & 0o777;

        return {
            configExists: true,
            dirMode,
            fileMode,
            secure: (dirMode & 0o077) === 0 && (fileMode & 0o077) === 0,
        };
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
            return { configExists: false, secure: false };
        }

        throw error;
    }
}

export function isTokenExpired(config: AgentConfig, clock: () => Date = () => new Date()): boolean {
    if (!config.token_expires_at) {
        return false;
    }

    const expires = Date.parse(config.token_expires_at);

    if (Number.isNaN(expires)) {
        return false;
    }

    return expires <= clock().getTime();
}

export function modeToString(mode: number | undefined): string {
    return mode === undefined ? 'missing' : mode.toString(8).padStart(4, '0');
}
