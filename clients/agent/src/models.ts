import { readFile } from 'node:fs/promises';
import type { AgentConfig, ModelRoles } from './types.js';
import { piModelsPath, piSettingsPath, type PathEnvironment } from './config.js';
import { ensureDir, readJsonFile, writeFileAtomic } from './util.js';
import { redactSecret } from './http.js';

export const DEFAULT_VENICE_BASE_URL = 'https://api.venice.ai/api/v1';
export const DEFAULT_VENICE_MODEL = 'e2ee-deepseek-v4-1-flash-1017-abliterated';
export const DEFAULT_VENICE_MODEL_FULL = `venice/${DEFAULT_VENICE_MODEL}`;
export const DEFAULT_FALLBACK_MODEL = 'zai-org-glm-5';
export const VENICE_MODELS_SCHEMA = 'https://pi.dev/schemas/models.schema.json';
export const PI_SETTINGS_SCHEMA = 'https://pi.dev/schemas/settings.schema.json';

export type ModelSource = 'cli' | 'env-lanomat' | 'env-openai' | 'config' | 'default';

export interface ResolvedModel {
    provider: string;
    model: string;
    full: string;
    source: ModelSource;
    baseUrl: string;
    /** Environment variable that holds the key, e.g. VENICE_API_KEY. Never the key itself. */
    apiKeyEnv?: string;
}

export interface ModelResolutionInput {
    cliModel?: string;
    cliProvider?: string;
    env?: NodeJS.ProcessEnv;
    savedModel?: string;
}

export function inferProvider(baseUrl: string): string {
    try {
        const host = new URL(baseUrl).host;

        return host === 'api.venice.ai' || host.endsWith('.venice.ai') ? 'venice' : 'lanomat-openai';
    } catch {
        return 'lanomat-openai';
    }
}

export function splitModelRef(value: string, fallbackProvider = 'venice'): { provider: string; model: string } {
    const slash = value.indexOf('/');

    if (slash > 0) {
        return { provider: value.slice(0, slash), model: value.slice(slash + 1) };
    }

    return { provider: fallbackProvider, model: value };
}

function baseUrlForProvider(provider: string, env: NodeJS.ProcessEnv): string {
    if (provider === 'venice') {
        return env.OPENAI_MODEL_BASE_URL || DEFAULT_VENICE_BASE_URL;
    }

    return env.OPENAI_MODEL_BASE_URL || DEFAULT_VENICE_BASE_URL;
}

function keyEnvFor(provider: string, env: NodeJS.ProcessEnv): string | undefined {
    if (env.OPENAI_MODEL_API_KEY) {
        return 'OPENAI_MODEL_API_KEY';
    }

    if (provider === 'venice' || env.VENICE_API_KEY) {
        return 'VENICE_API_KEY';
    }

    return undefined;
}

/**
 * The single place that decides which provider/model the agent talks to.
 *
 * CLI flags > LANOMAT_AGENT_MODEL > OPENAI_MODEL_* > saved agent.json model > built-in Venice default.
 */
