import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    DEFAULT_VENICE_MODEL_FULL,
    buildProviderEntry,
    discoverModels,
    formatKeyCommands,
    inferProvider,
    mergeModelRoles,
    mergeModelsConfig,
    mergeSettings,
    parseEnvFile,
    probeToolCall,
    resolveApiKey,
    resolveModel,
    resolveModelRoles,
    resolveModelWithConfig,
    setupModel,
    splitModelRef,
} from '../src/models.js';
import { piModelsPath, piSettingsPath } from '../src/config.js';
import { removeDir, readJson, tempDir } from './helpers.js';

describe('model resolution', () => {
    it('prefers CLI flags over every environment source', () => {
        const resolved = resolveModel({
            cliModel: 'venice/cli',
            env: {
                LANOMAT_AGENT_MODEL: 'venice/env',
                OPENAI_MODEL_BASE_URL: 'https://api.venice.ai/api/v1',
                OPENAI_MODEL_NAME: 'openai-model',
            },
            savedModel: 'venice/saved',
        });

        expect(resolved.full).toBe('venice/cli');
        expect(resolved.source).toBe('cli');
    });

    it('prefers LANOMAT_AGENT_MODEL over OPENAI_MODEL_*', () => {
        const resolved = resolveModel({
            env: {
                LANOMAT_AGENT_MODEL: 'venice/lanomat',
                OPENAI_MODEL_BASE_URL: 'https://api.venice.ai/api/v1',
                OPENAI_MODEL_NAME: 'openai-model',
            },
        });

        expect(resolved.full).toBe('venice/lanomat');
        expect(resolved.source).toBe('env-lanomat');
    });

    it('synthesises the provider from OPENAI_MODEL_*', () => {
        const resolved = resolveModel({
            env: {
                OPENAI_MODEL_BASE_URL: 'http://gpu.lan/v1',
                OPENAI_MODEL_NAME: 'local-model',
            },
        });

        expect(resolved.provider).toBe('lanomat-openai');
        expect(resolved.full).toBe('lanomat-openai/local-model');
        expect(resolved.baseUrl).toBe('http://gpu.lan/v1');
        expect(resolved.source).toBe('env-openai');
    });

    it('infers venice from the base URL and honours OPENAI_MODEL_PROVIDER', () => {
        expect(inferProvider('https://api.venice.ai/api/v1')).toBe('venice');
        expect(inferProvider('https://proxy.example/v1')).toBe('lanomat-openai');
        expect(
            resolveModel({
                env: {
                    OPENAI_MODEL_BASE_URL: 'https://api.venice.ai/api/v1',
                    OPENAI_MODEL_NAME: 'x',
                    OPENAI_MODEL_PROVIDER: 'custom',
                },
            }).provider,
        ).toBe('custom');
    });

    it('falls back to the saved model and then the Venice default', () => {
        expect(resolveModel({ env: {}, savedModel: 'venice/saved' })).toMatchObject({
            full: 'venice/saved',
            source: 'config',
        });
        expect(resolveModel({ env: {} })).toMatchObject({
            full: DEFAULT_VENICE_MODEL_FULL,
            source: 'default',
        });
    });

    it('reads the chat role from the saved config', () => {
        const resolved = resolveModelWithConfig({
            env: {},
            config: {
                url: 'http://lan.local',
                token: 't',
                token_expires_at: '',
                user: { id: 1, name: 'x' },
                modelRoles: { chat: 'venice/role-model' },
            },
        });

        expect(resolved.full).toBe('venice/role-model');
    });

    it('supports split refs and provider defaults', () => {
        expect(splitModelRef('venice/foo')).toEqual({ provider: 'venice', model: 'foo' });
        expect(splitModelRef('foo', 'lanomat-openai')).toEqual({ provider: 'lanomat-openai', model: 'foo' });
    });
});

