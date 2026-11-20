import { spawn } from 'node:child_process';
import { join } from 'node:path';
import type { PlanStep, VerifyCheck } from './types.js';
import { fileExists, isDirectory } from './util.js';

export interface CommandResult {
    code: number;
    stdout: string;
    stderr: string;
}

export type CommandExecutor = (
    command: string,
    args: string[],
    options: { cwd: string },
) => Promise<CommandResult>;

export class StepVerificationError extends Error {
    readonly step: PlanStep;
    readonly check: VerifyCheck;

    constructor(step: PlanStep, check: VerifyCheck, detail: string) {
        super(`Step "${step.title}" failed verification (${detail})`);
        this.name = 'StepVerificationError';
        this.step = step;
        this.check = check;
    }
}

export const defaultExecutor: CommandExecutor = (command, args, options) =>
    new Promise((resolve, reject) => {
        const child = spawn(command, args, {
            cwd: options.cwd,
            stdio: ['ignore', 'pipe', 'pipe'],
            windowsHide: true,
        });
        let stdout = '';
        let stderr = '';

        child.stdout?.on('data', (chunk: Buffer) => {
            const text = chunk.toString();
            stdout += text;
            process.stdout.write(text);
        });
        child.stderr?.on('data', (chunk: Buffer) => {
            const text = chunk.toString();
            stderr += text;
            process.stderr.write(text);
        });
        child.on('error', reject);
        child.on('close', (code) => resolve({ code: code ?? 1, stdout, stderr }));
    });

export interface ShellInvocation {
    command: string;
    args: string[];
}

export function shellInvocation(
    shell: 'powershell' | 'cmd' | 'bash',
    run: string,
    platform: NodeJS.Platform = process.platform,
): ShellInvocation {
    switch (shell) {
        case 'powershell':
            return {
                command: platform === 'win32' ? 'powershell' : 'pwsh',
                args: ['-NoProfile', '-Command', run],
            };
        case 'cmd':
            return { command: platform === 'win32' ? 'cmd' : 'cmd', args: ['/d', '/s', '/c', run] };
        case 'bash':
            return { command: 'bash', args: ['-c', run] };
        default:
            throw new Error(`Unsupported shell: ${String(shell)}`);
    }
}

export function substituteVariables(value: string, variables: Record<string, string>): string {
    return value.replace(/\{\{([a-z0-9_]+)\}\}/gi, (match, name: string) => {
        const key = name.toLowerCase();

        return variables[key] ?? match;
    });
}

export function applyStepVariables(step: PlanStep, variables: Record<string, string>): PlanStep {
    return {
        ...step,
        commands: step.commands?.map((command) => ({
            ...command,
            run: substituteVariables(command.run, variables),
        })),
        verify: step.verify?.map((check) => {
            if ('path' in check) {
                return { ...check, path: substituteVariables(check.path, variables) };
            }

            return {
                ...check,
                command: substituteVariables(check.command, variables),
                args: check.args?.map((arg) => substituteVariables(arg, variables)),
            };
        }),
        downloads: step.downloads?.map((download) => ({
            ...download,
            url: substituteVariables(download.url, variables),
            filename: substituteVariables(download.filename, variables),
        })),
    };
}

export interface RunStepOptions {
    cwd: string;
    variables?: Record<string, string>;
    yes?: boolean;
    dryRun?: boolean;
    executor?: CommandExecutor;
    confirm?: (question: string) => Promise<boolean>;
    log?: (line: string) => void;
    warn?: (line: string) => void;
}

export interface RunStepResult {
    step: PlanStep;
    skipped: boolean;
    commands: CommandResult[];
    verify: Array<{ check: VerifyCheck; ok: boolean; detail: string }>;
}

function checkLabel(check: VerifyCheck): string {
    switch (check.type) {
        case 'file_exists':
            return `file_exists ${check.path}`;
        case 'dir_exists':
            return `dir_exists ${check.path}`;
        case 'command':
            return `command ${check.command}`;
    }
}

async function runCheck(
    check: VerifyCheck,
    options: RunStepOptions,
    log: (line: string) => void,
): Promise<{ check: VerifyCheck; ok: boolean; detail: string }> {
    switch (check.type) {
        case 'file_exists': {
            const path = check.path.startsWith('/') ? check.path : join(options.cwd, check.path);
            const ok = await fileExists(path);

            return { check, ok, detail: `${path} ${ok ? 'exists' : 'is missing'}` };
        }
        case 'dir_exists': {
            const path = check.path.startsWith('/') ? check.path : join(options.cwd, check.path);
            const ok = await isDirectory(path);

            return { check, ok, detail: `${path} ${ok ? 'exists' : 'is missing'}` };
        }
        case 'command': {
            const executor = options.executor ?? defaultExecutor;
            const invocation = shellInvocation(check.shell ?? 'bash', check.command);

            log(`  verify: ${checkLabel(check)}`);

            const result = await executor(invocation.command, invocation.args, { cwd: options.cwd });

            return { check, ok: result.code === 0, detail: `exit code ${result.code}` };
        }
    }
}

export async function runPlanStep(step: PlanStep, options: RunStepOptions): Promise<RunStepResult> {
    const log = options.log ?? (() => undefined);
    const warn = options.warn ?? (() => undefined);
    const variables = options.variables ?? {};
    const resolved = applyStepVariables(step, variables);
    const result: RunStepResult = { step: resolved, skipped: false, commands: [], verify: [] };

    if (step.optional) {
        const run = options.yes
            ? true
            : await (options.confirm ?? (async () => false))(`Run optional step "${step.title}"?`);

        if (!run) {
            result.skipped = true;
            warn(`Skipped optional step: ${step.title}`);

            return result;
        }
    }

    log(`${step.optional ? '[optional] ' : ''}${step.title}`);

    if (options.dryRun) {
        for (const command of resolved.commands ?? []) {
            log(`  [dry-run] ${command.shell}: ${command.run}`);
        }

        for (const check of resolved.verify ?? []) {
            log(`  [dry-run] verify: ${checkLabel(check)}`);
        }

        return result;
    }

    const executor = options.executor ?? defaultExecutor;

    for (const command of resolved.commands ?? []) {
        if (command.optional && !options.yes) {
            const run = await (options.confirm ?? (async () => false))(
                `Run optional command "${command.run}"?`,
            );

            if (!run) {
                warn(`Skipped optional command: ${command.run}`);
                continue;
            }
        }

        const invocation = shellInvocation(command.shell, command.run);
        log(`  $ ${command.shell}: ${command.run}`);

        const commandResult = await executor(invocation.command, invocation.args, {
            cwd: options.cwd,
        });

        result.commands.push(commandResult);

        if (commandResult.code !== 0) {
            throw new Error(
                `Command failed (exit ${commandResult.code}): ${command.run}${
                    commandResult.stderr ? `\n${commandResult.stderr.trim()}` : ''
                }`,
            );
        }
    }

    for (const check of resolved.verify ?? []) {
        const verified = await runCheck(check, options, log);

        result.verify.push(verified);

        if (!verified.ok) {
            if (check.optional || step.optional) {
                warn(`Verification failed (optional): ${checkLabel(check)} — ${verified.detail}`);
                continue;
            }

            throw new StepVerificationError(resolved, check, verified.detail);
        }

        log(`  ✔ ${checkLabel(check)}`);
    }

    return result;
}
