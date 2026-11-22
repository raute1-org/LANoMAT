import { spawn } from 'node:child_process';
import { isAbsolute, join } from 'node:path';
import { assertStepPathsSafe, shellsForOs } from './path-quote.js';
import type { Os, PlanStep, VerifyCheck } from './types.js';
import { fileExists, isDirectory } from './util.js';

export type Shell = 'powershell' | 'cmd' | 'bash';

/** Exit code used when a command was killed by the step timeout (same as GNU `timeout`). */
export const TIMEOUT_EXIT_CODE = 124;

/** Guard against an installer that never returns. Steps can override it with `timeoutMs`. */
export const DEFAULT_COMMAND_TIMEOUT_MS = 30 * 60_000;

export interface CommandResult {
    code: number;
    stdout: string;
    stderr: string;
    /** Signal that terminated the process, if any. */
    signal?: NodeJS.Signals | null;
    /** True when the step timeout killed the command; `code` is then 124. */
    timedOut?: boolean;
}

export interface CommandRunOptions {
    cwd: string;
    /** Extra environment entries; the default executor merges them over `process.env`. */
    env?: NodeJS.ProcessEnv;
    /** Kill the command after this many milliseconds. 0 disables the watchdog. */
    timeoutMs?: number;
    /** `inherit` lets an installer read from the terminal; the default is `ignore`. */
    input?: 'ignore' | 'inherit';
    /** cmd.exe needs verbatim args so its `/s /c "…"` stripping matches what we built. */
    windowsVerbatimArguments?: boolean;
}

export type CommandExecutor = (
    command: string,
    args: string[],
    options: CommandRunOptions,
) => Promise<CommandResult>;

export class CommandFailedError extends Error {
    readonly run: string;
    readonly shell: Shell;
    readonly code: number;
    readonly stdout: string;
    readonly stderr: string;
    readonly timedOut: boolean;

    constructor(options: {
        run: string;
        shell: Shell;
        result: CommandResult;
        timeoutMs?: number;
    }) {
        const prefix = options.result.timedOut
            ? `Command timed out after ${options.timeoutMs ?? DEFAULT_COMMAND_TIMEOUT_MS}ms (exit ${options.result.code}): ${options.run}`
            : `Command failed (exit ${options.result.code}): ${options.run}`;

        super(`${prefix}${options.result.stderr ? `\n${options.result.stderr.trim()}` : ''}`);
        this.name = 'CommandFailedError';
        this.run = options.run;
        this.shell = options.shell;
        this.code = options.result.code;
        this.stdout = options.result.stdout;
        this.stderr = options.result.stderr;
        this.timedOut = Boolean(options.result.timedOut);
    }
}

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

/** Grace between SIGTERM and SIGKILL when a command times out. */
export const KILL_GRACE_MS = 1_000;

/** Hard ceiling for a timed-out command, even if the process never reports `close`. */
export const TIMEOUT_SETTLE_MS = 2_500;

function killProcessTree(child: ReturnType<typeof spawn>, signal: 'SIGTERM' | 'SIGKILL'): void {
    if (process.platform === 'win32') {
        const args = ['/pid', String(child.pid ?? 0), '/T'];

        if (signal === 'SIGKILL') {
            args.push('/F');
        }

        try {
            const killer = spawn('taskkill', args, { stdio: 'ignore', windowsHide: true });

            killer.on('error', () => child.kill(signal));
        } catch {
            child.kill(signal);
        }

        return;
    }

    // detached: true makes the child the leader of its own process group, so the negative pid
    // reaches bash and everything bash started (installers, sleep, nested helpers).
    try {
        process.kill(-(child.pid ?? 0), signal);
    } catch {
        child.kill(signal);
    }
}

