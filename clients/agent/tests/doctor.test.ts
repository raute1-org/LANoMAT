import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { runDoctor, formatDoctorReport } from '../src/doctor.js';
import { removeDir, tempDir } from './helpers.js';

describe('doctor', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('reports the resolved model, its source and all four roles without a config', async () => {
        const report = await runDoctor({
            env: { LANOMAT_CONFIG_DIR: join(dir, 'cfg'), LANOMAT_PI_DIR: join(dir, 'pi'), PATH: '' },
            home: dir,
            config: null,
            checkMcp: false,
            checkVeniceModels: false,
            checkProbe: false,
        });

        expect(report.model).toMatchObject({
            provider: 'venice',
            model: 'venice/e2ee-deepseek-v4-1-flash-1017-abliterated',
            source: 'default',
        });
        expect(report.roles.map((role) => role.name)).toEqual(['chat', 'decision', 'stt', 'tts']);
        expect(report.ok).toBe(false);
        expect(formatDoctorReport(report)).toContain('Roles:');
    });

    it('checks the server and the tool-call probe and prints no key', async () => {
        const report = await runDoctor({
            env: {
                LANOMAT_CONFIG_DIR: join(dir, 'cfg'),
                LANOMAT_PI_DIR: join(dir, 'pi'),
                VENICE_API_KEY: 'super-secret-key',
                PATH: '',
            },
            home: dir,
            config: {
                url: 'http://lan.local',
                token: 'lan-token',
                token_expires_at: '',
                user: { id: 1, name: 'Rene' },
            },
            checkMcp: false,
            checkVeniceModels: false,
            fetchImpl: async (input) => {
                const url = String(input);

                if (url.endsWith('/api/v1/me')) {
                    return new Response(
                        JSON.stringify({
                            user: { id: 1, name: 'Rene', role: 'admin' },
                            server: { name: 'LANoMAT', url: 'http://lan.local', version: '1.0' },
                            modules: [],
                        }),
                        { headers: { 'content-type': 'application/json' } },
                    );
                }

                return new Response(
                    JSON.stringify({ choices: [{ message: { tool_calls: [{ id: '1' }] } }] }),
                    { headers: { 'content-type': 'application/json' } },
                );
            },
        });
        const text = formatDoctorReport(report);

        expect(report.model.source).toBe('default');
        expect(text).toContain('tool calls work');
        expect(text).toContain('Tool calls:');
        expect(text).not.toContain('super-secret-key');
    });
});
