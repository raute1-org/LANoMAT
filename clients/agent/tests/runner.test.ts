import { access, chmod, mkdir, readdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    applyStepVariables,
    buildShellCommand,
    CommandFailedError,
    defaultExecutor,
    normalizeShellScript,
    runPlanStep,
    shellInvocation,
    shellQuote,
    splitTopLevel,
    substituteVariables,
    TIMEOUT_EXIT_CODE,
    type CommandExecutor,
} from '../src/runner.js';
import { removeDir, tempDir } from './helpers.js';

const ok = { code: 0, stdout: '', stderr: '' };

describe('command runner', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('dry-run prints commands and never executes, prompts or verifies', async () => {
        const lines: string[] = [];
        const warnings: string[] = [];
        let called = false;
        let prompted = false;
        const executor: CommandExecutor = async () => {
            called = true;

            return ok;
        };

        const result = await runPlanStep(
            {
                id: 'copy',
                title: 'Copy files',
                optional: true,
                commands: [{ shell: 'bash', run: 'cp {{avatars}}/x .' }],
                downloads: [{ url: 'http://lan.local/a b.zip', filename: 'a b.zip' }],
                verify: [{ type: 'file_exists', path: 'x' }],
            },
            {
                cwd: dir,
                variables: { avatars: '/avatars' },
                dryRun: true,
                executor,
                confirm: async () => {
                    prompted = true;

                    return true;
                },
                log: (line) => lines.push(line),
                warn: (line) => warnings.push(line),
            },
        );

        const text = lines.join('\n');

        expect(called).toBe(false);
        expect(prompted).toBe(false);
        expect(warnings).toEqual([]);
        expect(result.commands).toEqual([]);
        expect(result.verify).toEqual([]);
        expect(text).toContain('[optional] Copy files');
        expect(text).toContain('[dry-run] bash: cp /avatars/x .');
        expect(text).toContain('[dry-run] download: a b.zip ← http://lan.local/a b.zip');
        expect(text).toContain('[dry-run] verify: file_exists x');
        expect(await readdir(dir)).toEqual([]);
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

                    return ok;
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
            { cwd: dir, executor: async () => ok },
        );

        expect(result.verify[0]).toMatchObject({ ok: true });
    });

    it('verifies a relative path that contains spaces', async () => {
        await mkdir(join(dir, 'My Games'), { recursive: true });
        await writeFile(join(dir, 'My Games', 'UT2004.exe'), 'x');

        const result = await runPlanStep(
            { id: 'x', title: 'X', verify: [{ type: 'file_exists', path: 'My Games/UT2004.exe' }] },
            { cwd: dir, executor: async () => ok },
        );

        expect(result.verify[0]).toMatchObject({ ok: true });
    });

    it('fails a required verify when the file is missing', async () => {
        await expect(
            runPlanStep(
                { id: 'x', title: 'X', verify: [{ type: 'file_exists', path: 'missing.exe' }] },
                { cwd: dir, executor: async () => ok },
            ),
        ).rejects.toThrow('failed verification');
    });

    it('runs a command verify check and quotes its executable and args', async () => {
        let command = '';
        const result = await runPlanStep(
            {
                id: 'x',
                title: 'X',
                verify: [
                    {
                        type: 'command',
                        command: '/opt/My Tools/check.sh',
                        args: ['--dir', '/opt/My Tools'],
                    },
                ],
            },
            {
                cwd: dir,
                executor: async (name, args) => {
                    command = `${name} ${args.join(' ')}`;

                    return ok;
                },
            },
        );

        expect(command).toBe("bash -c '/opt/My Tools/check.sh' '--dir' '/opt/My Tools'");
        expect(result.verify[0]).toMatchObject({ ok: true });
    });

    it('throws a typed error with the exit code and stderr', async () => {
        const error = await runPlanStep(
            { id: 'x', title: 'X', commands: [{ shell: 'bash', run: 'false' }] },
            { cwd: dir, executor: async () => ({ code: 2, stdout: 'out', stderr: 'boom' }) },
        ).catch((caught: unknown) => caught);

        expect(error).toBeInstanceOf(CommandFailedError);
        expect((error as CommandFailedError).code).toBe(2);
        expect((error as CommandFailedError).stderr).toBe('boom');
        expect((error as Error).message).toContain('exit 2');
        expect((error as Error).message).toContain('boom');
    });

    it('reports a timed-out command as exit 124 with the timeout in the message', async () => {
        const error = await runPlanStep(
            { id: 'x', title: 'X', commands: [{ shell: 'bash', run: 'hung-installer' }] },
            {
                cwd: dir,
                timeoutMs: 1500,
                executor: async () => ({ code: TIMEOUT_EXIT_CODE, stdout: '', stderr: '', timedOut: true }),
            },
        ).catch((caught: unknown) => caught);

        expect(error).toBeInstanceOf(CommandFailedError);
        expect((error as CommandFailedError).timedOut).toBe(true);
        expect((error as Error).message).toContain('timed out after 1500ms');
    });

    it('builds shell invocations per shell', () => {
        expect(shellInvocation('bash', 'ls', 'linux')).toEqual({
            command: 'bash',
            args: ['-c', 'ls'],
            script: 'ls',
        });
        expect(shellInvocation('powershell', 'Get-ChildItem', 'win32').command).toBe('powershell');
        expect(shellInvocation('powershell', 'Get-ChildItem', 'linux').command).toBe('pwsh');
        expect(shellInvocation('cmd', 'dir', 'win32')).toMatchObject({
            command: 'cmd',
            args: ['/d', '/s', '/c', '"dir"'],
            windowsVerbatimArguments: true,
        });
    });
});

