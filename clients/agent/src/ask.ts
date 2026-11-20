import { spawn } from 'node:child_process';
import { accessSync, constants } from 'node:fs';
import { delimiter, join } from 'node:path';
import { buildPiArgs, piCommandLine } from './mcp.js';
import type { ResolvedModel } from './models.js';

export interface AskRequest {
    prompt: string;
    model?: string;
    provider?: string;
    skillsDir?: string;
    agentDir?: string;
    cwd?: string;
    /** Do not run; return the argv that would have been used. */
    printCommandOnly?: boolean;
}

export interface AskRunResult {
    code: number;
    argv?: string[];
    used: 'sdk' | 'spawn' | 'printed';
}

export interface AskAdapter {
    run(request: AskRequest): Promise<AskRunResult>;
}

/** Locate the `pi` binary in PATH or in this package's node_modules/.bin. */
export function resolvePiBinary(
    env: NodeJS.ProcessEnv = process.env,
    platform: NodeJS.Platform = process.platform,
    packageRoot?: string,
): string | null {
    const names = platform === 'win32' ? ['pi.cmd', 'pi.exe', 'pi'] : ['pi'];

    for (const dir of (env.PATH ?? '').split(delimiter).filter(Boolean)) {
        for (const name of names) {
            const candidate = join(dir, name);

            try {
                accessSync(candidate, constants.X_OK);

                return candidate;
            } catch {
                // keep looking
            }
        }
    }

    if (packageRoot) {
        for (const name of names) {
            const candidate = join(packageRoot, 'node_modules', '.bin', name);

            try {
                accessSync(candidate, constants.X_OK);

                return candidate;
            } catch {
                // keep looking
            }
        }
    }

    return null;
}

export function piInstallInstructions(): string {
    return [
        'pi is not installed (or not on PATH). Install it with:',
        '',
        '  npm install -g @earendil-works/pi-coding-agent',
        '  pi login                 # or configure a provider with "lanomat-agent model setup"',
        '',
        'The exact command it would run:',
    ].join('\n');
}

export function buildAskArgv(request: AskRequest): string[] {
    return buildPiArgs({
        prompt: request.prompt,
        skillsDir: request.skillsDir,
        model: request.model,
        provider: request.provider,
    });
}

export class SpawnPiAdapter implements AskAdapter {
    constructor(
        private readonly binary: string | null,
        private readonly log: (line: string) => void = (line) => process.stdout.write(`${line}\n`),
    ) {}

    async run(request: AskRequest): Promise<AskRunResult> {
        const argv = buildAskArgv(request);

        if (request.printCommandOnly) {
            this.log(piCommandLine(argv));

            return { code: 0, argv, used: 'printed' };
        }

        if (!this.binary) {
            this.log(piInstallInstructions());
            this.log(piCommandLine(argv));

            return { code: 1, argv, used: 'printed' };
        }

        return new Promise<AskRunResult>((resolve) => {
            const child = spawn(this.binary!, argv, {
                cwd: request.cwd,
                stdio: 'inherit',
                env: process.env,
            });

            child.on('error', (error) => {
                this.log(`Cannot start pi: ${error.message}`);
                this.log(piCommandLine(argv));
                resolve({ code: 1, argv, used: 'printed' });
            });
            child.on('close', (code) => resolve({ code: code ?? 1, argv, used: 'spawn' }));
        });
    }
}

/**
 * pi SDK path. It reuses the same ~/.pi/agent/mcp.json and models.json the CLI installed and adds
 * the synced LANoMAT skills as extra skill paths.
 */
export class PiSdkAdapter implements AskAdapter {
    constructor(
        private readonly options: { agentDir?: string; cwd?: string } = {},
    ) {}

    async run(request: AskRequest): Promise<AskRunResult> {
        const argv = buildAskArgv(request);

        if (request.printCommandOnly) {
            process.stdout.write(`${piCommandLine(argv)}\n`);

            return { code: 0, argv, used: 'printed' };
        }

        const {
            createAgentSession,
            createMcpExtension,
            DefaultResourceLoader,
            ModelRuntime,
            resolveCliModel,
            SettingsManager,
        } = await import('@earendil-works/pi-coding-agent');
        const cwd = request.cwd ?? this.options.cwd ?? process.cwd();
        const agentDir = request.agentDir ?? this.options.agentDir;

        if (agentDir) {
            process.env.PI_CODING_AGENT_DIR = agentDir;
        }

        const settingsManager = SettingsManager.create(cwd, agentDir);
        const loader = new DefaultResourceLoader({
            cwd,
            agentDir: agentDir ?? (await import('@earendil-works/pi-coding-agent')).getAgentDir(),
            settingsManager,
            additionalSkillPaths: request.skillsDir ? [request.skillsDir] : undefined,
            extensionFactories: [createMcpExtension()],
        });

        await loader.reload();

        let model: unknown;

        if (request.model || request.provider) {
            const runtime = await ModelRuntime.create({ modelsPath: undefined });
            const resolution = resolveCliModel({
                cliModel: request.model,
                cliProvider: request.provider,
                modelRuntime: runtime,
            });

            if (resolution.error) {
                process.stderr.write(`${resolution.error}\n`);
            }

            model = resolution.model;
        }

        const { session } = await createAgentSession({
            cwd,
            agentDir,
            resourceLoader: loader,
            settingsManager,
            ...(model ? { model: model as never } : {}),
        });
        const unsubscribe = session.subscribe((event) => {
            if (event.type === 'message_update' && event.assistantMessageEvent.type === 'text_delta') {
                process.stdout.write(event.assistantMessageEvent.delta);
            }

            if (event.type === 'tool_execution_start') {
                process.stderr.write(`\n→ ${event.toolName}\n`);
            }
        });

        try {
            await session.prompt(request.prompt);
            process.stdout.write('\n');

            return { code: 0, argv, used: 'sdk' };
        } finally {
            unsubscribe();
            session.dispose();
        }
    }
}

export class AskService {
    constructor(private readonly adapter: AskAdapter) {}

    async ask(request: AskRequest): Promise<AskRunResult> {
        return this.adapter.run(request);
    }
}

export function createAskService(options: {
    preferSdk?: boolean;
    piBinary?: string | null;
    agentDir?: string;
    log?: (line: string) => void;
} = {}): AskService {
    const log = options.log ?? ((line: string) => process.stdout.write(`${line}\n`));

    if (options.preferSdk !== false) {
        return new AskService(new PiSdkAdapter({ agentDir: options.agentDir }));
    }

    return new AskService(new SpawnPiAdapter(options.piBinary ?? resolvePiBinary(), log));
}

export function modelArgs(resolved: ResolvedModel): { provider: string; model: string } {
    return { provider: resolved.provider, model: resolved.full };
}