export const defaultExecutor: CommandExecutor = (command, args, options) =>
    new Promise((resolve, reject) => {
        const child = spawn(command, args, {
            cwd: options.cwd,
            env: options.env ? { ...process.env, ...options.env } : process.env,
            stdio: [options.input === 'inherit' ? 'inherit' : 'ignore', 'pipe', 'pipe'],
            windowsHide: true,
            // POSIX: own process group so the timeout can kill the whole tree. The executor always
            // waits for the child, so this does not leak a daemon.
            detached: process.platform !== 'win32',
            // Node's libuv re-quoting would fight cmd.exe's own quote handling.
            windowsVerbatimArguments: options.windowsVerbatimArguments ?? false,
        });
        let stdout = '';
        let stderr = '';
        let timedOut = false;
        let settled = false;
        let timer: NodeJS.Timeout | undefined;
        let killTimer: NodeJS.Timeout | undefined;
        let settleTimer: NodeJS.Timeout | undefined;

        const timeoutMs = options.timeoutMs ?? 0;

        const cleanup = (): void => {
            if (timer) {
                clearTimeout(timer);
            }

            if (killTimer) {
                clearTimeout(killTimer);
            }

            if (settleTimer) {
                clearTimeout(settleTimer);
            }
        };

        const settle = (code: number | null, signal: NodeJS.Signals | null): void => {
            if (settled) {
                return;
            }

            settled = true;
            cleanup();
            resolve({
                code: code ?? (timedOut ? TIMEOUT_EXIT_CODE : 1),
                stdout,
                stderr,
                signal: signal ?? null,
                ...(timedOut ? { timedOut: true } : {}),
            });
        };

        if (timeoutMs > 0) {
            timer = setTimeout(() => {
                timedOut = true;
                killProcessTree(child, 'SIGTERM');
                killTimer = setTimeout(() => killProcessTree(child, 'SIGKILL'), KILL_GRACE_MS);
                killTimer.unref?.();
                // A grandchild can hold the stdout pipe open forever; settle without `close`.
                settleTimer = setTimeout(() => settle(null, 'SIGKILL'), TIMEOUT_SETTLE_MS);
                settleTimer.unref?.();
            }, timeoutMs);
            timer.unref?.();
        }

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
        child.on('error', (error) => {
            if (settled) {
                return;
            }

            settled = true;
            cleanup();
            reject(error);
        });
        child.on('close', (code, signal) => settle(code, signal));
    });

export interface ShellCommand {
    command: string;
    args?: string[];
}

export interface ShellInvocation {
    command: string;
    args: string[];
    /** The script that was handed to the shell, after per-shell normalisation. */
    script: string;
    /** Only set for cmd.exe; pass through to `spawn`. */
    windowsVerbatimArguments?: boolean;
}

/**
 * Quote one argument for the target shell. The value is always wrapped, so paths with spaces,
 * `&`, `;`, `$` or quotes survive verbatim.
 */
export function shellQuote(shell: Shell, value: string): string {
    switch (shell) {
        case 'bash':
            // End the single-quoted string, emit an escaped quote, reopen it: 'it'\''s'.
            return `'${value.replace(/'/g, `'\\''`)}'`;
        case 'powershell':
            // PowerShell single quotes are literal; a literal quote is doubled.
            return `'${value.replace(/'/g, `''`)}'`;
        case 'cmd': {
            // cmd has no backslash escapes; a quote inside a quoted value is doubled. A run of
            // backslashes before the closing quote is doubled too, otherwise the argument parser
            // treats `"C:\Games\"` as an escaped quote.
            const quoted = value.replace(/"/g, '""');
            const safeTail = quoted.replace(/\\+$/, (run) => `${run}${run}`);

            return `"${safeTail}"`;
        }
        default:
            throw new Error(`Unsupported shell: ${String(shell)}`);
    }
}

/** Build a shell script from an executable and its arguments, quoting each part per shell. */
export function buildShellCommand(shell: Shell, command: string, args: string[] = []): string {
    const quoted = [command, ...args].map((part) => shellQuote(shell, part)).join(' ');

    // `'C:\path\tool.exe' '--x'` is a string expression in PowerShell, not a call. The call
    // operator makes a quoted executable path actually run, with each argument kept intact.
    return shell === 'powershell' ? `& ${quoted}` : quoted;
}

/**
 * Split a script on a top-level operator (`&&`) while respecting single and double quotes.
 * `'a && b' && c` yields `["'a && b'", ' c']`. Inside double quotes PowerShell escapes with a
 * backtick, POSIX shells with a backslash; pass the matching escape character.
 */
