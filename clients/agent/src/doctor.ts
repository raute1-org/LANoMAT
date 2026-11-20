import { access, readFile, readdir } from 'node:fs/promises';
import { constants, existsSync } from 'node:fs';
import { delimiter, dirname, join } from 'node:path';
import type { AgentConfig } from './types.js';
import {
    configPath,
    defaultAvatarsDir,
    defaultSkillsDir,
    inspectConfigPermissions,
    isTokenExpired,
    modeToString,
    piModelsPath,
    type PathEnvironment,
} from './config.js';
import { ApiClient, ApiError, redactSecret } from './http.js';
import {
    DEFAULT_FALLBACK_MODEL,
    discoverModels,
    probeToolCall,
    readModelsConfig,
    resolveApiKey,
    resolveModelRoles,
    resolveModelWithConfig,
    type ModelsConfig,
} from './models.js';
import { resolvePiBinary } from './ask.js';
import type { isDirectory } from './util.js';

/**
 * Stable identifier for `lanomat-agent doctor --json`. Additive fields may appear in a later
 * version, but a consumer can rely on every field of this schema being present and named.
 */
export const DOCTOR_SCHEMA = 'lanomat-agent-doctor/1';

export interface DoctorCheck {
    name: string;
    ok: boolean;
    required: boolean;
    detail: string;
}

export interface DoctorReport {
    /** `lanomat-agent-doctor/1`; the field order is stable for snapshots and shell parsing. */
    schema: typeof DOCTOR_SCHEMA;
    generated_at: string;
    ok: boolean;
    summary: {
        total: number;
        passed: number;
        failed: number;
        warnings: number;
    };
    checks: DoctorCheck[];
    model: {
        provider: string;
        model: string;
        source: string;
    };
    roles: Array<{ name: string; value: string | null; source: string }>;
}

export interface DoctorOptions extends PathEnvironment {
    config: AgentConfig | null;
    fetchImpl?: typeof fetch;
    /** Skip the MCP handshake in tests. */
    checkMcp?: boolean;
    checkProbe?: boolean;
    checkVeniceModels?: boolean;
    clock?: () => Date;
    /** Resolves whether `@earendil-works/pi-coding-agent` is importable. Injectable for tests. */
    sdkAvailable?: () => Promise<boolean>;
    fs?: {
        existsSync?: typeof existsSync;
        readdir?: typeof readdir;
        isDirectory?: typeof isDirectory;
        access?: typeof access;
    };
}

/** A URL that is safe to print: no credentials, query string or fragment. */
export function safeUrl(input: string): string {
    try {
        const url = new URL(input);

        url.username = '';
        url.password = '';
        url.search = '';
        url.hash = '';

        return url.toString().replace(/\/+$/, '');
    } catch {
        return input.replace(/\/\/[^/@\s]+@/, '//');
    }
}

function findExecutable(
    name: string,
    env: NodeJS.ProcessEnv = process.env,
    exists: typeof existsSync = existsSync,
): string | null {
    for (const dir of (env.PATH ?? '').split(delimiter).filter(Boolean)) {
        for (const candidate of [name, `${name}.exe`]) {
            const path = join(dir, candidate);

            if (exists(path)) {
                return path;
            }
        }
    }

    return null;
}

function serverFailure(error: unknown, url: string): { ok: false; detail: string } {
    const target = safeUrl(url);

    if (error instanceof ApiError) {
        if (error.status === 401) {
            return {
                ok: false,
                detail: `server reachable at ${target}, but the token was rejected (HTTP 401) — run "lanomat-agent login"`,
            };
        }

        if (error.status === 0 || error.code === 'network_error') {
            return { ok: false, detail: `offline — cannot reach ${target}: ${error.message}` };
        }

        return {
            ok: false,
            detail: `server error at ${target} (HTTP ${error.status}): ${error.message}`,
        };
    }

    return { ok: false, detail: `cannot reach ${target}: ${(error as Error).message}` };
}

