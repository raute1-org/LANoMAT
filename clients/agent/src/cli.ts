#!/usr/bin/env node
import { realpathSync } from 'node:fs';
import { hostname } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Command, Option } from 'commander';
import {
    configPath,
    defaultAvatarsDir,
    defaultSkillsDir,
    deleteConfig,
    loadConfig,
    piAgentDir,
    piSettingsPath,
    saveConfig,
    type PathEnvironment,
} from './config.js';
import {
    deviceName,
    loginInstructions,
    openBrowser,
    runDeviceLogin,
} from './auth.js';
import { ApiClient, ApiError } from './http.js';
import { formatExportLine, installMcpServer, piCommandLine, type McpExposure } from './mcp.js';
import { syncSkills } from './skills.js';
import { formatPlan, formatRecipeList } from './recipes.js';
import { runInstall, installChatPrompt } from './install.js';
import { launchRecipe } from './launch.js';
import { parseDeepLink } from './deeplink.js';
import { registerAgentScheme } from './register.js';
import { downloadAvatars, parseAvatarSelection, pickAvatars } from './avatars.js';
import { createProgressRenderer, promptSecret, promptYesNo, Logger } from './ui.js';
import { createAskService, buildAskArgv, resolvePiBinary, SpawnPiAdapter } from './ask.js';
import * as models from './models.js';
import { formatDoctorReport, runDoctor } from './doctor.js';
import type { AgentConfig, Os } from './types.js';
import { normalizeBaseUrl } from './util.js';

const DEFAULT_URL = 'http://lanomat.local';

interface CliContext {
    logger: Logger;
    paths: PathEnvironment;
    env: NodeJS.ProcessEnv;
}

function context(): CliContext {
    return { logger: new Logger(), paths: { env: process.env }, env: process.env };
}

function fail(message: string): never {
    throw new Error(message);
}

async function requireConfig(paths: PathEnvironment): Promise<AgentConfig> {
    const config = await loadConfig(paths);

    if (!config) {
        fail(`Not signed in. Run "lanomat-agent login --url ${DEFAULT_URL}" first.`);
    }

    return config;
}

function apiFor(config: AgentConfig, fetchImpl?: typeof fetch): ApiClient {
    return new ApiClient({ baseUrl: config.url, token: config.token, fetchImpl });
}

async function loadOrNull(paths: PathEnvironment): Promise<AgentConfig | null> {
    try {
        return await loadConfig(paths);
    } catch (error) {
        fail((error as Error).message);
    }
}

function parseOs(value: string): Os {
    const os = value.toLowerCase();

    if (os === 'windows' || os === 'linux') {
        return os;
    }

    fail(`Unsupported OS "${value}" (use windows or linux)`);
}

function collect(value: string, previous: string[] = []): string[] {
    return [...previous, value];
}

function printJson(value: unknown): void {
    process.stdout.write(`${JSON.stringify(value, null, 2)}\n`);
}


async function applyModelEnv(
    resolved: models.ResolvedModel,
    paths: PathEnvironment,
): Promise<void> {
    if (resolved.apiKeyEnv && process.env[resolved.apiKeyEnv]) {
        return;
    }

    const key = await models.resolveApiKey({
        provider: resolved.provider,
        env: process.env,
        envFile: join(configPath(paths), '..', 'venice.env'),
    });

    if (key.key && !process.env[key.envName]) {
        process.env[key.envName] = key.key;
    }

    if (resolved.provider === 'venice' && key.key && !process.env.VENICE_API_KEY) {
        process.env.VENICE_API_KEY = key.key;
    }
}