export function splitTopLevel(
    script: string,
    operator: string,
    escapeChar: '\\' | '`' = '\\',
): string[] {
    const parts: string[] = [];
    let current = '';
    let quote: "'" | '"' | null = null;
    let index = 0;

    while (index < script.length) {
        const char = script[index] as string;

        if (quote === "'") {
            current += char;

            if (char === "'") {
                // A doubled quote stays inside the literal in PowerShell; harmless for bash too.
                if (script[index + 1] === "'") {
                    current += "'";
                    index += 2;
                    continue;
                }

                quote = null;
            }

            index += 1;
            continue;
        }

        if (quote === '"') {
            current += char;

            if (char === escapeChar && script[index + 1] !== undefined) {
                current += script[index + 1];
                index += 2;
                continue;
            }

            if (char === '"') {
                quote = null;
            }

            index += 1;
            continue;
        }

        if (char === "'" || char === '"') {
            quote = char;
            current += char;
            index += 1;
            continue;
        }

        if (script.startsWith(operator, index)) {
            parts.push(current);
            current = '';
            index += operator.length;
            continue;
        }

        current += char;
        index += 1;
    }

    parts.push(current);

    return parts;
}

/**
 * Windows PowerShell 5.1 (the `powershell` binary on win32) has no `&&`. Rewrite a chain into
 * nested `if ($?)` blocks, which keep the same short-circuit behaviour. PowerShell 7 keeps `&&`.
 */
export function normalizeShellScript(
    shell: Shell,
    script: string,
    platform: NodeJS.Platform = process.platform,
): string {
    if (shell !== 'powershell' || platform !== 'win32' || !script.includes('&&')) {
        return script;
    }

    const parts = splitTopLevel(script, '&&', shell === 'powershell' ? '`' : '\\').map((part) =>
        part.trim(),
    );

    if (parts.length === 1) {
        return script;
    }

    return parts.reduceRight((next, part, index) =>
        index === parts.length - 1 ? part : `${part}; if ($?) { ${next} }`,
    );
}