describe('per-shell quoting', () => {
    it('quotes bash arguments, including single quotes and spaces', () => {
        expect(shellQuote('bash', '/opt/My Games/run.sh')).toBe("'/opt/My Games/run.sh'");
        expect(shellQuote('bash', "it's")).toBe("'it'\\''s'");
        expect(buildShellCommand('bash', '/opt/My Games/run.sh', ['--dir', '/opt/My Games'])).toBe(
            "'/opt/My Games/run.sh' '--dir' '/opt/My Games'",
        );
    });

    it('quotes PowerShell arguments with literal single-quoted strings', () => {
        expect(shellQuote('powershell', 'C:\\My Games\\UT2004.exe')).toBe(
            "'C:\\My Games\\UT2004.exe'",
        );
        expect(shellQuote('powershell', "C:\\Games\\it's")).toBe("'C:\\Games\\it''s'");
        expect(buildShellCommand('powershell', 'C:\\Program Files\\tool.exe', ['--dir', 'C:\\My Games'])).toBe(
            "'C:\\Program Files\\tool.exe' '--dir' 'C:\\My Games'",
        );
    });

    it('quotes cmd arguments for paths with spaces and doubled inner quotes', () => {
        expect(shellQuote('cmd', 'C:\\Program Files\\UT2004.exe')).toBe(
            '"C:\\Program Files\\UT2004.exe"',
        );
        expect(shellQuote('cmd', 'say "hi"')).toBe('"say ""hi"""');
        expect(buildShellCommand('cmd', 'C:\\My Games\\run.bat', ['--dir', 'C:\\My Games'])).toBe(
            '"C:\\My Games\\run.bat" "--dir" "C:\\My Games"',
        );
    });

    it('hands an object command through the shell with quoted args', () => {
        const bash = shellInvocation('bash', { command: '/opt/My Games/run.sh', args: ['--x'] }, 'linux');

        expect(bash.script).toBe("'/opt/My Games/run.sh' '--x'");
        expect(bash.args).toEqual(['-c', "'/opt/My Games/run.sh' '--x'"]);
    });
});