async function checkMcpEndpoint(api: ApiClient): Promise<{ ok: boolean; detail: string }> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 8000);

    try {
        const { Client } = await import('@modelcontextprotocol/sdk/client/index.js');
        const { StreamableHTTPClientTransport } = await import(
            '@modelcontextprotocol/sdk/client/streamableHttp.js'
        );
        const transport = new StreamableHTTPClientTransport(new URL(`${api.baseUrl}/mcp`), {
            requestInit: {
                headers: api.authHeaders(),
                signal: controller.signal,
            },
            redirectPolicy: 'follow',
        });
        const client = new Client({ name: 'lanomat-agent-doctor', version: '0.1.0' });

        await client.connect(transport);
        const tools = await client.listTools();
        await client.close();

        return { ok: true, detail: `${tools.tools.length} MCP tool(s) available` };
    } catch (error) {
        const message = (error as Error).message;

        if (((error as { status?: number }).status ?? 0) === 401 || /\b401\b|unauthori[sz]ed/i.test(message)) {
            return { ok: false, detail: 'MCP handshake rejected the token (HTTP 401) — run "lanomat-agent login"' };
        }

        return { ok: false, detail: `MCP handshake failed: ${message}` };
    } finally {
        clearTimeout(timer);
    }
}

async function canImportPiSdk(): Promise<boolean> {
    try {
        await import('@earendil-works/pi-coding-agent');

        return true;
    } catch {
        return false;
    }
}

function redact(text: string, secrets: Array<string | undefined>): string {
    let safe = text;

    for (const secret of secrets) {
        if (secret) {
            safe = redactSecret(safe, secret);
        }
    }

    return safe;
}

function collectSecrets(config: AgentConfig | null, key: string | undefined, env: NodeJS.ProcessEnv): string[] {
    return [
        config?.token,
        key,
        env.VENICE_API_KEY,
        env.OPENAI_MODEL_API_KEY,
        env.LANOMAT_TOKEN,
    ].filter((value): value is string => typeof value === 'string' && value.length > 0);
}

