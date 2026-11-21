import { readdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { delimiter, join } from 'node:path';
import type { AgentConfig } from './types.js';
import { configPath, defaultSkillsDir, defaultAvatarsDir, piModelsPath, type PathEnvironment } from './config.js';
import { ApiClient } from './http.js';
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
import { join as joinPath } from 'node:path';
import { isDirectory } from './util.js';

export interface DoctorCheck {
    name: string;
    ok: boolean;
    required: boolean;
    detail: string;
}

export interface DoctorReport {
    ok: boolean;
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
    fs?: {
        existsSync?: typeof existsSync;
        readdir?: typeof readdir;
        isDirectory?: typeof isDirectory;
    };
}

function findExecutable(name: string, env: NodeJS.ProcessEnv = process.env): string | null {
    for (const dir of (env.PATH ?? '').split(delimiter).filter(Boolean)) {
        for (const candidate of [name, `${name}.exe`]) {
            const path = join(dir, candidate);

            if (existsSync(path)) {
                return path;
            }
        }
    }

    return null;
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
        let cursor: string | undefined;
        let count = 0;

        do {
            const page = await client.listTools(cursor ? { cursor } : undefined);
            count += page.tools.length;
            cursor = page.nextCursor;
        } while (cursor);

        await client.close();

        return { ok: true, detail: `${count} MCP tool(s) available` };
    } catch (error) {
        return { ok: false, detail: `MCP handshake failed: ${(error as Error).message}` };
    } finally {
        clearTimeout(timer);
    }
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

    checks.push({
        name: 'Config',
        ok: Boolean(config),
        required: true,
        detail: config
            ? `${configPath(options)} (${config.url}, user ${config.user?.name ?? '?'})`
            : `not logged in — run "lanomat-agent login" (${configPath(options)})`,
    });

    if (config) {
        const api = new ApiClient({ baseUrl: config.url, token: config.token, fetchImpl: options.fetchImpl });

        try {
            const me = await api.me();

            checks.push({
                name: 'Server',
                ok: true,
                required: true,
                detail: `${me.server?.name ?? 'LANoMAT'} ${me.server?.version ?? ''} at ${me.server?.url ?? config.url} (${me.user?.role ?? 'user'})`.trim(),
            });
        } catch (error) {
            checks.push({
                name: 'Server',
                ok: false,
                required: true,
                detail: `cannot reach ${config.url}: ${(error as Error).message}`,
            });
        }

        if (options.checkMcp !== false) {
            const mcp = await checkMcpEndpoint(api);

            checks.push({ name: 'MCP endpoint', ...mcp, required: false });
        }
    } else {
        checks.push({ name: 'Server', ok: false, required: true, detail: 'skipped: no config' });
    }

    const piBinary = resolvePiBinary(env, platform);

    checks.push({
        name: 'pi',
        ok: true,
        required: false,
        detail: piBinary ? `binary at ${piBinary} (SDK also bundled)` : 'binary not on PATH; the bundled SDK is used',
    });

    let skillCount = 0;

    try {
        const entries = await (options.fs?.readdir ?? readdir)(skillsDir, { withFileTypes: true });

        for (const entry of entries) {
            if (entry.isDirectory() && entry.name.startsWith('lanomat-')) {
                try {
                    await (options.fs?.isDirectory ?? isDirectory)(joinPath(skillsDir, entry.name, '.lanomat-source'));

                    const marker = await import('node:fs/promises').then((fs) =>
                        fs.readFile(joinPath(skillsDir, entry.name, '.lanomat-source'), 'utf8'),
                    );

                    if (marker.includes('url')) {
                        skillCount += 1;
                    }
                } catch {
                    // directory without a LANoMAT marker
                }
            }
        }

        checks.push({
            name: 'Skills',
            ok: skillCount > 0,
            required: false,
            detail: skillCount > 0 ? `${skillCount} synced recipe skill(s) in ${skillsDir}` : `no synced skills — run "lanomat-agent skills sync" (${skillsDir})`,
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
        const smb = findExecutable('smbclient', env);

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
        envFile: joinPath(configPath(options), '..', 'venice.env'),
    });
    const models = await readModelsConfig(modelsPath).catch(() => null as ModelsConfig | null);
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

    const requiredOk = checks.filter((check) => check.required).every((check) => check.ok);

    return {
        ok: requiredOk,
        checks,
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

export function avatarDirForDoctor(options: PathEnvironment = {}): string {
    return defaultAvatarsDir(options);
}

export function modelFileExists(options: PathEnvironment = {}): boolean {
    return existsSync(piModelsPath(options));
}