describe('models.json and settings merging', () => {
    it('builds a provider referencing the env var, never the key', () => {
        const provider = buildProviderEntry({
            baseUrl: 'https://api.venice.ai/api/v1/',
            apiKeyEnv: 'VENICE_API_KEY',
            models: [{ id: 'a' }, { id: 'a' }, { id: 'b', name: 'B' }],
        });

        expect(provider.apiKey).toBe('$VENICE_API_KEY');
        expect(provider.baseUrl).toBe('https://api.venice.ai/api/v1');
        expect(provider.models).toEqual([{ id: 'a' }, { id: 'b', name: 'B' }]);
    });

    it('preserves other providers and top-level keys', () => {
        const merged = mergeModelsConfig(
            { custom: true, providers: { other: { apiKey: '$X' } as never } },
            'venice',
            buildProviderEntry({ baseUrl: 'https://api.venice.ai/api/v1', apiKeyEnv: 'VENICE_API_KEY', models: [] }),
        );

        expect(merged.custom).toBe(true);
        expect(merged.providers?.other).toEqual({ apiKey: '$X' });
        expect(merged.providers?.venice?.apiKey).toBe('$VENICE_API_KEY');
        expect(merged.$schema).toContain('models.schema.json');
    });

    it('writes defaultProvider and defaultModel without losing other settings', () => {
        const merged = mergeSettings({ theme: 'dark' }, 'venice', 'model-x');

        expect(merged).toMatchObject({
            theme: 'dark',
            defaultProvider: 'venice',
            defaultModel: 'model-x',
        });
        expect(merged.$schema).toContain('settings.schema.json');
    });

    it('resolves roles with defaults and merges updates', () => {
        const roles = resolveModelRoles(null, {});

        expect(roles.map((role) => role.name)).toEqual(['chat', 'decision', 'stt', 'tts']);
        expect(roles[0]).toMatchObject({ value: DEFAULT_VENICE_MODEL_FULL, source: 'default' });
        expect(roles.slice(1).every((role) => role.value === null)).toBe(true);
        expect(mergeModelRoles({ chat: 'venice/a' }, { decision: 'venice/d' })).toEqual({
            chat: 'venice/a',
            decision: 'venice/d',
            stt: null,
            tts: null,
        });
    });
});

describe('model setup', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('writes the requested model first and settings, even offline', async () => {
        const env = { LANOMAT_PI_DIR: join(dir, 'pi') } as NodeJS.ProcessEnv;
        const saved: Array<Record<string, unknown>> = [];
        const result = await setupModel({
            env,
            requestedModel: 'venice/e2ee-deepseek-v4-1-flash-1017-abliterated',
            apiKey: 'super-secret',
            apiKeyEnv: 'VENICE_API_KEY',
            fetchImpl: async () => {
                throw new Error('offline');
            },
            config: {
                url: 'http://lan.local',
                token: 't',
                token_expires_at: '',
                user: { id: 1, name: 'x' },
            },
            saveConfig: async (config) => saved.push(config as unknown as Record<string, unknown>),
            clock: () => new Date('2026-11-20T10:00:00Z'),
        });
        const models = (await readJson(piModelsPath({ env }))) as {
            providers: { venice: { apiKey: string; models: Array<{ id: string }> } };
        };
        const settings = (await readJson(piSettingsPath({ env }))) as Record<string, unknown>;

        expect(result.offline).toBe(true);
        expect(models.providers.venice.apiKey).toBe('$VENICE_API_KEY');
        expect(models.providers.venice.models[0]?.id).toBe('e2ee-deepseek-v4-1-flash-1017-abliterated');
        expect(settings.defaultProvider).toBe('venice');
        expect(settings.defaultModel).toBe('e2ee-deepseek-v4-1-flash-1017-abliterated');
        expect(saved[0]).toMatchObject({
            model: 'venice/e2ee-deepseek-v4-1-flash-1017-abliterated',
            modelRoles: { chat: 'venice/e2ee-deepseek-v4-1-flash-1017-abliterated' },
        });
        expect(JSON.stringify(models)).not.toContain('super-secret');
    });

    it('preserves other providers in an existing models.json', async () => {
        const env = { LANOMAT_PI_DIR: join(dir, 'pi') } as NodeJS.ProcessEnv;

        await mkdir(join(dir, 'pi'), { recursive: true });
        await writeFile(
            piModelsPath({ env }),
            JSON.stringify({ providers: { other: { baseUrl: 'http://x', apiKey: '$OTHER', models: [] } } }),
        );

        await setupModel({
            env,
            requestedModel: 'venice/m',
            apiKeyEnv: 'VENICE_API_KEY',
            fetchImpl: async () => {
                throw new Error('offline');
            },
        });
        const models = (await readJson(piModelsPath({ env }))) as { providers: Record<string, unknown> };

        expect(models.providers.other).toBeDefined();
        expect(models.providers.venice).toBeDefined();
    });

    it('falls back to the first discovered model when the requested one is unavailable', async () => {
        const env = { LANOMAT_PI_DIR: join(dir, 'pi') } as NodeJS.ProcessEnv;
        const result = await setupModel({
            env,
            requestedModel: 'venice/not-on-account',
            apiKeyEnv: 'VENICE_API_KEY',
            fetchImpl: async () =>
                new Response(JSON.stringify({ data: [{ id: 'available-model', model_spec: { name: 'Available' } }] }), {
                    headers: { 'content-type': 'application/json' },
                }),
        });
        const settings = (await readJson(piSettingsPath({ env }))) as Record<string, unknown>;

        expect(result.model).toBe('available-model');
        expect(result.warnings[0]).toContain('not available on this account');
        expect(settings.defaultModel).toBe('available-model');
    });

    it('discovers text models and handles an offline provider', async () => {
        const online = await discoverModels({
            baseUrl: 'https://api.venice.ai/api/v1',
            fetchImpl: async (input) => {
                expect(String(input)).toContain('/models?type=text');

                return new Response(JSON.stringify({ data: [{ id: 'm1', model_spec: { name: 'Model 1' } }] }));
            },
        });
        const offline = await discoverModels({
            baseUrl: 'https://api.venice.ai/api/v1',
            fetchImpl: async () => {
                throw new Error('no network');
            },
        });

        expect(online.models).toEqual([{ id: 'm1', name: 'Model 1' }]);
        expect(offline.offline).toBe(true);
        expect(offline.models).toEqual([]);
    });
});

