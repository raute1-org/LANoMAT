import { writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    applyStepVariables,
    runPlanStep,
    shellInvocation,
    substituteVariables,
    type CommandExecutor,
} from '../src/runner.js';
import { removeDir, tempDir } from './helpers.js';

describe('command runner', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('dry-run prints commands and never executes them', async () => {
        const lines: string[] = [];
        let called = false;
        const executor: CommandExecutor = async () => {
            called = true;

            return { code: 0, stdout: '', stderr: '' };
        };

        const result = await runPlanStep(
            {
                id: 'copy',
                title: 'Copy files',
                commands: [{ shell: 'bash', run: 'cp {{avatars}}/x .' }],
                verify: [{ type: 'file_exists', path: 'x' }],
            },
            {
                cwd: dir,
                variables: { avatars: '/avatars' },
                dryRun: true,
                executor,
                log: (line) => lines.push(line),
            },
        );

        expect(called).toBe(false);
        expect(result.commands).toEqual([]);
        expect(lines.join('\n')).toContain('[dry-run] bash: cp /avatars/x .');
        expect(lines.join('\n')).toContain('[dry-run] verify: file_exists x');
    });

    it('skips an optional step without --yes', async () => {
        const warnings: string[] = [];
        let called = false;

        await runPlanStep(
            { id: 'bonus', title: 'Bonus', optional: true, commands: [{ shell: 'bash', run: 'true' }] },
            {
                cwd: dir,
                yes: false,
                confirm: async () => false,
                executor: async () => {
                    called = true;

                    return { code: 0, stdout: '', stderr: '' };
                },
                warn: (line) => warnings.push(line),
            },
        );

        expect(called).toBe(false);
        expect(warnings[0]).toContain('Skipped optional step');
    });

    it('substitutes {{avatars}} in commands and verify paths', async () => {
        const step = applyStepVariables(
            {
                id: 'x',
                title: 'X',
                commands: [{ shell: 'bash', run: 'echo {{avatars}}' }],
                verify: [{ type: 'file_exists', path: '{{avatars}}/x.vrm' }],
            },
            { avatars: '/data/avatars' },
        );

        expect(step.commands?.[0]?.run).toBe('echo /data/avatars');
        expect(step.verify?.[0]).toEqual({ type: 'file_exists', path: '/data/avatars/x.vrm' });
        expect(substituteVariables('{{unknown}}', {})).toBe('{{unknown}}');
    });

    it('verifies an existing file after a step', async () => {
        await writeFile(join(dir, 'installed.exe'), 'x');
        const result = await runPlanStep(
            { id: 'x', title: 'X', verify: [{ type: 'file_exists', path: 'installed.exe' }] },
            { cwd: dir, executor: async () => ({ code: 0, stdout: '', stderr: '' }) },
        );

        expect(result.verify[0]).toMatchObject({ ok: true });
    });

    it('fails a required verify when the file is missing', async () => {
        await expect(
            runPlanStep(
                { id: 'x', title: 'X', verify: [{ type: 'file_exists', path: 'missing.exe' }] },
                { cwd: dir, executor: async () => ({ code: 0, stdout: '', stderr: '' }) },
            ),
        ).rejects.toThrow('failed verification');
    });

    it('runs a command verify check', async () => {
        let command = '';
        const result = await runPlanStep(
            {
                id: 'x',
                title: 'X',
                verify: [{ type: 'command', command: 'which ut2004' }],
            },
            {
                cwd: dir,
                executor: async (name, args) => {
                    command = `${name} ${args.join(' ')}`;

                    return { code: 0, stdout: '', stderr: '' };
                },
            },
        );

        expect(command).toBe('bash -c which ut2004');
        expect(result.verify[0]).toMatchObject({ ok: true });
    });

    it('throws when a command exits non-zero', async () => {
        await expect(
            runPlanStep(
                { id: 'x', title: 'X', commands: [{ shell: 'bash', run: 'false' }] },
                { cwd: dir, executor: async () => ({ code: 2, stdout: '', stderr: 'boom' }) },
            ),
        ).rejects.toThrow('exit 2');
    });

    it('builds shell invocations per shell', () => {
        expect(shellInvocation('bash', 'ls', 'linux')).toEqual({ command: 'bash', args: ['-c', 'ls'] });
        expect(shellInvocation('powershell', 'Get-ChildItem', 'win32').command).toBe('powershell');
        expect(shellInvocation('powershell', 'Get-ChildItem', 'linux').command).toBe('pwsh');
        expect(shellInvocation('cmd', 'dir', 'win32').args).toEqual(['/d', '/s', '/c', 'dir']);
    });
});
