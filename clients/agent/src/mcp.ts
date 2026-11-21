import { readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { piMcpPath, type PathEnvironment } from './config.js';
import { ensureDir } from './util.js';

export type McpExposure = 'direct' | 'codemode' | 'deferred';

export const DEFAULT_SERVER_NAME = 'lanomat';
export const LANOMAT_TOKEN_PLACEHOLDER = '${LANOMAT_TOKEN}';

export interface McpServerEntry {
    url: string;
    headers?: Record<string, string>;
    description?: string;
    exposure?: McpExposure;
    [key: string]: unknown;
}

export interface McpConfig {
    mcpServers?: Record<string, McpServerEntry>;
    [key: string]: unknown;
}

export function buildMcpServerEntry(baseUrl: string, exposure: McpExposure = 'deferred'): McpServerEntry {
    return {
        url: `${baseUrl.replace(/\/+$/, '')}/mcp`,
        headers: {
            Authorization: `Bearer ${LANOMAT_TOKEN_PLACEHOLDER}`,
        },
        description: 'LANoMAT: events, teams, tournaments, files, game recipes and LAN install plans.',
        exposure,
    };
}

/** Merge one server into an existing mcp.json, keeping every other key and server untouched. */
export function mergeMcpConfig(existing: McpConfig | null, name: string, entry: McpServerEntry): McpConfig {
    const base: McpConfig = existing && typeof existing === 'object' ? { ...existing } : {};
    const servers = base.mcpServers && typeof base.mcpServers === 'object' ? { ...base.mcpServers } : {};

    servers[name] = entry;
    base.mcpServers = servers;

    return base;
}

export function parseMcpConfig(text: string, path: string): McpConfig {
    try {
        const parsed = JSON.parse(text) as unknown;

        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
            throw new Error('expected a JSON object');
        }

        return parsed as McpConfig;
    } catch (error) {
        throw new Error(`Cannot parse ${path}: ${(error as Error).message}`, { cause: error });
    }
}

export interface InstallMcpOptions extends PathEnvironment {
    url: string;
    serverName?: string;
    exposure?: McpExposure;
}

export interface InstallMcpResult {
    path: string;
    serverName: string;
    entry: McpServerEntry;
    created: boolean;
}

export async function installMcpServer(options: InstallMcpOptions): Promise<InstallMcpResult> {
    const path = piMcpPath(options);
    const name = options.serverName ?? DEFAULT_SERVER_NAME;
    const entry = buildMcpServerEntry(options.url, options.exposure ?? 'deferred');

    let existing: McpConfig | null = null;
    let created = true;

    try {
        existing = parseMcpConfig(await readFile(path, 'utf8'), path);
        created = false;
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
            throw error;
        }
    }

    const merged = mergeMcpConfig(existing, name, entry);

    await ensureDir(dirname(path), 0o700);
    await writeFile(path, `${JSON.stringify(merged, null, 2)}\n`, { mode: 0o644 });

    return { path, serverName: name, entry, created };
}

export function formatExportLine(token: string, platform: NodeJS.Platform = process.platform): string {
    const escaped = token.replace(/'/g, `'\\''`);

    if (platform === 'win32') {
        return `$env:LANOMAT_TOKEN = '${escaped.replace(/'/g, "''")}'`;
    }

    return `export LANOMAT_TOKEN='${escaped}'`;
}

/** The exact command used by `ask` when the SDK is unavailable. */
export function buildPiArgs(options: {
    prompt: string;
    skillsDir?: string;
    model?: string;
    provider?: string;
    print?: boolean;
}): string[] {
    const args: string[] = [];

    if (options.print !== false) {
        args.push('--print');
    }

    if (options.provider) {
        args.push('--provider', options.provider);
    }

    if (options.model) {
        args.push('--model', options.model);
    }

    if (options.skillsDir) {
        args.push('--skills', options.skillsDir);
    }

    args.push(options.prompt);

    return args;
}

export function piCommandLine(args: string[], platform: NodeJS.Platform = process.platform): string {
    const quote = (value: string): string => {
        if (/^[A-Za-z0-9_./:@-]+$/.test(value)) {
            return value;
        }

        return platform === 'win32' ? `"${value.replace(/"/g, '\\"')}"` : `'${value.replace(/'/g, `'\\''`)}'`;
    };

    return ['pi', ...args].map(quote).join(' ');
}

export function mcpConfigSummary(path: string, serverName: string): string {
    return `MCP server "${serverName}" written to ${path}`;
}

export function mcpConfigPathForDisplay(options: PathEnvironment = {}): string {
    return join(piMcpPath(options));
}