describe('API key resolution', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('uses --key, then env, then the env file', async () => {
        const envFile = join(dir, 'venice.env');

        await writeFile(envFile, "export VENICE_API_KEY='from-file'\n");

        expect(
            await resolveApiKey({
                flagKey: 'from-flag',
                env: { VENICE_API_KEY: 'from-env' },
                envFile,
            }),
        ).toMatchObject({ key: 'from-flag', source: 'flag', envName: 'VENICE_API_KEY' });
        expect(
            await resolveApiKey({ env: { VENICE_API_KEY: 'from-env' }, envFile }),
        ).toMatchObject({ key: 'from-env', source: 'env' });
        expect(await resolveApiKey({ env: {}, envFile })).toMatchObject({
            key: 'from-file',
            source: 'file',
        });
        expect(await resolveApiKey({ env: {}, envFile: join(dir, 'missing.env') })).toMatchObject({
            source: 'none',
        });
    });

    it('parses exports, quotes and comments', () => {
        expect(
            parseEnvFile("# comment\nexport VENICE_API_KEY='a b'\nOTHER=\"c\"\nEMPTY=\n"),
        ).toEqual({ VENICE_API_KEY: 'a b', OTHER: 'c', EMPTY: '' });
    });

    it('prints export lines in bash and PowerShell', () => {
        expect(formatKeyCommands('abc', 'VENICE_API_KEY', 'linux')[0]).toBe(
            "export VENICE_API_KEY='abc'",
        );
        expect(formatKeyCommands('abc', 'VENICE_API_KEY', 'win32')[1]).toContain('$env:VENICE_API_KEY');
    });
});

describe('tool-call probe', () => {
    it('reports OK when the model returns tool_calls', async () => {
        const result = await probeToolCall({
            baseUrl: 'https://api.venice.ai/api/v1',
            apiKey: 'secret',
            model: 'e2ee-deepseek-v4-1-flash-1017-abliterated',
            fetchImpl: async (_input, init) => {
                const body = JSON.parse(String(init?.body)) as Record<string, unknown>;

                expect(body.max_tokens).toBe(64);
                expect(body.model).toBe('e2ee-deepseek-v4-1-flash-1017-abliterated');

                return new Response(
                    JSON.stringify({ choices: [{ message: { tool_calls: [{ id: '1' }] } }] }),
                );
            },
        });

        expect(result).toEqual({ ok: true, detail: 'tool calls work' });
    });

    it('reports a failure without leaking the key', async () => {
        const result = await probeToolCall({
            baseUrl: 'https://api.venice.ai/api/v1',
            apiKey: 'secret-key',
            model: 'm',
            fetchImpl: async () => {
                throw new Error('bad key secret-key');
            },
        });

        expect(result.ok).toBe(false);
        expect(result.detail).not.toContain('secret-key');
    });
});
