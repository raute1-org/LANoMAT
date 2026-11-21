import type { ApiClient } from './http.js';
import type { LaunchTarget, Os, RecipeDetail, RecipePlan } from './types.js';
import { openBrowser } from './auth.js';
import { defaultAvatarsDir } from './config.js';
import { runPlanStep, substituteVariables, type CommandExecutor } from './runner.js';
import { validateRecipeSlug } from './util.js';

export interface LaunchOptions {
    api: ApiClient;
    slug: string;
    os: Os;
    dryRun?: boolean;
    targetDir?: string;
    avatarsDir?: string;
    /** Launch target id from `metadata.launch_targets` (flat, vr, …). */
    variant?: string;
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

/** A macOS application bundle (`UT2004.app`) must be handed to `open`, not executed. */
function isMacAppBundle(value: string): boolean {
    return /\.app\/?$/i.test(value);
}

/** The plan carries the recipe-level launch fields too; use it only when needed. */
function launchFields(recipe: RecipeDetail, plan: RecipePlan | null, os: Os, variant?: string): {
    playUrl?: string | null;
    target?: string | null;
    shell?: 'powershell' | 'cmd' | 'bash';
} {
    const targets = recipe.launch_targets ?? plan?.recipe?.launch_targets ?? null;
    const chosen = pickLaunchTarget(targets, os, variant);
    const chosenCommand = chosen ? (chosen.command ?? chosen.target ?? null) : null;

    const explicit =
        recipe.launch_target ??
        plan?.recipe?.launch_target ??
        plan?.launch_target ??
        null;

    // An explicit `--variant` wins over everything; otherwise the recipe-level
    // default target wins and the per-OS alternatives are the fallback. A
    // chosen target carries the shell it must run in.
    const useChosen = variant !== undefined ? chosenCommand !== null : explicit === null;
    const target = useChosen ? chosenCommand : explicit;

    return {
        playUrl: recipe.play_url ?? plan?.recipe?.play_url ?? plan?.play_url ?? null,
        target: target ?? explicit ?? null,
        shell: useChosen && chosen?.shell ? (chosen.shell as 'powershell' | 'cmd' | 'bash') : undefined,
    };
}

/**
 * Choose the launch target: an explicit `--variant <id>` wins, otherwise the
 * first entry that runs on this OS, otherwise the first entry (the server sorts
 * them most-useful-first). `null` when the recipe declares no targets.
 */
export function pickLaunchTarget(
    targets: LaunchTarget[] | null | undefined,
    os: Os,
    variant?: string,
): LaunchTarget | null {
    if (!targets || targets.length === 0) {
        return null;
    }

    if (variant) {
        return targets.find((entry) => entry.id === variant) ?? null;
    }

    return targets.find((entry) => !entry.os || entry.os === 'any' || entry.os === os) ?? targets[0] ?? null;
}

export function buildLaunchCommand(
    os: Os,
    target: string,
    shell?: 'powershell' | 'cmd' | 'bash',
): { shell: 'powershell' | 'cmd' | 'bash'; run: string } {
    if (shell) {
        return { shell, run: target };
    }

    const isUri = /^[a-z][a-z0-9+.-]*:\/\//i.test(target);

    if (os === 'windows') {
        // A registered URI scheme (steam://, lanomat://) is opened, not executed.
        return { shell: 'cmd', run: 'start "" "' + target.replace(/"/g, '\\"') + '"' };
    }

    if (os === 'macos' && (isUri || isMacAppBundle(target))) {
        // `.app` bundles and URI schemes are opened through LaunchServices.
        return { shell: 'bash', run: `open ${shellQuote(target)}` };
    }

    if (isUri) {
        // A registered URI scheme (steam://, lanomat://) is opened, not executed.
        return { shell: 'bash', run: `xdg-open ${shellQuote(target)}` };
    }

    return { shell: 'bash', run: looksLikePath(target) ? shellQuote(target) : target };
}

/**
 * `launch`: play_url wins (browser), otherwise run launch_target/launch_command through the same
 * command runner as install steps. A missing target is exit code 2 with an install suggestion.
 */
export async function launchRecipe(options: LaunchOptions): Promise<LaunchResult> {
    validateRecipeSlug(options.slug);
    const log = options.log ?? (() => undefined);
    const warn = options.warn ?? (() => undefined);
    const platform = options.platform ?? process.platform;
    const openUrl = options.openUrl ?? ((url: string) => openBrowser(url, platform));
    const recipe = await options.api.getRecipe(options.slug);
    let plan: RecipePlan | null = null;

    if (options.variant && (recipe.launch_targets?.length ?? 0) > 0
        && pickLaunchTarget(recipe.launch_targets ?? null, options.os, options.variant) === null) {
        const available = (recipe.launch_targets ?? []).map((entry) => entry.id).join(', ');

        warn(`Unknown launch target "${options.variant}" for "${options.slug}".`);
        warn(`Available targets: ${available}.`);

        return { code: 2, kind: 'missing' };
    }

    let fields = launchFields(recipe, plan, options.os, options.variant);

    if (!fields.playUrl && !fields.target) {
        plan = await options.api.createPlan(options.slug, options.os, {
            includeAvatars: false,
            dryRun: options.dryRun,
        });
        fields = launchFields(recipe, plan, options.os, options.variant);
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

    if (!plan && (rawTarget.includes('{{target}}') || rawTarget.includes('{{avatars}}') || rawTarget.includes('{{downloads}}'))) {
        plan = await options.api.createPlan(options.slug, options.os, {
            includeAvatars: false,
            dryRun: options.dryRun,
        });
    }

    const targetDir = options.targetDir ?? plan?.target_dir ?? process.cwd();
    const avatarDir = options.avatarsDir ?? plan?.avatars_dir ?? defaultAvatarsDir({ env: process.env });
    const target = substituteVariables(rawTarget, {
        target: targetDir,
        avatars: avatarDir,
        downloads: plan?.downloads_dir ?? targetDir,
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
