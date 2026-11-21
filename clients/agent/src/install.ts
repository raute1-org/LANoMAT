import type { ApiClient } from './http.js';
import type { Avatar, DownloadTask, Os, RecipePlan } from './types.js';
import { defaultAvatarsDir } from './config.js';
import { collectPlanDownloads, missingRequiredFiles, formatPlan, stepAppliesToOs } from './recipes.js';
import { avatarTasks, downloadAvatars, isAvatarOnLan, parseAvatarSelection, pickAvatars } from './avatars.js';
import { downloadMany, type DownloadEngineOptions } from './download.js';
import { runPlanStep, type CommandExecutor } from './runner.js';
import { ensureDir, joinUrl, sanitizeName } from './util.js';

export interface InstallOptions {
    api: ApiClient;
    slug: string;
    os: Os;
    yes?: boolean;
    avatarIds?: string[];
    allowExternal?: boolean;
    dryRun?: boolean;
    jobs?: number;
    eventSlug?: string;
    targetDir?: string;
    avatarsDir?: string;
    interactive?: boolean;
    executor?: CommandExecutor;
    confirm?: (question: string) => Promise<boolean>;
    log?: (line: string) => void;
    warn?: (line: string) => void;
    fetchImpl?: DownloadEngineOptions['fetchImpl'];
    onProgress?: DownloadEngineOptions['onProgress'];
    clock?: () => Date;
}

export interface InstallResult {
    code: number;
    plan: RecipePlan;
    downloadedFiles: string[];
    downloadedAvatars: string[];
    skippedExternal: string[];
    missing: string[];
    avatarDir: string;
}

export class MissingFilesError extends Error {
    readonly missing: string[];

    constructor(missing: string[]) {
        super(`Missing required files: ${missing.join(', ')}`);
        this.name = 'MissingFilesError';
        this.missing = missing;
    }
}

export function avatarDestDir(plan: RecipePlan, options: InstallOptions, env: NodeJS.ProcessEnv): string {
    return options.avatarsDir || plan.avatars_dir || defaultAvatarsDir({ env });
}

export async function selectInstallAvatars(options: {
    avatars: Avatar[];
    avatarIds?: string[];
    yes?: boolean;
    interactive?: boolean;
}): Promise<{ ids: string[]; unknown: string[] }> {
    if (options.avatarIds && options.avatarIds.length > 0) {
        return parseAvatarSelection(options.avatarIds, options.avatars);
    }

    if (options.yes) {
        return { ids: [], unknown: [] };
    }

    if (options.interactive === false) {
        return { ids: [], unknown: [] };
    }

    const ids = await pickAvatars(options.avatars);

    return { ids, unknown: [] };
}

function fileTasks(options: {
    api: ApiClient;
    plan: RecipePlan;
    downloadDir: string;
    allowExternal: boolean;
}): { tasks: DownloadTask[]; skippedExternal: string[]; external: string[] } {
    const downloadsDir = options.downloadDir;
    const tasks: DownloadTask[] = [];
    const skippedExternal: string[] = [];
    const external: string[] = [];

    for (const download of collectPlanDownloads(options.plan)) {
        if (download.source === 'external') {
            external.push(download.filename);

            if (!options.allowExternal) {
                skippedExternal.push(download.filename);
                continue;
            }

            const url = options.api.resolve(download.fallback_url ?? download.url);

            tasks.push({
                url,
                filename: sanitizeName(download.filename),
                destDir: downloadsDir,
                sha256: download.sha256,
                size_bytes: download.size_bytes,
                source: 'external',
                headers: options.api.headersFor(url),
            });
            continue;
        }

        const url = options.api.resolve(download.url);

        tasks.push({
            url,
            filename: sanitizeName(download.filename),
            destDir: downloadsDir,
            sha256: download.sha256,
            size_bytes: download.size_bytes,
            source: 'lan',
            headers: options.api.headersFor(url),
        });
    }

    return { tasks, skippedExternal, external };
}

function expandTargetDir(value: string, vars: Record<string, string>): string {
    return value.replace(/\{\{([a-z0-9_]+)\}\}/gi, (match, name: string) => vars[name.toLowerCase()] ?? match);
}

/**
 * The one-shot installer: plan → availability → avatars → LAN downloads → steps → verify.
 * Exit code 2 means "ask the Orga to upload the missing files".
 */
