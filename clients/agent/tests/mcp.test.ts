import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    buildMcpServerEntry,
    buildPiArgs,
    formatExportLine,
    installMcpServer,
    mergeMcpConfig,
    piCommandLine,
} from '../src/mcp.js';
import { removeDir, readJson, tempDir } from './helpers.js';

describe('mcp.json', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('writes the literal ${LANOMAT_TOKEN} placeholder, not a token', () => {
        const entry = buildMcpServerEntry('http://lan.local/', 'deferred');

        expect(entry.url).toBe('http://lan.local/mcp');
        expect(entry.headers?.Authorization).toBe('Bearer ${LANOMAT_TOKEN}');
        expect(entry.exposure).toBe('deferred');
        expect(entry.description).toContain('LANoMAT');
    });

    it('merges into an existing mcp.json and preserves other servers', async () => {
        const env = { LANOMAT_PI_DIR: join(dir, 'pi') } as NodeJS.ProcessEnv;
        const path = join(dir, 'pi', 'mcp.json');
        const { mkdir, writeFile } = await import('node:fs/promises');

        await mkdir(join(dir, 'pi'), { recursive: true });
        await writeFile(
            path,
            JSON.stringify(
                {
                    theme: 'keep-me',
                    mcpServers: {
                        other: { url: 'http://other/mcp', headers: { Authorization: 'Bearer x' } },
                    },
                },
                null,
                2,
            ),
        );

        const result = await installMcpServer({ env, url: 'http://lan.local', serverName: 'lanomat' });
        const written = (await readJson(path)) as {
            theme: string;
            mcpServers: Record<string, { url: string; exposure?: string }>;
        };

        expect(result.created).toBe(false);
        expect(written.theme).toBe('keep-me');
        expect(written.mcpServers.other?.url).toBe('http://other/mcp');
        expect(written.mcpServers.lanomat?.url).toBe('http://lan.local/mcp');
        expect(JSON.parse(await readFile(path, 'utf8')).mcpServers.lanomat.exposure).toBe('deferred');
    });

    it('creates mcp.json and honours the exposure flag', async () => {
        const env = { LANOMAT_PI_DIR: join(dir, 'pi') } as NodeJS.ProcessEnv;
        const result = await installMcpServer({
            env,
            url: 'https://lan.example',
            serverName: 'lan',
            exposure: 'direct',
        });

        expect(result.created).toBe(true);
        expect(result.entry.exposure).toBe('direct');
    });

    it('merges without mutating the input object', () => {
        const existing = { mcpServers: { a: { url: 'a' } } };
        const merged = mergeMcpConfig(existing, 'b', { url: 'b' });

        expect(merged.mcpServers?.a?.url).toBe('a');
        expect(merged.mcpServers?.b?.url).toBe('b');
        expect(existing.mcpServers).not.toHaveProperty('b');
    });

    it('formats a shell export line with safe quoting', () => {
        expect(formatExportLine('abc', 'linux')).toBe("export LANOMAT_TOKEN='abc'");
        expect(formatExportLine("a'b", 'linux')).toBe("export LANOMAT_TOKEN='a'\\''b'");
        expect(formatExportLine('abc', 'win32')).toContain('$env:LANOMAT_TOKEN');
    });

    it('builds the pi argv with print, provider, model and skills', () => {
        expect(
            buildPiArgs({
                prompt: 'install ut2004',
                provider: 'venice',
                model: 'venice/e2ee-deepseek-v4-1-flash-1017-abliterated',
                skillsDir: '/skills',
            }),
        ).toEqual([
            '--print',
            '--provider',
            'venice',
            '--model',
            'venice/e2ee-deepseek-v4-1-flash-1017-abliterated',
            '--skills',
            '/skills',
            'install ut2004',
        ]);
    });

    it('quotes the printable pi command', () => {
        expect(piCommandLine(['--print', 'install ut2004'])).toBe("pi --print 'install ut2004'");
    });
});