export async function runDoctor(options: DoctorOptions): Promise<DoctorReport> {
    const env = options.env ?? process.env;
    const checks: DoctorCheck[] = [];
    const config = options.config;
    const resolved = resolveModelWithConfig({ env, config });
    const roles = resolveModelRoles(config, env);
    const modelsPath = piModelsPath(options);
    const skillsDir = defaultSkillsDir(options);
    const platform = options.platform ?? process.platform;
    const exists = options.fs?.existsSync ?? existsSync;
    const accessFn = options.fs?.access ?? access;

    checks.push({
        name: 'Config',
        ok: Boolean(config),
        required: true,
        detail: config
            ? `${configPath(options)} (${safeUrl(config.url)}, user ${config.user?.name ?? '?'})`
            : `not logged in — run "lanomat-agent login" (${configPath(options)})`,
    });

    const permissions = await inspectConfigPermissions(options);
    const posixPermissions = platform !== 'win32';

    checks.push({
        name: 'Config permissions',
        ok: posixPermissions ? permissions.secure : permissions.configExists,
        required: false,
        detail: !posixPermissions
            ? `Windows ACLs govern access (${configPath(options)})`
            : permissions.configExists
              ? `dir ${modeToString(permissions.dirMode)}, file ${modeToString(permissions.fileMode)}${
                    permissions.secure ? '' : ' — run "lanomat-agent login" to tighten to 0700/0600'
                }`
              : `no config file yet (${configPath(options)})`,
    });

    if (!config) {
        checks.push({ name: 'Token', ok: false, required: false, detail: 'no token — run "lanomat-agent login"' });
    } else if (isTokenExpired(config, options.clock)) {
        checks.push({
            name: 'Token',
            ok: false,
            required: false,
            detail: `expired at ${config.token_expires_at} — run "lanomat-agent login"`,
        });
    } else {
        checks.push({
            name: 'Token',
            ok: true,
            required: false,
            detail: config.token_expires_at ? `valid until ${config.token_expires_at}` : 'no expiry recorded',
        });
    }

    let api: ApiClient | null = null;

    if (config) {
        api = new ApiClient({ baseUrl: config.url, token: config.token, fetchImpl: options.fetchImpl });

        try {
            const me = await api.me();

            checks.push({
                name: 'Server',
                ok: true,
                required: true,
                detail: `${me.server?.name ?? 'LANoMAT'} ${me.server?.version ?? ''} at ${safeUrl(
                    me.server?.url ?? config.url,
                )} (${me.user?.role ?? 'user'})`.trim(),
            });
        } catch (error) {
            const failure = serverFailure(error, config.url);

            checks.push({
                name: 'Server',
                ok: failure.ok,
                required: true,
                detail: failure.detail,
            });
        }
    } else {
        checks.push({ name: 'Server', ok: false, required: true, detail: 'skipped: no config' });
    }

    if (config && api && options.checkMcp !== false) {
        const mcp = await checkMcpEndpoint(api);

        checks.push({ name: 'MCP endpoint', ok: mcp.ok, required: false, detail: mcp.detail });
    }

    const piBinary = resolvePiBinary(env, platform);
    const sdkAvailable = await (options.sdkAvailable ?? canImportPiSdk)();

    checks.push({
        name: 'pi',
        ok: Boolean(piBinary) || sdkAvailable,
        required: false,
        detail: piBinary
            ? `binary at ${piBinary}${sdkAvailable ? ' (SDK also bundled)' : ''}`
            : sdkAvailable
              ? 'binary not on PATH; the bundled pi SDK is used'
              : 'pi not found — npm install -g @earendil-works/pi-coding-agent (or reinstall the agent)',
    });

    let skillCount = 0;

    try {
        const entries = await (options.fs?.readdir ?? readdir)(skillsDir, { withFileTypes: true });

        for (const entry of entries) {
            if (entry.isDirectory() && entry.name.startsWith('lanomat-')) {
                try {
                    const marker = await readFile(join(skillsDir, entry.name, '.lanomat-source'), 'utf8');

                    if (marker.includes('url')) {
                        skillCount += 1;
                    }
                } catch {
                    // directory without a LANoMAT marker
                }
            }
        }

        let writable = true;

        try {
            await accessFn(skillsDir, constants.W_OK);
        } catch {
            writable = false;
        }

        checks.push({
            name: 'Skills',
            ok: writable,
            required: false,
            detail: writable
                ? `${skillCount > 0 ? `${skillCount} synced recipe skill(s)` : 'no synced skills yet'} in ${skillsDir} (writable)`
                : `${skillsDir} is not writable — fix its permissions or run "lanomat-agent skills sync --dest <dir>"`,
        });
    } catch {
        checks.push({
            name: 'Skills',
            ok: false,
            required: false,
            detail: `skills directory missing — run "lanomat-agent skills sync" (${skillsDir})`,
        });
    }

    if (platform === 'linux') {
        const smb = findExecutable('smbclient', env, exists);

        checks.push({
            name: 'smbclient',
            ok: Boolean(smb),
            required: false,
            detail: smb ? smb : 'smbclient not found; LAN share copies (SMB) may need it (pacman -S samba)',
        });
    }

    const key = await resolveApiKey({
        provider: resolved.provider,
        env,
        envFile: join(dirname(configPath(options)), 'venice.env'),
    });
    let models: ModelsConfig | null = null;
    let modelsError: string | null = null;

    try {
        models = await readModelsConfig(modelsPath);
    } catch (error) {
        modelsError = (error as Error).message;
    }

    const providerConfigured = Boolean(models?.providers?.[resolved.provider]);

    checks.push({
        name: 'Venice key',
        ok: key.source !== 'none',
        required: false,
        detail:
            key.source === 'none'
                ? 'VENICE_API_KEY not set — run "lanomat-agent model setup"'
                : `set via ${key.source === 'file' ? key.file : key.envName}`,
    });

    checks.push({
        name: 'models.json',
        ok: providerConfigured,
        required: false,
        detail: providerConfigured
            ? `provider "${resolved.provider}" configured at ${modelsPath}`
            : modelsError
              ? `cannot parse ${modelsPath}: ${modelsError}`
              : `provider "${resolved.provider}" missing — run "lanomat-agent model setup" (${modelsPath})`,
    });

    if (options.checkVeniceModels !== false) {
        const discovery = await discoverModels({
            baseUrl: resolved.baseUrl,
            apiKey: key.key,
            fetchImpl: options.fetchImpl,
        });

        checks.push({
            name: 'Model catalog',
            ok: !discovery.offline,
            required: false,
            detail: discovery.offline
                ? discovery.warning ?? 'unreachable (offline is fine)'
                : `${discovery.models.length} text model(s) reachable`,
        });
    }

    if (options.checkProbe !== false && key.key) {
        const probe = await probeToolCall({
            baseUrl: resolved.baseUrl,
            apiKey: key.key,
            model: resolved.model,
            fetchImpl: options.fetchImpl,
        });

        checks.push({
            name: 'Tool calls',
            ok: probe.ok,
            required: false,
            detail: `${probe.detail} (${resolved.full})`,
        });

        if (!probe.ok && resolved.provider === 'venice') {
            const fallback = await probeToolCall({
                baseUrl: resolved.baseUrl,
                apiKey: key.key,
                model: DEFAULT_FALLBACK_MODEL,
                fetchImpl: options.fetchImpl,
            });

            checks.push({
                name: 'Tool calls (fallback)',
                ok: fallback.ok,
                required: false,
                detail: `${fallback.detail} (venice/${DEFAULT_FALLBACK_MODEL})`,
            });
        }
    }

    const secrets = collectSecrets(config, key.key, env);
    const safeChecks = checks.map((check) => ({ ...check, detail: redact(check.detail, secrets) }));
    const requiredOk = safeChecks.filter((check) => check.required).every((check) => check.ok);
    const failed = safeChecks.filter((check) => !check.ok && check.required).length;
    const warnings = safeChecks.filter((check) => !check.ok && !check.required).length;

    return {
        schema: DOCTOR_SCHEMA,
        generated_at: (options.clock ?? (() => new Date()))().toISOString(),
        ok: requiredOk,
        summary: {
            total: safeChecks.length,
            passed: safeChecks.filter((check) => check.ok).length,
            failed,
            warnings,
        },
        checks: safeChecks,
        model: {
            provider: resolved.provider,
            model: resolved.full,
            source: resolved.source,
        },
        roles,
    };
}

export function formatDoctorReport(report: DoctorReport): string {
    const lines: string[] = [];

    for (const check of report.checks) {
        lines.push(`${check.ok ? '✔' : check.required ? '✖' : '!'} ${check.name}: ${check.detail}`);
    }

    lines.push('');
    lines.push(`Model: ${report.model.model} (source: ${report.model.source})`);
    lines.push('Roles:');

    for (const role of report.roles) {
        lines.push(`  ${role.name.padEnd(8)} ${role.value ?? '(unset)'}  [${role.source}]`);
    }

    lines.push('');
    lines.push(report.ok ? 'Doctor: OK' : 'Doctor: attention needed');

    return lines.join('\n');
}

/** The exact payload of `lanomat-agent doctor --json`, with two-space indentation. */
export function formatDoctorJson(report: DoctorReport): string {
    return `${JSON.stringify(report, null, 2)}\n`;
}

export function avatarDirForDoctor(options: PathEnvironment = {}): string {
    return defaultAvatarsDir(options);
}

export function modelFileExists(options: PathEnvironment = {}): boolean {
    return existsSync(piModelsPath(options));
}