export async function runInstall(options: InstallOptions): Promise<InstallResult> {
    const log = options.log ?? (() => undefined);
    const warn = options.warn ?? (() => undefined);
    const env = process.env;
    const plan = await options.api.createPlan(options.slug, options.os, {
        eventSlug: options.eventSlug,
        includeAvatars: true,
    });
    const steps = plan.steps.filter((step) => stepAppliesToOs(step, options.os));
    const planForDownloads: RecipePlan = { ...plan, steps };
    const avatarDir = avatarDestDir(plan, options, env);
    const targetDir =
        options.targetDir ??
        expandTargetDir(plan.target_dir || process.cwd(), {
            home: process.env.HOME ?? '',
            avatars: avatarDir,
        });
    const missing = missingRequiredFiles(plan);
    const empty: InstallResult = {
        code: 0,
        plan,
        downloadedFiles: [],
        downloadedAvatars: [],
        skippedExternal: [],
        missing,
        avatarDir,
    };

    log(formatPlan(plan));

    const avatars = plan.avatars && plan.avatars.length > 0 ? plan.avatars : await options.api.listRecipeAvatars(options.slug);
    const selection = await selectInstallAvatars({
        avatars,
        avatarIds: options.avatarIds,
        yes: options.yes,
        interactive: options.interactive,
    });

    for (const unknown of selection.unknown) {
        warn(`Unknown or unavailable avatar: ${unknown}`);
    }

    if (missing.length > 0 && !options.allowExternal) {
        warn(`Cannot install: ${missing.length} required file(s) are missing on the LAN.`);
        warn('Ask the Orga to upload them to LANoMAT, or re-run with --allow-external to use internet fallbacks.');

        return { ...empty, code: 2 };
    }

    const downloadsDir = plan.downloads_dir
        ? expandTargetDir(plan.downloads_dir, { home: process.env.HOME ?? '', avatars: avatarDir })
        : targetDir;

    const files = fileTasks({
        api: options.api,
        plan: planForDownloads,
        downloadDir: downloadsDir,
        allowExternal: options.allowExternal ?? false,
    });

    if (files.skippedExternal.length > 0) {
        warn(
            `EXTERNAL download(s) skipped: ${files.skippedExternal.join(', ')} — re-run with --allow-external to fetch them from the internet.`,
        );
    }

    if (files.external.length > 0 && options.allowExternal) {
        warn(`EXTERNAL download(s) enabled: ${files.external.join(', ')} (not from the LAN).`);
    }

    const variables: Record<string, string> = {
        avatars: avatarDir,
        target: targetDir,
        target_dir: targetDir,
        downloads: downloadsDir,
        os: options.os,
        slug: options.slug,
        event: options.eventSlug ?? '',
        recipe_dir: targetDir,
    };

    if (options.dryRun) {
        log('');
        log(`[dry-run] Target dir: ${targetDir}`);
        log(`[dry-run] Avatar dir: ${avatarDir}`);
        log(`[dry-run] ${files.tasks.length} LAN file download(s)`);

        for (const task of files.tasks) {
            log(`  [${task.source}] ${task.filename} ← ${task.url}`);
        }

        if (selection.ids.length > 0) {
            log(`[dry-run] ${selection.ids.length} avatar download(s) → ${avatarDir}`);
        }

        for (const step of steps) {
            await runPlanStep(step, {
                cwd: targetDir,
                variables,
                yes: options.yes,
                dryRun: true,
                executor: options.executor,
                log,
                warn,
            });
        }

        return { ...empty, skippedExternal: files.skippedExternal };
    }

    await ensureDir(targetDir, 0o755);

    const downloadedFiles: string[] = [];

    if (files.tasks.length > 0) {
        log('');
        log(`Downloading ${files.tasks.length} file(s) from LANoMAT…`);

        const results = await downloadMany(files.tasks, {
            jobs: options.jobs ?? 4,
            fetchImpl: options.fetchImpl,
            onProgress: options.onProgress,
            clock: options.clock ? () => options.clock!().getTime() : undefined,
        });

        downloadedFiles.push(...results.map((result) => result.path));
    }

    const downloadedAvatars: string[] = [];

    if (selection.ids.length > 0) {
        log(`Downloading ${selection.ids.length} avatar(s) to ${avatarDir}…`);

        const avatarResult = await downloadAvatars({
            api: options.api,
            avatars,
            selectedIds: selection.ids,
            destDir: avatarDir,
            jobs: options.jobs,
            fetchImpl: options.fetchImpl,
            onProgress: options.onProgress,
            clock: options.clock,
        });

        downloadedAvatars.push(...avatarResult.downloaded);
    }

    for (const step of steps) {
        await runPlanStep(step, {
            cwd: targetDir,
            variables,
            yes: options.yes,
            executor: options.executor,
            confirm: options.confirm,
            log,
            warn,
        });
    }

    return {
        ...empty,
        downloadedFiles,
        downloadedAvatars,
        skippedExternal: files.skippedExternal,
    };
}

/** Build the prompt used by `install --chat` and `ask` helpers. */
export function installChatPrompt(slug: string, os: Os): string {
    return `I just ran the LANoMAT installer for the recipe "${slug}" on ${os}. Check the installation, explain what was installed, and offer the next steps.`;
}

export function avatarCatalogUrl(api: ApiClient, slug: string): string {
    return joinUrl(api.baseUrl, `/api/v1/avatars?recipe=${encodeURIComponent(slug)}`);
}

export function avatarAvailable(avatar: Avatar): boolean {
    return isAvatarOnLan(avatar);
}

export function avatarDownloadTasks(
    api: ApiClient,
    avatars: Avatar[],
    ids: string[],
    destDir: string,
): DownloadTask[] {
    return avatarTasks(api, avatars, ids, destDir);
}