export function shellInvocation(
    shell: Shell,
    run: string | ShellCommand,
    platform: NodeJS.Platform = process.platform,
): ShellInvocation {
    const script = normalizeShellScript(
        shell,
        typeof run === 'string' ? run : buildShellCommand(shell, run.command, run.args),
        platform,
    );

    switch (shell) {
        case 'powershell':
            return {
                command: platform === 'win32' ? 'powershell' : 'pwsh',
                args: ['-NoProfile', '-Command', script],
                script,
            };
        case 'cmd':
            // cmd /s /c strips exactly the first and last quote, so wrapping is the safe form for
            // paths with spaces as well as for `&&` chains. Verbatim args keep our quoting intact.
            return {
                command: 'cmd',
                args: ['/d', '/s', '/c', `"${script}"`],
                script,
                windowsVerbatimArguments: true,
            };
        case 'bash':
            return { command: 'bash', args: ['-c', script], script };
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

/**
 * A step may declare commands for both platforms (`os: any` with a bash and a
 * PowerShell variant). The OS decides which shell family runs: bash on Linux
 * and macOS, `powershell`/`cmd` on Windows. Without this filter a Linux install
 * would try to run PowerShell commands (and vice versa).
 */
function commandAppliesToOs(shell: Shell, os: Os): boolean {
    return os === 'windows' ? shell !== 'bash' : shell === 'bash';
}

export function applyStepVariables(
    step: PlanStep,
    variables: Record<string, string>,
    os?: Os,
): PlanStep {
    // The placeholders are pasted into already-quoted command text, so a path that would end that
    // quoting is refused here as well as in `install` (which checks before it downloads anything).
    assertStepPathsSafe(step, variables, shellsForOs(os));

    return {
        ...step,
        commands: step.commands
            ?.filter((command) => !os || commandAppliesToOs(command.shell, os))
            .map((command) => ({
                ...command,
                run: substituteVariables(command.run, variables),
            })),
        verify: step.verify?.filter((check) => !os || !check.os || check.os === 'any' || check.os === os).map((check) => {
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
    /** Restricts the step's commands and verify checks to this platform's shell family. */
    os?: Os;
    variables?: Record<string, string>;
    yes?: boolean;
    dryRun?: boolean;
    executor?: CommandExecutor;
    confirm?: (question: string) => Promise<boolean>;
    log?: (line: string) => void;
    warn?: (line: string) => void;
    /** Extra environment for every command in the step; merged over the inherited environment. */
    env?: NodeJS.ProcessEnv;
    /** Per-command watchdog; defaults to `DEFAULT_COMMAND_TIMEOUT_MS`. 0 disables it. */
    timeoutMs?: number;
    /** `inherit` lets an installer prompt on the terminal. */
    input?: 'ignore' | 'inherit';
    platform?: NodeJS.Platform;
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
            return `command ${[check.command, ...(check.args ?? [])].join(' ')}`;
    }
}

function resolveCheckPath(path: string, cwd: string): string {
    return isAbsolute(path) ? path : join(cwd, path);
}

async function runCheck(
    check: VerifyCheck,
    options: RunStepOptions,
    log: (line: string) => void,
): Promise<{ check: VerifyCheck; ok: boolean; detail: string }> {
    switch (check.type) {
        case 'file_exists': {
            const path = resolveCheckPath(check.path, options.cwd);
            const ok = await fileExists(path);

            return { check, ok, detail: `${path} ${ok ? 'exists' : 'is missing'}` };
        }
        case 'dir_exists': {
            const path = resolveCheckPath(check.path, options.cwd);
            const ok = await isDirectory(path);

            return { check, ok, detail: `${path} ${ok ? 'exists' : 'is missing'}` };
        }
        case 'command': {
            const executor = options.executor ?? defaultExecutor;
            const invocation = shellInvocation(
                check.shell ?? 'bash',
                { command: check.command, args: check.args },
                options.platform,
            );

            log(`  verify: ${checkLabel(check)}`);

            const result = await executor(invocation.command, invocation.args, {
                cwd: options.cwd,
                env: options.env,
                timeoutMs: options.timeoutMs,
                input: 'ignore',
                windowsVerbatimArguments: invocation.windowsVerbatimArguments,
            });

            return {
                check,
                ok: result.code === 0,
                detail: result.timedOut
                    ? `timed out after ${options.timeoutMs ?? DEFAULT_COMMAND_TIMEOUT_MS}ms`
                    : `exit code ${result.code}`,
            };
        }
    }
}

export async function runPlanStep(step: PlanStep, options: RunStepOptions): Promise<RunStepResult> {
    const log = options.log ?? (() => undefined);
    const warn = options.warn ?? (() => undefined);
    const variables = options.variables ?? {};
    const resolved = applyStepVariables(step, variables, options.os);
    const result: RunStepResult = { step: resolved, skipped: false, commands: [], verify: [] };

    // A dry run has no side effects: no prompt, no download, no process, no filesystem check.
    if (options.dryRun) {
        log(`${step.optional ? '[optional] ' : ''}${step.title}`);

        for (const command of resolved.commands ?? []) {
            log(`  [dry-run] ${command.shell}: ${command.run}`);
        }

        for (const download of resolved.downloads ?? []) {
            log(`  [dry-run] download: ${download.filename} ← ${download.url}`);
        }

        for (const check of resolved.verify ?? []) {
            log(`  [dry-run] verify: ${checkLabel(check)}`);
        }

        return result;
    }

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

    const executor = options.executor ?? defaultExecutor;
    const timeoutMs = options.timeoutMs ?? DEFAULT_COMMAND_TIMEOUT_MS;

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

        const invocation = shellInvocation(command.shell, command.run, options.platform);
        log(`  $ ${command.shell}: ${command.run}`);

        const commandResult = await executor(invocation.command, invocation.args, {
            cwd: options.cwd,
            env: options.env,
            timeoutMs,
            input: options.input ?? 'ignore',
            windowsVerbatimArguments: invocation.windowsVerbatimArguments,
        });

        result.commands.push(commandResult);

        if (commandResult.code !== 0) {
            throw new CommandFailedError({
                run: command.run,
                shell: command.shell,
                result: commandResult,
                timeoutMs,
            });
        }
    }

    for (const check of resolved.verify ?? []) {
        const verified = await runCheck(check, { ...options, timeoutMs }, log);

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