describe('&& chains', () => {
    it('keeps bash and cmd chains as one script', () => {
        expect(shellInvocation('bash', 'printf a && printf b', 'linux').script).toBe(
            'printf a && printf b',
        );
        expect(shellInvocation('cmd', 'echo a && echo b', 'win32').args[3]).toBe('"echo a && echo b"');
    });

    it('rewrites && for Windows PowerShell 5.1 into nested $? guards', () => {
        expect(normalizeShellScript('powershell', 'a && b', 'win32')).toBe('a; if ($?) { b }');
        expect(normalizeShellScript('powershell', 'a && b && c', 'win32')).toBe(
            'a; if ($?) { b; if ($?) { c } }',
        );
        // PowerShell 7 (`pwsh`) supports && natively.
        expect(normalizeShellScript('powershell', 'a && b', 'linux')).toBe('a && b');
    });

    it('does not split an && inside quotes', () => {
        expect(splitTopLevel("echo 'a && b' && echo c", '&&')).toEqual(["echo 'a && b' ", ' echo c']);
        expect(normalizeShellScript('powershell', "echo 'a && b' && echo c", 'win32')).toBe(
            "echo 'a && b'; if ($?) { echo c }",
        );
    });
});

describe('default executor', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('runs a chain and reports the real exit code', async () => {
        const result = await defaultExecutor('bash', ['-c', 'printf a && printf b'], { cwd: dir });

        expect(result.stdout).toBe('ab');
        expect(result.code).toBe(0);
    });

    it('inherits the parent environment and merges step overrides', async () => {
        process.env.LANOMAT_TEST_INHERIT = 'from-parent';

        try {
            const inherited = await defaultExecutor('bash', ['-c', 'printf %s "$LANOMAT_TEST_INHERIT"'], {
                cwd: dir,
            });
            const overridden = await defaultExecutor(
                'bash',
                ['-c', 'printf %s "$LANOMAT_TEST_INHERIT"'],
                { cwd: dir, env: { LANOMAT_TEST_INHERIT: 'from-step' } },
            );

            expect(inherited.stdout).toBe('from-parent');
            expect(overridden.stdout).toBe('from-step');
        } finally {
            delete process.env.LANOMAT_TEST_INHERIT;
        }
    });

    it('resolves with a non-zero exit code instead of throwing', async () => {
        const result = await defaultExecutor('bash', ['-c', 'exit 7'], { cwd: dir });

        expect(result.code).toBe(7);
    });

    it('kills a hung command after the timeout', async () => {
        const started = Date.now();
        const result = await defaultExecutor('bash', ['-c', 'sleep 5'], { cwd: dir, timeoutMs: 50 });

        expect(result.code).toBe(TIMEOUT_EXIT_CODE);
        expect(result.timedOut).toBe(true);
        expect(Date.now() - started).toBeLessThan(2_000);
    });

    it('works from a working directory that contains spaces', async () => {
        const spaced = join(dir, 'My Games');
        await mkdir(spaced, { recursive: true });

        const result = await defaultExecutor('bash', ['-c', 'pwd'], { cwd: spaced });

        expect(result.stdout.trim()).toBe(spaced);
    });
});

describe('runner file-system side effects', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('leaves nothing behind when a command fails', async () => {
        await runPlanStep(
            { id: 'x', title: 'X', commands: [{ shell: 'bash', run: 'exit 3' }] },
            { cwd: dir },
        ).catch(() => undefined);

        expect(await readdir(dir)).toEqual([]);
        await expect(access(join(dir, 'agent.json'))).rejects.toThrow();
    });

    it('runs in the given cwd even when it has spaces', async () => {
        const spaced = join(dir, 'My Games');
        await mkdir(spaced, { recursive: true });
        await chmod(spaced, 0o755);

        const result = await runPlanStep(
            { id: 'x', title: 'X', verify: [{ type: 'dir_exists', path: spaced }] },
            { cwd: dir },
        );

        expect(result.verify[0]).toMatchObject({ ok: true });
    });
});