export function resolveModel(input: ModelResolutionInput = {}): ResolvedModel {
    const env = input.env ?? process.env;
    const cliProvider = input.cliProvider;

    if (input.cliModel) {
        const parsed = splitModelRef(input.cliModel, cliProvider ?? 'venice');

        return {
            ...parsed,
            full: `${parsed.provider}/${parsed.model}`,
            source: 'cli',
            baseUrl: baseUrlForProvider(parsed.provider, env),
            apiKeyEnv: keyEnvFor(parsed.provider, env),
        };
    }

    if (cliProvider) {
        const model = env.LANOMAT_AGENT_MODEL
            ? splitModelRef(env.LANOMAT_AGENT_MODEL, cliProvider).model
            : env.OPENAI_MODEL_NAME || DEFAULT_VENICE_MODEL;

        return {
            provider: cliProvider,
            model,
            full: `${cliProvider}/${model}`,
            source: 'cli',
            baseUrl: baseUrlForProvider(cliProvider, env),
            apiKeyEnv: keyEnvFor(cliProvider, env),
        };
    }

    if (env.LANOMAT_AGENT_MODEL) {
        const parsed = splitModelRef(env.LANOMAT_AGENT_MODEL);

        return {
            ...parsed,
            full: `${parsed.provider}/${parsed.model}`,
            source: 'env-lanomat',
            baseUrl: baseUrlForProvider(parsed.provider, env),
            apiKeyEnv: keyEnvFor(parsed.provider, env),
        };
    }

    if (env.OPENAI_MODEL_NAME && env.OPENAI_MODEL_BASE_URL) {
        const provider = env.OPENAI_MODEL_PROVIDER || inferProvider(env.OPENAI_MODEL_BASE_URL);

        return {
            provider,
            model: env.OPENAI_MODEL_NAME,
            full: `${provider}/${env.OPENAI_MODEL_NAME}`,
            source: 'env-openai',
            baseUrl: env.OPENAI_MODEL_BASE_URL,
            apiKeyEnv: keyEnvFor(provider, env),
        };
    }

    if (input.savedModel) {
        const parsed = splitModelRef(input.savedModel);

        return {
            ...parsed,
            full: `${parsed.provider}/${parsed.model}`,
            source: 'config',
            baseUrl: baseUrlForProvider(parsed.provider, env),
            apiKeyEnv: keyEnvFor(parsed.provider, env),
        };
    }

    return {
        provider: 'venice',
        model: DEFAULT_VENICE_MODEL,
        full: DEFAULT_VENICE_MODEL_FULL,
        source: 'default',
        baseUrl: env.OPENAI_MODEL_BASE_URL || DEFAULT_VENICE_BASE_URL,
        apiKeyEnv: keyEnvFor('venice', env),
    };
}

export function resolveModelWithConfig(input: {
    cliModel?: string;
    cliProvider?: string;
    env?: NodeJS.ProcessEnv;
    config?: AgentConfig | null;
}): ResolvedModel {
    return resolveModel({
        cliModel: input.cliModel,
        cliProvider: input.cliProvider,
        env: input.env,
        savedModel: input.config?.modelRoles?.chat ?? input.config?.model,
    });
}

export const MODEL_ROLE_NAMES = ['chat', 'decision', 'stt', 'tts'] as const;

export type ModelRoleName = (typeof MODEL_ROLE_NAMES)[number];

export interface ModelRoleView {
    name: ModelRoleName;
    value: string | null;
    source: 'config' | 'default' | 'null';
}

/** Roles besides `chat` are configuration only until a non-text runtime exists. */
export function resolveModelRoles(
    config: AgentConfig | null | undefined,
    env: NodeJS.ProcessEnv = process.env,
): ModelRoleView[] {
    const defaults = resolveModel({ env });

    return MODEL_ROLE_NAMES.map((name) => {
        const saved = config?.modelRoles?.[name];

        if (name === 'chat') {
            const value = saved ?? config?.model ?? defaults.full;

            return { name, value, source: saved || config?.model ? 'config' : 'default' };
        }

        return saved
            ? { name, value: saved, source: 'config' }
            : { name, value: null, source: 'null' };
    });
}

export function mergeModelRoles(existing: ModelRoles | undefined, updates: Partial<ModelRoles>): ModelRoles {
    return {
        chat: updates.chat ?? existing?.chat ?? null,
        decision: updates.decision ?? existing?.decision ?? null,
        stt: updates.stt ?? existing?.stt ?? null,
        tts: updates.tts ?? existing?.tts ?? null,
    };
}

export interface DiscoveredModel {
    id: string;
    name?: string;
}

export interface DiscoverModelsResult {
    models: DiscoveredModel[];
    offline: boolean;
    warning?: string;
}