async function resolveAskModel(
    input: { cliModel?: string; cliProvider?: string; config: AgentConfig | null },
    paths: PathEnvironment,
): Promise<models.ResolvedModel> {
    const resolved = models.resolveModelWithConfig({
        cliModel: input.cliModel,
        cliProvider: input.cliProvider,
        env: process.env,
        config: input.config,
    });

    if (
        resolved.source === 'default' &&
        !input.config?.model &&
        !input.config?.modelRoles?.chat &&
        !process.env.LANOMAT_AGENT_MODEL &&
        !process.env.OPENAI_MODEL_NAME
    ) {
        const settings = await models.readSettings(piSettingsPath(paths)).catch(() => null);
        const saved = models.savedModelFromSettings(settings);

        if (saved) {
            return models.resolveModel({ savedModel: saved, env: process.env });
        }
    }

    return resolved;
}

async function executeAsk(
    request: Parameters<ReturnType<typeof createAskService>['ask']>[0],
    paths: PathEnvironment,
    logger: Logger,
): Promise<number> {
    const service = createAskService({ preferSdk: true, agentDir: piAgentDir(paths) });

    try {
        const result = await service.ask(request);

        return result.code;
    } catch (error) {
        logger.warn(`The pi SDK could not start the session: ${(error as Error).message}`);
        logger.line('Falling back to the pi CLI (if installed).');
        logger.line(`npm install -g @earendil-works/pi-coding-agent`);
        const binary = resolvePiBinary(process.env, process.platform);
        const argv = buildAskArgv(request);
        logger.line(piCommandLine(argv));

        if (!binary) {
            return 1;
        }

        const fallback = new SpawnPiAdapter(binary, (line) => logger.line(line));

        return (await fallback.run(request)).code;
    }
}

