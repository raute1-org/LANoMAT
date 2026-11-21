import type { ApiClient } from './http.js';
import type { Os, RecipeDetail, RecipePlan } from './types.js';
import { openBrowser } from './auth.js';
import { defaultAvatarsDir } from './config.js';
import { runPlanStep, substituteVariables, type CommandExecutor } from './runner.js';

export interface LaunchOptions {
    api: ApiClient;
    slug: string;
    os: Os;
    dryRun?: boolean;
    targetDir?: string;
    avatarsDir?: string;
    platform?: NodeJS.Platform;
    openUrl?: (url: string) => void;
    executor?: CommandExecutor;
    log?: (line: string) => void;
    warn?: (line: string) => void;
}

export interface LaunchResult {
    code: number;
    kind: 'play_url' | 'launch_target' | 'missing';
    url?: string;
    command?: string;
}

function shellQuote(value: string): string {
    return "'" + value.replace(/'/g, "'\\''") + "'";
}

function looksLikePath(value: string): boolean {
    return (
        value.startsWith('/') ||
        value.startsWith('~/') ||
        value.startsWith('./') ||
        value.startsWith('../') ||
        /^[A-Za-z]:[\\/]/.test(value) ||
        (!value.includes(' ') && (value.includes('/') || value.includes('\\')))
    );
}

/** The plan carries the recipe-level launch fields too; use it only when needed. */
function launchFields(recipe: RecipeDetail, plan: RecipePlan | null, os: Os): {
    playUrl?: string | null;
    target?: string | null;
    shell?: 'powershell' | 'cmd' | 'bash';
} {
    const targets = recipe.launch_targets ?? plan?.recipe?.launch_targets ?? [];
    const chosen = targets.find((target) => (target.os ?? 'any') === 'any' || target.os === os) ?? targets[0];
    const chosenCommand = chosen ? (chosen.target ?? chosen.command ?? null) : null;

    const explicit =
        recipe.launch_target ??
        plan?.recipe?.launch_target ??
        plan?.launch_target ??
        null;

    return {
        playUrl: recipe.play_url ?? plan?.recipe?.play_url ?? plan?.play_url ?? null,
        target: explicit ?? chosenCommand,
        // `launch_command` is a display hint ("lanomat-agent launch <slug>"),
        // never an executable — do not fall back to it.
        shell: explicit ? undefined : chosen?.shell,
    };
}

export function buildLaunchCommand(
    os: Os,
    target: string,
    shell?: 'powershell' | 'cmd' | 'bash',
): { shell: 'powershell' | 'cmd' | 'bash'; run: string } {
    if (shell) {
        return { shell, run: target };
    }

    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(target)) {
        return os === 'windows'
            ? { shell: 'cmd', run: 'start "" "' + target.replace(/"/g, '\\"') + '"' }
            : { shell: 'bash', run: `xdg-open ${shellQuote(target)}` };
    }

    if (os === 'windows') {
        return { shell: 'cmd', run: 'start "" "' + target.replace(/"/g, '\\"') + '"' };
    }

    return { shell: 'bash', run: looksLikePath(target) ? shellQuote(target) : target };
}

/**
 * `launch`: play_url wins (browser), otherwise run launch_target/launch_command through the same
 * command runner as install steps. A missing target is exit code 2 with an install suggestion.
 */
export async function launchRecipe(options: LaunchOptions): Promise<LaunchResult> {
    const log = options.log ?? (() => undefined);
    const warn = options.warn ?? (() => undefined);
    const platform = options.platform ?? process.platform;
    const openUrl = options.openUrl ?? ((url: string) => openBrowser(url, platform));
    const recipe = await options.api.getRecipe(options.slug);
    let plan: RecipePlan | null = null;
    let fields = launchFields(recipe, plan, options.os);

    if (!fields.playUrl && !fields.target) {
        plan = await options.api.createPlan(options.slug, options.os, {
            includeAvatars: false,
            dryRun: options.dryRun,
        });
        fields = launchFields(recipe, plan, options.os);
    }

    if (fields.playUrl) {
        const url = /^[a-z][a-z0-9+.-]*:/i.test(fields.playUrl)
            ? fields.playUrl
            : options.api.resolve(fields.playUrl);

        log(`${options.dryRun ? '[dry-run] ' : ''}Opening ${url}`);

        if (!options.dryRun) {
            openUrl(url);
        }

        return { code: 0, kind: 'play_url', url };
    }

    const rawTarget = fields.target;

    if (!rawTarget) {
        warn(`No launch target for "${options.slug}".`);
        warn(`Install it first: lanomat-agent install ${options.slug} --os ${options.os}`);
        warn('If the recipe only has a web play_url, use "lanomat-agent launch" after the server adds it.');

        return { code: 2, kind: 'missing' };
    }

    if (!plan && (rawTarget.includes('{{target}}') || rawTarget.includes('{{avatars}}'))) {
        plan = await options.api.createPlan(options.slug, options.os, { includeAvatars: false });
    }

    const targetDir = options.targetDir ?? plan?.target_dir ?? process.cwd();
    const avatarDir = options.avatarsDir ?? plan?.avatars_dir ?? defaultAvatarsDir({ env: process.env });
    const target = substituteVariables(rawTarget, {
        target: targetDir,
        avatars: avatarDir,
        slug: options.slug,
        os: options.os,
    });
    const command = buildLaunchCommand(options.os, target, fields.shell);

    if (options.dryRun) {
        log(`[dry-run] target: ${target}`);
    }

    log(`${options.os === 'windows' ? 'Starting' : 'Launching'} ${options.slug}: ${target}`);

    await runPlanStep(
        {
            id: 'launch',
            title: `Launch ${recipe.title ?? options.slug}`,
            commands: [command],
        },
        {
            cwd: targetDir,
            dryRun: options.dryRun,
            executor: options.executor,
            yes: true,
            log,
            warn,
        },
    );

    return {
        code: 0,
        kind: 'launch_target',
        command: `${command.shell}: ${command.run}`,
    };
}