/** `GET {baseUrl}/models` (text models). Never fails the caller: offline is a normal outcome. */
export async function discoverModels(options: {
    baseUrl: string;
    apiKey?: string;
    fetchImpl?: typeof fetch;
    timeoutMs?: number;
}): Promise<DiscoverModelsResult> {
    const fetchImpl = options.fetchImpl ?? fetch;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), options.timeoutMs ?? 10_000);

    try {
        const headers: Record<string, string> = { Accept: 'application/json' };

        if (options.apiKey) {
            headers.Authorization = `Bearer ${options.apiKey}`;
        }

        const response = await fetchImpl(`${options.baseUrl.replace(/\/+$/, '')}/models?type=text`, {
            headers,
            signal: controller.signal,
        });

        if (!response.ok) {
            return {
                models: [],
                offline: true,
                warning: `Model discovery failed (${response.status}); using the requested/default model only.`,
            };
        }

        const payload = (await response.json()) as { data?: unknown };
        const data = Array.isArray(payload.data) ? payload.data : [];
        const models = data
            .map((entry) => entry as Record<string, unknown>)
            .filter((entry) => typeof entry.id === 'string' && entry.id.length > 0)
            .map((entry) => ({
                id: entry.id as string,
                name:
                    typeof entry.name === 'string'
                        ? entry.name
                        : typeof (entry.model_spec as Record<string, unknown> | undefined)?.name === 'string'
                          ? ((entry.model_spec as Record<string, unknown>).name as string)
                          : undefined,
            }));

        if (models.length === 0) {
            return {
                models: [],
                offline: true,
                warning: 'Model discovery returned no text models; using the requested/default model only.',
            };
        }

        return { models, offline: false };
    } catch (error) {
        return {
            models: [],
            offline: true,
            warning: `Model discovery offline (${(error as Error).message}); using the requested/default model only.`,
        };
    } finally {
        clearTimeout(timer);
    }
}

export interface PiProviderModel {
    id: string;
    name?: string;
    [key: string]: unknown;
}

export interface PiProvider {
    baseUrl: string;
    api: 'openai-completions';
    apiKey: string;
    models: PiProviderModel[];
    [key: string]: unknown;
}

export interface ModelsConfig {
    $schema?: string;
    providers?: Record<string, PiProvider>;
    [key: string]: unknown;
}

export function buildProviderEntry(options: {
    baseUrl: string;
    apiKeyEnv: string;
    models: Array<{ id: string; name?: string }>;
}): PiProvider {
    const seen = new Set<string>();
    const models: PiProviderModel[] = [];

    for (const model of options.models) {
        if (!model.id || seen.has(model.id)) {
            continue;
        }

        seen.add(model.id);
        models.push(model.name ? { id: model.id, name: model.name } : { id: model.id });
    }

    return {
        baseUrl: options.baseUrl.replace(/\/+$/, ''),
        api: 'openai-completions',
        apiKey: `$${options.apiKeyEnv}`,
        models,
    };
}

export function mergeModelsConfig(
    existing: ModelsConfig | null,
    providerName: string,
    provider: PiProvider,
): ModelsConfig {
    const base: ModelsConfig = existing && typeof existing === 'object' ? { ...existing } : {};
    const providers = base.providers && typeof base.providers === 'object' ? { ...base.providers } : {};

    providers[providerName] = provider;

    return {
        ...base,
        $schema: base.$schema ?? VENICE_MODELS_SCHEMA,
        providers,
    };
}

export interface PiSettings {
    $schema?: string;
    defaultProvider?: string;
    defaultModel?: string;
    [key: string]: unknown;
}

/** pi documents `defaultProvider` + `defaultModel` in https://pi.dev/docs/latest/settings. */
export function mergeSettings(
    existing: PiSettings | null,
    provider: string,
    model: string,
): PiSettings {
    return {
        ...(existing ?? {}),
        $schema: existing?.$schema ?? PI_SETTINGS_SCHEMA,
        defaultProvider: provider,
        defaultModel: model,
    };
}

export function savedModelFromSettings(settings: PiSettings | null): string | undefined {
    if (!settings || typeof settings.defaultModel !== 'string' || settings.defaultModel.length === 0) {
        return undefined;
    }

    if (typeof settings.defaultProvider === 'string' && settings.defaultProvider.length > 0) {
        return settings.defaultModel.includes('/')
            ? settings.defaultModel
            : `${settings.defaultProvider}/${settings.defaultModel}`;
    }

    return settings.defaultModel;
}

export interface ModelSetupOptions extends PathEnvironment {
    requestedModel?: string;
    provider?: string;
    apiKey?: string;
    apiKeyEnv?: string;
    fetchImpl?: typeof fetch;
    clock?: () => Date;
    /** Persist the chosen model into an existing agent.json. */
    config?: AgentConfig | null;
    saveConfig?: (config: AgentConfig) => Promise<unknown>;
}

export interface ModelSetupResult {
    provider: string;
    model: string;
    full: string;
    modelsPath: string;
    settingsPath: string;
    models: DiscoveredModel[];
    offline: boolean;
    warnings: string[];
    apiKeyEnv: string;
    hadKey: boolean;
}

/**
 * Write/merge the pi provider and the default model. The key itself is never written: models.json
 * references `$VENICE_API_KEY` / `$OPENAI_MODEL_API_KEY`, exactly like pi's schema expects.
 */
