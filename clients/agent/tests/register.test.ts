import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applicationsDir,
    desktopEntry,
    registerAgentScheme,
    SCHEME_KEY,
    windowsRegisterCommand,
} from '../src/register.js';
import { removeDir, tempDir } from './helpers.js';

describe('scheme registration', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('writes the exact desktop file with the lanomat mime type', async () => {
        const update = vi.fn(async () => true);
        const result = await registerAgentScheme({
            platform: 'linux',
            applicationsDir: dir,
            nodePath: '/usr/bin/node',
            cliPath: '/opt/lanomat/dist/cli.js',
            updateDesktopDatabase: update,
        });
        const content = await readFile(join(dir, 'lanomat-agent.desktop'), 'utf8');

        expect(result.written).toBe(true);
        expect(result.databaseUpdated).toBe(true);
        expect(update).toHaveBeenCalledWith(dir);
        expect(content).toContain('Exec=/usr/bin/node /opt/lanomat/dist/cli.js open %u');
        expect(content).toContain('MimeType=x-scheme-handler/lanomat;');
        expect(content).toContain('X-LANoMAT-Agent=true');
        expect(desktopEntry('/usr/bin/node', '/opt/lanomat/dist/cli.js')).toBe(content);
    });

    it('does not overwrite unrelated desktop files and is idempotent', async () => {
        const other = join(dir, 'other.desktop');

        await mkdir(dir, { recursive: true });
        await writeFile(other, '[Desktop Entry]\nName=Other\n');

        const first = await registerAgentScheme({
            platform: 'linux',
            applicationsDir: dir,
            nodePath: '/usr/bin/node',
            cliPath: '/opt/lanomat/dist/cli.js',
            updateDesktopDatabase: async () => true,
        });
        const second = await registerAgentScheme({
            platform: 'linux',
            applicationsDir: dir,
            nodePath: '/usr/bin/node',
            cliPath: '/opt/lanomat/dist/cli.js',
            updateDesktopDatabase: async () => true,
        });

        expect(first.written).toBe(true);
        expect(second.written).toBe(false);
        expect(await readFile(other, 'utf8')).toBe('[Desktop Entry]\nName=Other\n');
    });

    it('prints the Windows registry command without running it unless --yes is set', async () => {
        const executor = vi.fn(async () => ({ code: 0, stdout: '', stderr: '' }));
        const lines: string[] = [];
        const result = await registerAgentScheme({
            platform: 'win32',
            nodePath: 'C:\\Program Files\\nodejs\\node.exe',
            cliPath: 'C:\\LANoMAT\\dist\\cli.js',
            executor,
            log: (line) => lines.push(line),
        });

        expect(result.written).toBe(false);
        expect(executor).not.toHaveBeenCalled();
        expect(lines.join('\n')).toContain(`reg add ${SCHEME_KEY}`);
        expect(windowsRegisterCommand('node', 'cli.js')).toContain(
            'HKCU\\Software\\Classes\\lanomat\\shell\\open\\command',
        );
    });

    it('runs the Windows registry command with --yes', async () => {
        const executor = vi.fn(async () => ({ code: 0, stdout: '', stderr: '' }));
        const result = await registerAgentScheme({
            platform: 'win32',
            nodePath: 'node.exe',
            cliPath: 'cli.js',
            yes: true,
            executor,
            log: () => undefined,
        });

        expect(result.written).toBe(true);
        expect(executor).toHaveBeenCalledWith(
            'reg',
            ['add', SCHEME_KEY, '/ve', '/d', '"node.exe" "cli.js" open "%1"', '/f'],
            expect.anything(),
        );
    });

    it('resolves XDG applications directories', () => {
        expect(applicationsDir({ env: { XDG_DATA_HOME: '/xdg/data' } as NodeJS.ProcessEnv })).toBe(
            '/xdg/data/applications',
        );
        expect(applicationsDir({ env: {}, home: '/home/r' })).toBe('/home/r/.local/share/applications');
    });
});
