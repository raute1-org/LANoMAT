import { chmod, mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    DOCTOR_SCHEMA,
    formatDoctorJson,
    formatDoctorReport,
    runDoctor,
    safeUrl,
    type DoctorReport,
} from '../src/doctor.js';
import { saveConfig } from '../src/config.js';
import { removeDir, tempDir } from './helpers.js';

const config = {
    url: 'http://lan.local',
    token: 'lan-token-secret',
    token_expires_at: '2030-01-01T00:00:00.000Z',
    user: { id: 1, name: 'Rene' },
};

function check(report: DoctorReport, name: string) {
    const found = report.checks.find((entry) => entry.name === name);

    if (!found) {
        throw new Error(`missing doctor check "${name}" (have ${report.checks.map((c) => c.name).join(', ')})`);
    }

    return found;
}

function meResponse(): Response {
    return new Response(
        JSON.stringify({
            user: { id: 1, name: 'Rene', role: 'admin' },
            server: { name: 'LANoMAT', url: 'http://lan.local', version: '1.0' },
            modules: [],
        }),
        { headers: { 'content-type': 'application/json' } },
    );
}

describe('doctor', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    function baseOptions(overrides: Record<string, unknown> = {}) {
        return {
            env: {
                LANOMAT_CONFIG_DIR: join(dir, 'cfg'),
                LANOMAT_PI_DIR: join(dir, 'pi'),
                LANOMAT_SKILLS_DIR: join(dir, 'skills'),
                PATH: '',
            } as NodeJS.ProcessEnv,
            home: dir,
            config: null,
            checkMcp: false,
            checkVeniceModels: false,
            checkProbe: false,
            sdkAvailable: async () => false,
            clock: () => new Date('2026-11-21T10:00:00.000Z'),
            ...overrides,
        };
    }

    it('reports the resolved model, its source and all four roles without a config', async () => {
        const report = await runDoctor(baseOptions());

        expect(report.model).toMatchObject({
            provider: 'venice',
            model: 'venice/e2ee-deepseek-v4-1-flash-1017-abliterated',
            source: 'default',
        });
        expect(report.roles.map((role) => role.name)).toEqual(['chat', 'decision', 'stt', 'tts']);
        expect(report.ok).toBe(false);
        expect(check(report, 'Config').ok).toBe(false);
        expect(check(report, 'Server').detail).toBe('skipped: no config');
        expect(formatDoctorReport(report)).toContain('Roles:');
    });

    it('has a stable JSON schema and a matching summary', async () => {
        const report = await runDoctor(
            baseOptions({
                config,
                fetchImpl: async () => meResponse(),
            }),
        );

        expect(Object.keys(report)).toEqual([
            'schema',
            'generated_at',
            'ok',
            'summary',
            'checks',
            'model',
            'roles',
        ]);
        expect(report.schema).toBe(DOCTOR_SCHEMA);
        expect(report.generated_at).toBe('2026-11-21T10:00:00.000Z');
        expect(Object.keys(report.summary)).toEqual(['total', 'passed', 'failed', 'warnings']);
        expect(report.summary.total).toBe(report.checks.length);
        expect(report.summary.passed + report.summary.failed + report.summary.warnings).toBe(
            report.summary.total,
        );
        expect(report.summary.failed).toBe(0);
        expect(report.ok).toBe(true);

        for (const entry of report.checks) {
            expect(Object.keys(entry)).toEqual(['name', 'ok', 'required', 'detail']);
            expect(typeof entry.detail).toBe('string');
        }

        expect(JSON.parse(formatDoctorJson(report))).toEqual(report);
    });

    it('distinguishes a rejected token (401) from an offline server', async () => {
        const rejected = await runDoctor(
            baseOptions({
                config,
                fetchImpl: async () => new Response('', { status: 401 }),
            }),
        );
        const rejectedCheck = check(rejected, 'Server');

        expect(rejectedCheck.ok).toBe(false);
        expect(rejectedCheck.detail).toContain('token was rejected (HTTP 401)');
        expect(rejectedCheck.detail).toContain('lanomat-agent login');
        expect(rejected.ok).toBe(false);

        const offline = await runDoctor(
            baseOptions({
                config,
                fetchImpl: async () => {
                    throw new TypeError('fetch failed');
                },
            }),
        );
        const offlineCheck = check(offline, 'Server');

        expect(offlineCheck.ok).toBe(false);
        expect(offlineCheck.detail).toBe('offline — cannot reach http://lan.local: fetch failed');
        expect(offlineCheck.detail).not.toContain('Cannot reach http://lan.local: Cannot reach');
        expect(offlineCheck.detail).not.toContain('401');
    });

    it('reports an expired token separately from a server error', async () => {
        const report = await runDoctor(
            baseOptions({
                config: { ...config, token_expires_at: '2026-01-01T00:00:00.000Z' },
                fetchImpl: async () => meResponse(),
            }),
        );

        expect(check(report, 'Token')).toMatchObject({ ok: false, required: false });
        expect(check(report, 'Token').detail).toContain('expired at 2026-01-01T00:00:00.000Z');
    });

    it('reports whether pi can run at all', async () => {
        const missing = await runDoctor(baseOptions());
        const bundled = await runDoctor(baseOptions({ sdkAvailable: async () => true }));

        expect(check(missing, 'pi').ok).toBe(false);
        expect(check(missing, 'pi').detail).toContain('npm install -g @earendil-works/pi-coding-agent');
        expect(check(bundled, 'pi').ok).toBe(true);
        expect(check(bundled, 'pi').detail).toContain('bundled pi SDK');
    });

    it('detects the Venice key without printing it', async () => {
        const report = await runDoctor(
            baseOptions({
                config,
                fetchImpl: async () => meResponse(),
                env: {
                    ...baseOptions().env,
                    VENICE_API_KEY: 'super-secret-key',
                } as NodeJS.ProcessEnv,
            }),
        );

        expect(check(report, 'Venice key').ok).toBe(true);
        expect(check(report, 'Venice key').detail).toContain('VENICE_API_KEY');
        expect(formatDoctorJson(report)).not.toContain('super-secret-key');
        expect(formatDoctorReport(report)).not.toContain('super-secret-key');
    });

    it('reports the models.json provider and a writable skills directory', async () => {
        const piDir = join(dir, 'pi');
        const skills = join(dir, 'skills');
        await mkdir(join(skills, 'lanomat-ut2004'), { recursive: true });
        await writeFile(join(skills, 'lanomat-ut2004', '.lanomat-source'), '{"url":"http://lan.local"}');
        await mkdir(piDir, { recursive: true });
        await writeFile(
            join(piDir, 'models.json'),
            JSON.stringify({ providers: { venice: { models: [{ id: 'x' }] } } }),
        );

        const report = await runDoctor(
            baseOptions({
                config,
                env: {
                    ...baseOptions().env,
                    VENICE_API_KEY: 'key',
                } as NodeJS.ProcessEnv,
                fetchImpl: async () => meResponse(),
            }),
        );

        expect(check(report, 'models.json')).toMatchObject({ ok: true, required: false });
        expect(check(report, 'models.json').detail).toContain('provider "venice" configured');
        expect(check(report, 'Skills')).toMatchObject({ ok: true, required: false });
        expect(check(report, 'Skills').detail).toContain('1 synced recipe skill(s)');
        expect(check(report, 'Skills').detail).toContain('(writable)');
    });

    it.skipIf(process.getuid?.() === 0)(
        'flags an unparseable models.json and an unwritable skills directory',
        async () => {
            const piDir = join(dir, 'pi');
            const skills = join(dir, 'skills');
            await mkdir(skills, { recursive: true });
            await mkdir(piDir, { recursive: true });
            await writeFile(join(piDir, 'models.json'), '{ not json');
            await chmod(skills, 0o555);

            const report = await runDoctor(baseOptions());

            expect(check(report, 'models.json').ok).toBe(false);
            expect(check(report, 'models.json').detail).toContain('cannot parse');
            expect(check(report, 'Skills').ok).toBe(false);
            expect(check(report, 'Skills').detail).toMatch(/not writable|skills directory missing/);

            await chmod(skills, 0o755);
        },
    );

    it('checks smbclient on Linux only', async () => {
        const bin = join(dir, 'bin');
        await mkdir(bin, { recursive: true });
        await writeFile(join(bin, 'smbclient'), '#!/bin/sh\n', { mode: 0o755 });

        const withSmb = await runDoctor(
            baseOptions({
                platform: 'linux',
                env: { ...baseOptions().env, PATH: bin } as NodeJS.ProcessEnv,
            }),
        );
        const withoutSmb = await runDoctor(baseOptions({ platform: 'linux' }));
        const windows = await runDoctor(baseOptions({ platform: 'win32' }));

        expect(check(withSmb, 'smbclient').ok).toBe(true);
        expect(check(withSmb, 'smbclient').detail).toBe(join(bin, 'smbclient'));
        expect(check(withoutSmb, 'smbclient').ok).toBe(false);
        expect(withoutSmb.checks.some((entry) => entry.name === 'smbclient')).toBe(true);
        expect(windows.checks.some((entry) => entry.name === 'smbclient')).toBe(false);
    });

    it('never prints the token, the API key or credentials embedded in the URL', async () => {
        const report = await runDoctor(
            baseOptions({
                config: { ...config, url: 'http://user:pass@lan.local' },
                env: {
                    ...baseOptions().env,
                    VENICE_API_KEY: 'venice-secret-key',
                } as NodeJS.ProcessEnv,
                fetchImpl: async () =>
                    new Response(
                        JSON.stringify({
                            user: { id: 1, name: 'Rene', role: 'admin' },
                            server: {
                                name: 'LANoMAT',
                                url: 'http://lan.local/?token=lan-token-secret',
                                version: '1.0',
                            },
                            modules: [],
                        }),
                        { headers: { 'content-type': 'application/json' } },
                    ),
            }),
        );
        const json = formatDoctorJson(report);
        const text = formatDoctorReport(report);

        for (const secret of ['lan-token-secret', 'venice-secret-key', 'user:pass']) {
            expect(json).not.toContain(secret);
            expect(text).not.toContain(secret);
        }

        expect(check(report, 'Config').detail).toContain('http://lan.local');
        expect(check(report, 'Server').detail).toContain('http://lan.local');
    });

    it('reports the MCP 401 vs offline distinction', async () => {
        const rejectUnauthorized = async (): Promise<number> => {
            const error = Object.assign(new Error('Error POSTing to endpoint (HTTP 401): Unauthorized'), {
                code: 401,
            });

            throw error;
        };
        const offline = async (): Promise<number> => {
            const error = Object.assign(new TypeError('fetch failed'), {
                cause: { code: 'ECONNREFUSED' },
            });

            throw error;
        };

        const rejected = await runDoctor(
            baseOptions({ config, fetchImpl: async () => meResponse(), checkMcp: true, mcpConnector: rejectUnauthorized }),
        );
        const rejectedCheck = check(rejected, 'MCP endpoint');

        expect(rejectedCheck.ok).toBe(false);
        expect(rejectedCheck.required).toBe(false);
        expect(rejectedCheck.detail).toContain('MCP endpoint rejected the token (HTTP 401)');
        expect(rejectedCheck.detail).toContain('lanomat-agent login');

        const unreachable = await runDoctor(
            baseOptions({ config, fetchImpl: async () => meResponse(), checkMcp: true, mcpConnector: offline }),
        );
        const offlineCheck = check(unreachable, 'MCP endpoint');

        expect(offlineCheck.ok).toBe(false);
        expect(offlineCheck.detail).toBe('offline — cannot reach http://lan.local/mcp: fetch failed');
        expect(offlineCheck.detail).not.toContain('401');

        const healthy = await runDoctor(
            baseOptions({
                config,
                fetchImpl: async () => meResponse(),
                checkMcp: true,
                mcpConnector: async () => 7,
            }),
        );

        expect(check(healthy, 'MCP endpoint')).toMatchObject({ ok: true });
        expect(check(healthy, 'MCP endpoint').detail).toBe('7 MCP tool(s) available');
    });

    it('redacts the token when the MCP error text leaks it', async () => {
        const report = await runDoctor(
            baseOptions({
                config,
                fetchImpl: async () => meResponse(),
                checkMcp: true,
                mcpConnector: async () => {
                    throw new Error(`handshake exploded while using ${config.token}`);
                },
            }),
        );
        const json = formatDoctorJson(report);

        expect(check(report, 'MCP endpoint').ok).toBe(false);
        expect(json).not.toContain(config.token);
        expect(json).toContain('«redacted»');
    });

    it('does not echo the token from a 401 MCP error', async () => {
        const report = await runDoctor(
            baseOptions({
                config,
                fetchImpl: async () => meResponse(),
                checkMcp: true,
                mcpConnector: async () => {
                    throw new Error(`401 Unauthorized: bearer ${config.token} was rejected`);
                },
            }),
        );

        expect(check(report, 'MCP endpoint').detail).toBe(
            'MCP endpoint rejected the token (HTTP 401) — run "lanomat-agent login"',
        );
        expect(formatDoctorJson(report)).not.toContain(config.token);
    });

    it('strips credentials from URLs it prints', () => {
        expect(safeUrl('http://user:pass@lan.local/?token=x#y')).toBe('http://lan.local');
        expect(safeUrl('not a url')).toBe('not a url');
    });

    it('reports loose 0644 config permissions without repairing them', async () => {
        const env = {
            LANOMAT_CONFIG_DIR: join(dir, 'cfg'),
            LANOMAT_PI_DIR: join(dir, 'pi'),
            LANOMAT_SKILLS_DIR: join(dir, 'skills'),
            PATH: '',
        } as NodeJS.ProcessEnv;

        const path = await saveConfig(config, { env });

        await chmod(path, 0o644);

        const report = await runDoctor({
            env,
            home: dir,
            config,
            checkMcp: false,
            checkVeniceModels: false,
            checkProbe: false,
            sdkAvailable: async () => false,
            clock: () => new Date('2026-11-21T10:00:00.000Z'),
            fetchImpl: async () => meResponse(),
        });

        expect(check(report, 'Config permissions')).toMatchObject({ ok: false, required: false });
        expect(check(report, 'Config permissions').detail).toContain('0644');
    });

    it('uses Windows ACLs instead of POSIX modes on win32', async () => {
        const report = await runDoctor(
            baseOptions({
                platform: 'win32',
                config,
                fetchImpl: async () => meResponse(),
            }),
        );

        expect(check(report, 'Config permissions').detail).toContain('Windows ACLs');
    });

    it('checks the server and the tool-call probe and prints no key', async () => {
        const report = await runDoctor(
            baseOptions({
                config,
                env: {
                    ...baseOptions().env,
                    VENICE_API_KEY: 'super-secret-key',
                } as NodeJS.ProcessEnv,
                checkProbe: true,
                fetchImpl: async (input) => {
                    const url = String(input);

                    if (url.endsWith('/api/v1/me')) {
                        return meResponse();
                    }

                    return new Response(
                        JSON.stringify({ choices: [{ message: { tool_calls: [{ id: '1' }] } }] }),
                        { headers: { 'content-type': 'application/json' } },
                    );
                },
            }),
        );
        const text = formatDoctorReport(report);

        expect(report.model.source).toBe('default');
        expect(text).toContain('tool calls work');
        expect(text).toContain('Tool calls:');
        expect(text).not.toContain('super-secret-key');
    });
});