export function buildProgram(): Command {
    const program = new Command();

    program
        .name('lanomat-agent')
        .description('LANoMAT desktop agent: install LAN games with a pi coding agent.')
        .version('0.1.0');

    program
        .command('login')
        .description('Sign in with the LANoMAT device flow')
        .option('--url <url>', 'LANoMAT server URL', DEFAULT_URL)
        .option('--name <name>', 'device name shown in the admin panel')
        .option('--no-browser', 'do not open a browser')
        .action(async (opts: { url: string; name?: string; browser: boolean }) => {
            const { logger, paths } = context();
            const url = normalizeBaseUrl(opts.url);
            const existing = await loadOrNull(paths);
            const api = new ApiClient({ baseUrl: url });
            const name = opts.name ?? deviceName(hostname());

            const result = await runDeviceLogin({
                api,
                deviceName: name,
                onDevice: (device) => {
                    logger.heading(`Sign in to ${url}`);
                    logger.line(loginInstructions(device));

                    if (opts.browser) {
                        openBrowser(device.verification_uri);
                    }
                },
                onSlowDown: (seconds) => logger.dim(`Slow down: polling every ${seconds}s`),
                onPending: (attempt) => {
                    if (attempt > 1) {
                        logger.dim(`still waiting… (${attempt})`);
                    }
                },
            });
            const authed = new ApiClient({ baseUrl: url, token: result.token.access_token });
            let user: { id: string | number; name: string } = { id: 'unknown', name };

            try {
                const me = await authed.me();
                user = { id: me.user.id, name: me.user.name };
            } catch (error) {
                logger.warn(`Signed in, but /api/v1/me failed: ${(error as Error).message}`);
            }

            const config: AgentConfig = {
                url,
                token: result.token.access_token,
                token_expires_at: result.expiresAt,
                user,
                ...(existing?.model ? { model: existing.model } : {}),
                ...(existing?.modelRoles ? { modelRoles: existing.modelRoles } : {}),
            };

            const path = await saveConfig(config, paths);

            logger.success(`Signed in as ${user.name}. Config written to ${path}`);
            logger.line(`Next: lanomat-agent mcp install`);
            logger.line(`      lanomat-agent skills sync`);
            logger.line(`      lanomat-agent model setup`);
        });

    program
        .command('logout')
        .description('Delete the stored LANoMAT token')
        .action(async () => {
            const { logger, paths } = context();
            const removed = await deleteConfig(paths);

            logger.success(removed ? `Removed ${configPath(paths)}` : 'No config to remove.');
            logger.dim('MCP servers and synced skills were left in place.');
        });

    const mcp = program.command('mcp').description('Configure the pi MCP integration');

    mcp.command('install')
        .description('Write/merge ~/.pi/agent/mcp.json')
        .option('--server-name <name>', 'MCP server name', 'lanomat')
        .addOption(
            new Option('--exposure <mode>', 'tool exposure')
                .choices(['direct', 'codemode', 'deferred'])
                .default('deferred'),
        )
        .action(async (opts: { serverName: string; exposure: McpExposure }) => {
            const { logger, paths } = context();
            const config = await requireConfig(paths);
            const result = await installMcpServer({
                ...paths,
                url: config.url,
                serverName: opts.serverName,
                exposure: opts.exposure,
            });

            logger.success(`MCP server "${result.serverName}" written to ${result.path}`);
            logger.line(`URL: ${result.entry.url}`);
            logger.line(`Exposure: ${result.entry.exposure}`);
            logger.line('');
            logger.heading('Shell export (contains the token, keep it secret):');
            logger.line(formatExportLine(config.token));
            logger.dim('`lanomat-agent ask` sets LANOMAT_TOKEN for the pi session automatically.');
        });

    const skills = program.command('skills').description('Sync recipe skills for pi');

    skills
        .command('sync')
        .description('Fetch every published recipe as a pi sub-skill')
        .option('--dest <dir>', 'skills directory', defaultSkillsDir())
        .option('--prune', 'remove lanomat-* skills whose recipe vanished')
        .action(async (opts: { dest: string; prune?: boolean }) => {
            const { logger, paths } = context();
            const config = await requireConfig(paths);
            const api = apiFor(config);
            const result = await syncSkills({ api, dest: opts.dest, prune: opts.prune });

            logger.success(
                `Skills synced: ${result.written.length} written, ${result.unchanged.length} unchanged${
                    result.pruned.length > 0 ? `, ${result.pruned.length} pruned` : ''
                }.`,
            );
            logger.dim(opts.dest);

            for (const warning of result.warnings) {
                logger.warn(warning);
            }
        });

    program
        .command('recipes')
        .description('List published recipes')
        .option('--json', 'print raw JSON')
        .action(async (opts: { json?: boolean }) => {
            const { logger, paths } = context();
            const config = await requireConfig(paths);
            const recipes = await apiFor(config).listRecipes();

            if (opts.json) {
                printJson(recipes);

                return;
            }

            logger.line(formatRecipeList(recipes));
        });

    program
        .command('plan')
        .argument('<slug>', 'recipe slug')
        .description('Show an install plan')
        .option('--os <os>', 'windows or linux', process.platform === 'win32' ? 'windows' : 'linux')
        .option('--event <slug>', 'event context')
        .option('--json', 'print the raw plan JSON')
        .option('--allow-external', 'announce external downloads as allowed')
        .action(async (slug: string, opts: { os: string; event?: string; json?: boolean; allowExternal?: boolean }) => {
            const { logger, paths } = context();
            const config = await requireConfig(paths);
            const plan = await apiFor(config).createPlan(slug, parseOs(opts.os), {
                eventSlug: opts.event,
                includeAvatars: true,
            });

            if (opts.json) {
                printJson(plan);

                return;
            }

            logger.line(formatPlan(plan, { color: logger.color }));

            const external =
                plan.files.some((file) => file.status === 'external') ||
                plan.steps.some((step) => step.downloads?.some((download) => download.source === 'external'));

            if (external) {
                if (opts.allowExternal) {
                    logger.warn('External downloads are allowed (not from the LAN).');
                } else {
                    logger.warn('External downloads are disabled; use --allow-external to fetch them from the internet.');
                }
            }
        });

    program
        .command('install')
        .argument('<slug>', 'recipe slug')
        .description('Install a LAN game from a LANoMAT recipe')
        .option('--os <os>', 'windows or linux', process.platform === 'win32' ? 'windows' : 'linux')
        .option('--event <slug>', 'event context')
        .option('-y, --yes', 'no prompts; run optional steps and select no avatars')
        .option('--avatar <id>', 'avatar id (repeatable, or "all")', collect)
        .option('--allow-external', 'allow internet fallbacks for files not on the LAN')
        .option('--dry-run', 'print the plan and commands without downloading or executing')
        .option('--jobs <n>', 'parallel downloads', '4')
        .option('--chat', 'open the pi agent after a successful install')
        .option('--model <model>', 'pi model for --chat')
        .action(
            async (
                slug: string,
                opts: {
                    os: string;
                    event?: string;
                    yes?: boolean;
                    avatar?: string[];
                    allowExternal?: boolean;
                    dryRun?: boolean;
                    jobs: string;
                    chat?: boolean;
                    model?: string;
                },
            ) => {
                const { logger, paths } = context();
                const config = await requireConfig(paths);
                const api = apiFor(config);
                const progress = createProgressRenderer(logger);
                const result = await runInstall({
                    api,
                    slug,
                    os: parseOs(opts.os),
                    yes: opts.yes,
                    avatarIds: opts.avatar,
                    allowExternal: opts.allowExternal,
                    dryRun: opts.dryRun,
                    jobs: Number(opts.jobs) || 4,
                    eventSlug: opts.event,
                    interactive: process.stdin.isTTY,
                    log: (line) => logger.line(line),
                    warn: (line) => logger.warn(line),
                    confirm: promptYesNo,
                    onProgress: (update) =>
                        progress.update({
                            ...update,
                            state: update.percent === 100 ? 'done' : 'downloading',
                        }),
                });

                progress.finish();

                if (result.code === 2) {
                    process.exitCode = 2;

                    return;
                }

                if (result.downloadedFiles.length > 0 || result.downloadedAvatars.length > 0) {
                    logger.success(
                        `Installed ${slug}: ${result.downloadedFiles.length} file(s), ${result.downloadedAvatars.length} avatar(s).`,
                    );
                }

                if (opts.chat && !opts.dryRun) {
                    const resolved = await resolveAskModel(
                        { cliModel: opts.model, config },
                        paths,
                    );

                    await applyModelEnv(resolved, paths);
                    process.env.LANOMAT_TOKEN = config.token;
                    process.exitCode = await executeAsk(
                        {
                            prompt: installChatPrompt(slug, parseOs(opts.os)),
                            model: resolved.full,
                            provider: resolved.provider,
                            skillsDir: defaultSkillsDir(paths),
                        },
                        paths,
                        logger,
                    );
                }
            },
        );

    program
        .command('launch')
        .argument('<slug>', 'recipe slug')
        .description('Launch an installed game, or open its web play_url')
        .option('--os <os>', 'windows or linux', process.platform === 'win32' ? 'windows' : 'linux')
        .option('--target <dir>', 'install dir substituted for {{target}}')
        .option('--avatars <dir>', 'avatar dir substituted for {{avatars}}')
        .option('--variant <id>', 'launch target id from the recipe (flat, vr, …)')
        .option('--dry-run', 'print the launch command without running it')
        .action(
            async (
                slug: string,
                opts: { os: string; target?: string; avatars?: string; variant?: string; dryRun?: boolean },
            ) => {
                const { logger, paths } = context();
                const config = await requireConfig(paths);
                const result = await launchRecipe({
                    api: apiFor(config),
                    slug,
                    os: parseOs(opts.os),
                    targetDir: opts.target,
                    avatarsDir: opts.avatars,
                    variant: opts.variant,
                    dryRun: opts.dryRun,
                    log: (line) => logger.line(line),
                    warn: (line) => logger.warn(line),
                });

                process.exitCode = result.code;
            },
        );

    program
        .command('open')
        .argument('<url>', 'lanomat://install/<slug>, lanomat://launch/<slug> or lanomat://play/<slug>')
        .description('Handle a LANoMAT deep link (install or launch)')
        .option('--os <os>', 'windows or linux', process.platform === 'win32' ? 'windows' : 'linux')
        .option('-y, --yes', 'no prompts for install')
        .option('--avatar <id>', 'avatar id for install (repeatable, or "all")', collect)
        .option('--allow-external', 'allow internet fallbacks for install')
        .option('--dry-run', 'print the plan/launch command only')
        .action(
            async (
                input: string,
                opts: {
                    os: string;
                    yes?: boolean;
                    avatar?: string[];
                    allowExternal?: boolean;
                    dryRun?: boolean;
                },
            ) => {
                const { logger, paths } = context();
                const link = parseDeepLink(input);
                const config = await requireConfig(paths);
                const api = apiFor(config);

                if (link.action === 'launch') {
                    const result = await launchRecipe({
                        api,
                        slug: link.slug,
                        os: parseOs(opts.os),
                        dryRun: opts.dryRun,
                        log: (line) => logger.line(line),
                        warn: (line) => logger.warn(line),
                    });

                    process.exitCode = result.code;

                    return;
                }

                const progress = createProgressRenderer(logger);
                const result = await runInstall({
                    api,
                    slug: link.slug,
                    os: parseOs(opts.os),
                    yes: opts.yes,
                    avatarIds: opts.avatar,
                    allowExternal: opts.allowExternal,
                    dryRun: opts.dryRun,
                    interactive: process.stdin.isTTY,
                    log: (line) => logger.line(line),
                    warn: (line) => logger.warn(line),
                    confirm: promptYesNo,
                    onProgress: (update) =>
                        progress.update({
                            ...update,
                            state: update.percent === 100 ? 'done' : 'downloading',
                        }),
                });

                progress.finish();
                process.exitCode = result.code;
            },
        );

    program
        .command('register')
        .description('Register the lanomat:// scheme for the web games page')
        .option('-y, --yes', 'execute the Windows registry command (Linux always writes)')
        .action(async (opts: { yes?: boolean }) => {
            const { logger } = context();
            const cliPath = (() => {
                try {
                    return realpathSync(process.argv[1] ?? '');
                } catch {
                    return process.argv[1] ?? '';
                }
            })();
            const result = await registerAgentScheme({
                yes: opts.yes,
                cliPath,
                log: (line) => logger.line(line),
                warn: (line) => logger.warn(line),
            });

            if (result.path) {
                logger.success(`Registered lanomat:// in ${result.path}`);
            } else if (result.written) {
                logger.success('Registered lanomat://.');
            } else if (result.platform === 'win32') {
                logger.dim('Windows registry command printed; use --yes to execute it.');
            }
        });

    program
        .command('avatars')
        .description('Browse and download VRM avatars from the LAN server')
        .option('--recipe <slug>', 'only avatars for this recipe')
        .option('--q <text>', 'search labels/author')
        .option('--all', 'select every avatar with a LAN copy')
        .option('--avatar <id>', 'avatar id (repeatable)', collect)
        .option('--dest <dir>', 'avatar directory', defaultAvatarsDir())
        .option('--jobs <n>', 'parallel downloads', '4')
        .action(
            async (opts: {
                recipe?: string;
                q?: string;
                all?: boolean;
                avatar?: string[];
                dest: string;
                jobs: string;
            }) => {
                const { logger, paths } = context();
                const config = await requireConfig(paths);
                const api = apiFor(config);
                const avatars = await api.listAvatars({ recipe: opts.recipe, q: opts.q });

                if (avatars.length === 0) {
                    logger.warn('No avatars returned by the server.');

                    return;
                }

                let selected: string[];

                if (opts.all) {
                    selected = parseAvatarSelection('all', avatars).ids;
                } else if (opts.avatar && opts.avatar.length > 0) {
                    const parsed = parseAvatarSelection(opts.avatar, avatars);
                    selected = parsed.ids;
                    parsed.unknown.forEach((unknown) => logger.warn(`Unknown/unavailable avatar: ${unknown}`));
                } else if (process.stdin.isTTY) {
                    selected = await pickAvatars(avatars);
                } else {
                    logger.line(avatars.map((avatar) => `${avatar.id}\t${avatar.label}`).join('\n'));
                    logger.warn('Not a TTY. Use --all or --avatar <id> to choose avatars.');

                    return;
                }

                if (selected.length === 0) {
                    logger.dim('No avatars selected.');

                    return;
                }

                const progress = createProgressRenderer(logger);
                const result = await downloadAvatars({
                    api,
                    avatars,
                    selectedIds: selected,
                    destDir: opts.dest,
                    jobs: Number(opts.jobs) || 4,
                    onProgress: (update) =>
                        progress.update({
                            ...update,
                            state: update.percent === 100 ? 'done' : 'downloading',
                        }),
                });

                progress.finish();
                logger.success(
                    `Avatars: ${result.downloaded.length} downloaded, ${result.skipped.length} already verified.`,
                );
                logger.dim(`Saved in ${opts.dest} (manifest: ${result.manifestPath})`);
            },
        );

    program
        .command('ask')
        .argument('<prompt...>', 'what you want the agent to do')
        .description('Chat with the pi agent connected to LANoMAT')
        .option('--model <model>', 'override the pi model (provider/model)')
        .option('--provider <provider>', 'override the pi provider')
        .option('--print-command', 'only print the exact pi command')
        .action(async (promptParts: string[], opts: { model?: string; provider?: string; printCommand?: boolean }) => {
            const { logger, paths } = context();
            const config = await loadOrNull(paths);
            const resolved = await resolveAskModel(
                { cliModel: opts.model, cliProvider: opts.provider, config },
                paths,
            );
            await applyModelEnv(resolved, paths);

            const request = {
                prompt: promptParts.join(' '),
                model: resolved.full,
                provider: resolved.provider,
                skillsDir: defaultSkillsDir(paths),
                printCommandOnly: opts.printCommand,
            };

            if (opts.printCommand) {
                logger.line(piCommandLine(buildAskArgv(request)));

                return;
            }

            process.exitCode = await executeAsk(request, paths, logger);
        });

    const model = program.command('model').description('Configure the agent model');

    model
        .command('setup')
        .description('Write/merge a Venice (or OpenAI-compatible) provider for pi')
        .option('--key <key>', 'API key (never written to the LANoMAT config)')
        .option('--model <model>', 'provider/model or model id')
        .option('--provider <provider>', 'provider id (default venice)')
        .action(async (opts: { key?: string; model?: string; provider?: string }) => {
            const { logger, paths } = context();
            const config = await loadOrNull(paths);
            const resolved = await resolveAskModel(
                { cliModel: opts.model, cliProvider: opts.provider, config },
                paths,
            );
            const key = await models.resolveApiKey({
                flagKey: opts.key,
                provider: resolved.provider,
                env: process.env,
                envFile: join(configPath(paths), '..', 'venice.env'),
            });
            let apiKey = key.key;

            if (!apiKey && process.stdin.isTTY) {
                apiKey = await promptSecret(`API key for ${resolved.provider} (leave empty to configure without one): `);
            }

            const result = await models.setupModel({
                env: process.env,
                requestedModel: resolved.full,
                provider: resolved.provider,
                apiKey,
                apiKeyEnv: key.envName,
                config,
                saveConfig: (updated) => saveConfig(updated, paths),
            });

            logger.success(`Provider "${result.provider}" written to ${result.modelsPath}`);
            logger.line(`Settings: ${result.settingsPath}`);
            logger.line(`Default model: ${result.full}`);
            logger.dim(`Model source: ${resolved.source}`);

            for (const warning of result.warnings) {
                logger.warn(warning);
            }

            if (apiKey) {
                logger.heading('Shell export (keep it secret):');

                for (const line of models.formatKeyCommands(apiKey, result.apiKeyEnv)) {
                    logger.line(line);
                }
            } else {
                logger.warn(
                    `No API key found. models.json references $${result.apiKeyEnv}; export it before running pi.`,
                );
            }

            logger.heading('Run the agent:');
            logger.line(`pi --model ${result.full}`);
            logger.line(`lanomat-agent ask "install ut2004 for me"`);
        });

    model
        .command('roles')
        .description('Show or edit model roles (chat, decision, stt, tts)')
        .option('--set <role=model>', 'set a role (repeatable)', collect)
        .action(async (opts: { set?: string[] }) => {
            const { logger, paths } = context();
            const config = await requireConfig(paths);
            let current = config;

            if (opts.set && opts.set.length > 0) {
                const updates: Partial<Record<'chat' | 'decision' | 'stt' | 'tts', string | null>> = {};

                for (const assignment of opts.set) {
                    const separator = assignment.indexOf('=');

                    if (separator === -1) {
                        fail(`Invalid --set "${assignment}" (expected role=provider/model)`);
                    }

                    const role = assignment.slice(0, separator).trim();
                    const value = assignment.slice(separator + 1).trim();

                    if (!models.MODEL_ROLE_NAMES.includes(role as never)) {
                        fail(`Unknown role "${role}" (use ${models.MODEL_ROLE_NAMES.join(', ')})`);
                    }

                    if (value === '' || value === 'null' || value === '-') {
                        updates[role as 'chat'] = null;
                        continue;
                    }

                    updates[role as 'chat'] = value.includes('/') ? value : `venice/${value}`;
                }

                const baseRoles = {
                    ...config.modelRoles,
                    chat:
                        config.modelRoles?.chat ??
                        config.model ??
                        models.resolveModel({ env: process.env }).full,
                };
                const merged = models.mergeModelRoles(baseRoles, updates);
                const next: AgentConfig = {
                    ...config,
                    modelRoles: merged,
                    ...(merged.chat ? { model: merged.chat } : {}),
                };

                await saveConfig(next, paths);
                current = next;
                logger.success('Model roles updated.');
            }

            const rows = models.resolveModelRoles(current, process.env);

            logger.heading('Model roles');
            rows.forEach((row) => {
                logger.line(
                    `${row.name.padEnd(8)} ${row.value ?? '(unset)'}  [${row.source}]`,
                );
            });
            logger.dim('Only "chat" is used at runtime today; decision/stt/tts are reserved.');
        });

    program
        .command('doctor')
        .description('Check config, server, MCP, pi, skills and the Venice model')
        .option('--json', 'print the report as JSON')
        .action(async (opts: { json?: boolean }) => {
            const { logger, paths } = context();
            const config = await loadOrNull(paths);
            const report = await runDoctor({ ...paths, config });

            if (opts.json) {
                printJson(report);
            } else {
                logger.line(formatDoctorReport(report));
            }

            if (!report.ok) {
                process.exitCode = 1;
            }
        });

    return program;
}

export async function main(argv: string[] = process.argv): Promise<void> {
    if (process.env.LANOMAT_PI_DIR && !process.env.PI_CODING_AGENT_DIR) {
        process.env.PI_CODING_AGENT_DIR = process.env.LANOMAT_PI_DIR;
    }

    const program = buildProgram();

    try {
        await program.parseAsync(argv);
    } catch (error) {
        const logger = new Logger();

        if (error instanceof ApiError) {
            logger.error(error.message);
        } else {
            logger.error((error as Error).message);
        }

        process.exitCode = 1;
    }
}

function isEntryPoint(): boolean {
    const entry = process.argv[1];

    if (!entry) {
        return false;
    }

    try {
        return fileURLToPath(import.meta.url) === realpathSync(entry);
    } catch {
        return false;
    }
}

if (isEntryPoint()) {
    void main();
}