export async function setupModel(options: ModelSetupOptions): Promise<ModelSetupResult> {
    const requested = options.requestedModel
        ? splitModelRef(options.requestedModel, options.provider ?? 'venice')
        : { provider: options.provider ?? 'venice', model: DEFAULT_VENICE_MODEL };
    const env = options.env ?? process.env;
    const baseUrl =
        requested.provider === 'venice'
            ? env.OPENAI_MODEL_BASE_URL || DEFAULT_VENICE_BASE_URL
            : env.OPENAI_MODEL_BASE_URL || DEFAULT_VENICE_BASE_URL;
    const apiKey = options.apiKey;
    const apiKeyEnv = options.apiKeyEnv ?? (requested.provider === 'venice' ? 'VENICE_API_KEY' : 'OPENAI_MODEL_API_KEY');
    const discovery = await discoverModels({
        baseUrl,
        apiKey,
        fetchImpl: options.fetchImpl,
    });

    const warnings: string[] = [];
    const discoveredIds = new Set(discovery.models.map((model) => model.id));
    let chosen = requested.model;

    if (discovery.warning) {
        warnings.push(discovery.warning);
    }

    if (!discovery.offline && !discoveredIds.has(requested.model)) {
        const fallback = discovery.models.find((model) => !model.id.includes('embedding'));

        if (fallback) {
            warnings.push(
                `Model "${requested.model}" is not available on this account; falling back to "${fallback.id}".`,
            );
            chosen = fallback.id;
        } else {
            warnings.push(
                `Model "${requested.model}" is not listed by the provider; keeping it as requested.`,
            );
        }
    }

    const ordered: DiscoveredModel[] = [{ id: requested.model }, ...discovery.models];
    const providerEntry = buildProviderEntry({ baseUrl, apiKeyEnv, models: ordered });
    const modelsPath = piModelsPath(options);
    const settingsPath = piSettingsPath(options);
    const existingModels = await readModelsConfig(modelsPath);
    const existingSettings = await readSettings(settingsPath);
    const mergedModels = mergeModelsConfig(existingModels, requested.provider, providerEntry);
    const mergedSettings = mergeSettings(existingSettings, requested.provider, chosen);

    await ensureDir(piModelsPath(options).replace(/[\\/][^\\/]+$/, ''), 0o700);
    await writeFileAtomic(modelsPath, `${JSON.stringify(mergedModels, null, 2)}\n`, 0o644);
    await writeFileAtomic(settingsPath, `${JSON.stringify(mergedSettings, null, 2)}\n`, 0o644);

    if (options.config && options.saveConfig) {
        const full = `${requested.provider}/${chosen}`;

        await options.saveConfig({
            ...options.config,
            model: full,
            modelRoles: mergeModelRoles(options.config.modelRoles, { chat: full }),
        });
    }

    return {
        provider: requested.provider,
        model: chosen,
        full: `${requested.provider}/${chosen}`,
        modelsPath,
        settingsPath,
        models: discovery.models,
        offline: discovery.offline,
        warnings,
        apiKeyEnv,
        hadKey: Boolean(apiKey),
    };
}

export async function readModelsConfig(path: string): Promise<ModelsConfig | null> {
    try {
        return await readJsonFile<ModelsConfig>(path);
    } catch (error) {
        throw new Error(`Cannot parse ${path}: ${(error as Error).message}`, { cause: error });
    }
}

export async function readSettings(path: string): Promise<PiSettings | null> {
    try {
        return await readJsonFile<PiSettings>(path);
    } catch (error) {
        throw new Error(`Cannot parse ${path}: ${(error as Error).message}`, { cause: error });
    }
}

export async function readRawModelsConfig(path: string): Promise<string | null> {
    try {
        return await readFile(path, 'utf8');
    } catch {
        return null;
    }
}

export function formatKeyCommands(key: string, envName: string, platform: NodeJS.Platform = process.platform): string[] {
    const bash = `export ${envName}='${key.replace(/'/g, `'\\''`)}'`;

    if (platform === 'win32') {
        return [bash, `$env:${envName} = '${key.replace(/'/g, "''")}'`];
    }

    return [bash, `$env:${envName} = '${key.replace(/'/g, "''")}'`];
}

