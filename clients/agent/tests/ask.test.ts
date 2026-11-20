import { chmod, mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    AskService,
    SpawnPiAdapter,
    buildAskArgv,
    piInstallInstructions,
    resolvePiBinary,
    type AskAdapter,
} from '../src/ask.js';
import { removeDir, tempDir } from './helpers.js';

describe('ask adapters', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('builds the exact pi argv for provider, model and skills', () => {
        expect(
            buildAskArgv({
                prompt: 'install ut2004 for me',
                provider: 'venice',
                model: 'venice/e2ee-deepseek-v4-1-flash-1017-abliterated',
                skillsDir: '/home/r/.agents/skills',
            }),
        ).toEqual([
            '--print',
            '--provider',
            'venice',
            '--model',
            'venice/e2ee-deepseek-v4-1-flash-1017-abliterated',
            '--skills',
            '/home/r/.agents/skills',
            'install ut2004 for me',
        ]);
    });

    it('prints the exact command and install instructions when pi is missing', async () => {
        const lines: string[] = [];
        const adapter = new SpawnPiAdapter(null, (line) => lines.push(line));
        const result = await adapter.run({
            prompt: 'install ut2004',
            model: 'venice/m',
            provider: 'venice',
        });

        expect(result.code).toBe(1);
        expect(result.used).toBe('printed');
        expect(lines.join('\n')).toContain('npm install -g @earendil-works/pi-coding-agent');
        expect(lines.join('\n')).toContain("pi --print --provider venice --model venice/m 'install ut2004'");
        expect(piInstallInstructions()).toContain('pi login');
    });

    it('prints only the command with printCommandOnly', async () => {
        const lines: string[] = [];
        const adapter = new SpawnPiAdapter('/usr/bin/pi', (line) => lines.push(line));
        const result = await adapter.run({ prompt: 'hi', printCommandOnly: true });

        expect(result.used).toBe('printed');
        expect(lines[0]).toContain('pi --print');
    });

    it('finds an executable pi on PATH', async () => {
        const bin = join(dir, 'bin');

        await mkdir(bin, { recursive: true });
        await writeFile(join(bin, 'pi'), '#!/bin/sh\n');
        await chmod(join(bin, 'pi'), 0o755);

        expect(resolvePiBinary({ PATH: bin } as NodeJS.ProcessEnv, 'linux')).toBe(join(bin, 'pi'));
        expect(resolvePiBinary({ PATH: join(dir, 'nope') } as NodeJS.ProcessEnv, 'linux')).toBeNull();
    });

    it('delegates through the adapter interface', async () => {
        const adapter: AskAdapter = {
            run: async () => ({ code: 7, used: 'sdk' }),
        };
        const service = new AskService(adapter);

        expect((await service.ask({ prompt: 'x' })).code).toBe(7);
    });
});
