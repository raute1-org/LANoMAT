import { chmod, readFile, readdir, rename, rm, stat, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import type { AgentConfig, ModelRoles, StoredUser } from './types.js';
import { ensureDir, writeFileAtomic } from './util.js';

/** Bumped when the on-disk shape changes. A file without `version` is treated as v1. */
export const CONFIG_VERSION = 2;

export const CONFIG_DIR_MODE = 0o700;
export const CONFIG_FILE_MODE = 0o600;

/** Suffix of the last known-good copy that a corrupt file falls back to. */
export const CONFIG_BACKUP_SUFFIX = '.bak';

/** How many quarantined corrupt files are kept next to `agent.json`. */
const CORRUPT_BACKUPS_TO_KEEP = 3;

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

export function configBackupPath(options: PathEnvironment = {}): string {
    return `${configPath(options)}${CONFIG_BACKUP_SUFFIX}`;
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

type ConfigDocument = Record<string, unknown>;

export interface ConfigLoadOutcome {
    config: AgentConfig | null;
    /** Version of the file that was found on disk; `null` when there was no readable file. */
    version: number | null;
    /** True when a v1 file was upgraded and written back as v2. */
    migrated: boolean;
    /** True when `agent.json` was unusable and restored from `agent.json.bak`. */
    recoveredFromBackup: boolean;
    /** Absolute path of the quarantined corrupt file, when recovery had to run. */
    corruptBackupPath?: string;
    /** True when group/other permission bits were removed from the file or directory. */
    permissionsRepaired: boolean;
}

function emptyOutcome(): ConfigLoadOutcome {
    return {
        config: null,
        version: null,
        migrated: false,
        recoveredFromBackup: false,
        permissionsRepaired: false,
    };
}

function isDocument(value: unknown): value is ConfigDocument {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

async function readDocument(path: string): Promise<
    { status: 'ok'; document: ConfigDocument } | { status: 'corrupt' } | { status: 'missing' }
> {
    let text: string;

    try {
        text = await readFile(path, 'utf8');
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
            return { status: 'missing' };
        }

        throw error;
    }

    try {
        const parsed: unknown = JSON.parse(text);

        return isDocument(parsed) ? { status: 'ok', document: parsed } : { status: 'corrupt' };
    } catch {
        return { status: 'corrupt' };
    }
}

function asString(value: unknown): string | undefined {
    return typeof value === 'string' && value.length > 0 ? value : undefined;
}

function normalizeUser(document: ConfigDocument): StoredUser {
    const raw = document.user;

    if (isDocument(raw) && (typeof raw.id === 'string' || typeof raw.id === 'number')) {
        return {
            id: raw.id,
            name: typeof raw.name === 'string' ? raw.name : String(raw.id),
        };
    }

    // v1 wrote `user_name`/`user_id` at the top level in some builds.
    return {
        id: typeof document.user_id === 'string' || typeof document.user_id === 'number'
            ? document.user_id
            : 'unknown',
        name: asString(document.user_name) ?? 'unknown',
    };
}

function normalizeRoles(value: unknown): ModelRoles | undefined {
    if (!isDocument(value)) {
        return undefined;
    }

    const roles: ModelRoles = {};

    for (const name of ['chat', 'decision', 'stt', 'tts'] as const) {
        const role = value[name];

        if (typeof role === 'string') {
            roles[name] = role;
        } else if (role === null) {
            roles[name] = null;
        }
    }

    return roles;
}

interface NormalizedDocument {
    config: AgentConfig;
    version: number;
    migrated: boolean;
}

/**
 * Validate and upgrade a parsed config document. Unknown keys stay in `document`; the returned
 * `config` carries only the known fields, so callers never see `version` in their config object.
 */
function normalizeDocument(document: ConfigDocument, path: string): NormalizedDocument {
    const url = asString(document.url);
    const token = asString(document.token);

    if (!url || !token) {
        throw new Error(
            `Invalid config at ${path}: expected url and token. Run "lanomat-agent login" again.`,
        );
    }

    const fromVersion = typeof document.version === 'number' ? document.version : 1;

    if (fromVersion > CONFIG_VERSION) {
        throw new Error(
            `Config at ${path} was written by a newer lanomat-agent (version ${fromVersion}; this agent understands ${CONFIG_VERSION}). Upgrade lanomat-agent or move the file away.`,
        );
    }

    const expires =
        typeof document.token_expires_at === 'string'
            ? document.token_expires_at
            : typeof document.expires_at === 'string'
              ? document.expires_at
              : '';
    const normalizedUrl = url.replace(/\/+$/, '');
    const roles = normalizeRoles(document.modelRoles);
    const config: AgentConfig = {
        url: normalizedUrl,
        token,
        token_expires_at: expires,
        user: normalizeUser(document),
        ...(asString(document.model) ? { model: asString(document.model) } : {}),
        ...(roles ? { modelRoles: roles } : {}),
    };

    // A v2 file always carries `token_expires_at`; its absence means the field came from v1
    // (or was missing entirely), which the migration writes back explicitly.
    const hadVersionedExpiry = typeof document.token_expires_at === 'string';

    return {
        config,
        version: fromVersion,
        migrated:
            fromVersion !== CONFIG_VERSION || normalizedUrl !== url || !hadVersionedExpiry,
    };
}

/** Fields that only exist in v1 files; they are consumed by the migration. */
const LEGACY_FIELDS = ['expires_at', 'user_id', 'user_name'] as const;

function documentForSave(config: AgentConfig, existing: ConfigDocument | null): ConfigDocument {
    const document: ConfigDocument = { ...(existing ?? {}), ...config, version: CONFIG_VERSION };

    for (const field of LEGACY_FIELDS) {
        delete document[field];
    }

    return document;
}

function corruptBackupName(path: string, clock: () => Date): string {
    const stamp = clock().toISOString().replace(/[:.]/g, '-');

    return `${path}.corrupt-${stamp}`;
}

async function pruneCorruptBackups(dir: string, keep = CORRUPT_BACKUPS_TO_KEEP): Promise<void> {
    try {
        const prefix = `agent.json.corrupt-`;
        const entries = (await readdir(dir)).filter((name) => name.startsWith(prefix)).sort();

        for (const name of entries.slice(0, Math.max(0, entries.length - keep))) {
            await rm(join(dir, name), { force: true }).catch(() => undefined);
        }
    } catch {
        // Pruning is best effort; a missing directory is not an error here.
    }
}

async function quarantineCorrupt(path: string, clock: () => Date): Promise<string> {
    const target = corruptBackupName(path, clock);

    try {
        await rename(path, target);
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
            return target;
        }

        // Cross-device or a locked file: copy instead of rename.
        await writeFile(target, await readFile(path), { mode: CONFIG_FILE_MODE });
        await rm(path, { force: true });
    }

    await chmod(target, CONFIG_FILE_MODE).catch(() => undefined);
    await pruneCorruptBackups(dirname(path));

    return target;
}

async function backupValidConfig(path: string): Promise<void> {
    const document = await readDocument(path);

    if (document.status !== 'ok') {
        return;
    }

    const backup = `${path}${CONFIG_BACKUP_SUFFIX}`;

    await writeFileAtomic(backup, `${JSON.stringify(document.document, null, 2)}\n`, CONFIG_FILE_MODE);
}

async function repairPermissions(options: PathEnvironment): Promise<boolean> {
    const dir = configDir(options);
    const path = configPath(options);
    let repaired = false;

    try {
        const dirMode = (await stat(dir)).mode & 0o777;

        if ((dirMode & 0o077) !== 0) {
            await chmod(dir, CONFIG_DIR_MODE);
            repaired = true;
        }
    } catch {
        // Missing directory is created by saveConfig.
    }

    try {
        const fileMode = (await stat(path)).mode & 0o777;

        if ((fileMode & 0o077) !== 0) {
            await chmod(path, CONFIG_FILE_MODE);
            repaired = true;
        }
    } catch {
        // Missing file is fine.
    }

    return repaired;
}

/**
 * Read `agent.json`, migrating v1 files, quarantining corrupt JSON and restoring the last
 * good backup. The caller gets the same `AgentConfig` shape regardless of the file version.
 */
export async function loadConfigDetailed(
    options: PathEnvironment = {},
    clock: () => Date = () => new Date(),
): Promise<ConfigLoadOutcome> {
    const path = configPath(options);
    const outcome = emptyOutcome();
    let document = await readDocument(path);

    if (document.status === 'corrupt') {
        outcome.corruptBackupPath = await quarantineCorrupt(path, clock);
        document = await readDocument(`${path}${CONFIG_BACKUP_SUFFIX}`);

        if (document.status !== 'ok') {
            return outcome;
        }

        outcome.recoveredFromBackup = true;
    }

    if (document.status === 'missing') {
        return outcome;
    }

    let normalized: NormalizedDocument;

    try {
        normalized = normalizeDocument(document.document, path);
    } catch (error) {
        // A structurally invalid backup must not make the CLI unusable: treat it as signed out.
        if (outcome.recoveredFromBackup) {
            return outcome;
        }

        throw error;
    }

    outcome.version = normalized.version;
    outcome.migrated = normalized.migrated;
    outcome.permissionsRepaired = await repairPermissions(options);

    if (outcome.migrated || outcome.recoveredFromBackup) {
        await backupValidConfig(path);
        await ensureDir(configDir(options), CONFIG_DIR_MODE);
        await writeFileAtomic(
            path,
            `${JSON.stringify(documentForSave(normalized.config, document.document), null, 2)}\n`,
            CONFIG_FILE_MODE,
        );
    }

    outcome.config = normalized.config;

    return outcome;
}

export async function loadConfig(options: PathEnvironment = {}): Promise<AgentConfig | null> {
    return (await loadConfigDetailed(options)).config;
}

export async function saveConfig(config: AgentConfig, options: PathEnvironment = {}): Promise<string> {
    const dir = configDir(options);
    const path = configPath(options);

    await ensureDir(dir, CONFIG_DIR_MODE);
    const existing = await readDocument(path);
    const previous = existing.status === 'ok' ? existing.document : null;

    if (existing.status === 'ok') {
        await backupValidConfig(path);
    } else if (existing.status === 'corrupt') {
        await quarantineCorrupt(path, () => new Date());
    }

    await writeFileAtomic(
        path,
        `${JSON.stringify(documentForSave(config, previous), null, 2)}\n`,
        CONFIG_FILE_MODE,
    );
    await chmod(path, CONFIG_FILE_MODE);
    await chmod(dir, CONFIG_DIR_MODE).catch(() => undefined);

    return path;
}

export async function deleteConfig(options: PathEnvironment = {}): Promise<boolean> {
    const path = configPath(options);
    const dir = dirname(path);
    let removed = false;

    try {
        await rm(path, { force: false });
        removed = true;
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
            throw error;
        }
    }

    // The token must not survive `logout` in a backup or quarantine file.
    for (const suffix of [CONFIG_BACKUP_SUFFIX]) {
        try {
            await rm(`${path}${suffix}`, { force: false });
            removed = true;
        } catch (error) {
            if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
                throw error;
            }
        }
    }

    try {
        const entries = await readdir(dir);

        for (const name of entries.filter((entry) => entry.startsWith('agent.json.corrupt-'))) {
            await rm(join(dir, name), { force: true });
            removed = true;
        }
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
            throw error;
        }
    }

    return removed;
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

/** Tighten an existing config to 0600/0700. Returns true when something changed. */
export async function enforceConfigPermissions(options: PathEnvironment = {}): Promise<boolean> {
    return repairPermissions(options);
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

/** Kept for callers that read a config document without the typed view. */
export async function readConfigDocument(
    options: PathEnvironment = {},
): Promise<Record<string, unknown> | null> {
    const document = await readDocument(configPath(options));

    return document.status === 'ok' ? document.document : null;
}