/** Parse `~/.config/lanomat/venice.env` (export KEY=value, quotes optional). */
export function parseEnvFile(text: string): Record<string, string> {
    const values: Record<string, string> = {};

    for (const rawLine of text.split(/\r?\n/)) {
        const line = rawLine.trim();

        if (!line || line.startsWith('#')) {
            continue;
        }

        const match = /^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/.exec(line);

        if (!match) {
            continue;
        }

        let value = match[2]!.trim();

        if (
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1);
        }

        values[match[1]!] = value;
    }

    return values;
}

export async function readEnvFileKey(
    path: string,
    names: string[] = ['VENICE_API_KEY', 'OPENAI_MODEL_API_KEY'],
): Promise<{ key: string; envName: string; path: string } | null> {
    try {
        const values = parseEnvFile(await readFile(path, 'utf8'));

        for (const name of names) {
            if (values[name]) {
                return { key: values[name]!, envName: name, path };
            }
        }
    } catch {
        return null;
    }

    return null;
}

export interface ApiKeyResolution {
    key?: string;
    envName: string;
    source: 'flag' | 'env' | 'file' | 'none';
    file?: string;
}

/**
 * Key resolution order: `--key` > provider env var > the other env var > venice.env file.
 * The key is returned for in-memory use only; callers print at most the env var name.
 */
export async function resolveApiKey(options: {
    flagKey?: string;
    provider?: string;
    env?: NodeJS.ProcessEnv;
    envFile?: string;
}): Promise<ApiKeyResolution> {
    const env = options.env ?? process.env;
    const provider = options.provider ?? 'venice';
    const preferred = provider === 'venice' ? 'VENICE_API_KEY' : 'OPENAI_MODEL_API_KEY';
    const secondary = preferred === 'VENICE_API_KEY' ? 'OPENAI_MODEL_API_KEY' : 'VENICE_API_KEY';

    if (options.flagKey) {
        return { key: options.flagKey, envName: preferred, source: 'flag' };
    }

    if (env[preferred]) {
        return { key: env[preferred], envName: preferred, source: 'env' };
    }

    if (env[secondary]) {
        return { key: env[secondary], envName: secondary, source: 'env' };
    }

    if (options.envFile) {
        const fromFile = await readEnvFileKey(options.envFile, [preferred, secondary]);

        if (fromFile) {
            return {
                key: fromFile.key,
                envName: fromFile.envName,
                source: 'file',
                file: fromFile.path,
            };
        }
    }

    return { envName: preferred, source: 'none' };
}

export interface ProbeResult {
    ok: boolean;
    detail: string;
}

/** Send one tiny tool call to prove whether the configured model can call tools. */
export async function probeToolCall(options: {
    baseUrl: string;
    apiKey: string;
    model: string;
    fetchImpl?: typeof fetch;
    timeoutMs?: number;
}): Promise<ProbeResult> {
    const fetchImpl = options.fetchImpl ?? fetch;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), options.timeoutMs ?? 30_000);

    try {
        const response = await fetchImpl(`${options.baseUrl.replace(/\/+$/, '')}/chat/completions`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${options.apiKey}`,
            },
            body: JSON.stringify({
                model: options.model,
                messages: [{ role: 'user', content: 'Call the ping tool with value 1.' }],
                tools: [
                    {
                        type: 'function',
                        function: {
                            name: 'ping',
                            description: 'Return a number.',
                            parameters: {
                                type: 'object',
                                properties: { value: { type: 'number' } },
                                required: ['value'],
                            },
                        },
                    },
                ],
                tool_choice: 'auto',
                // Reasoning models spend the first tokens on thinking; 64 is still a tiny probe.
                max_tokens: 64,
            }),
            signal: controller.signal,
        });

        if (!response.ok) {
            return { ok: false, detail: `tool-call probe failed (${response.status})` };
        }

        const payload = (await response.json()) as {
            choices?: Array<{ message?: { tool_calls?: unknown[] } }>;
        };
        const calls = payload.choices?.[0]?.message?.tool_calls;

        return Array.isArray(calls) && calls.length > 0
            ? { ok: true, detail: 'tool calls work' }
            : { ok: false, detail: 'model answered without a tool call' };
    } catch (error) {
        return { ok: false, detail: `tool-call probe error: ${redactSecret((error as Error).message, options.apiKey)}` };
    } finally {
        clearTimeout(timer);
    }
}
