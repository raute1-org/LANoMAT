import type { PlanFile, PlanStep, RecipeDownload, RecipePlan, RecipeSummary } from './types.js';
import { formatBytes, truncateMiddle } from './util.js';

export interface CollectedDownload extends RecipeDownload {
    source: 'lan' | 'external';
    stepId?: string;
}

export function collectPlanDownloads(plan: RecipePlan): CollectedDownload[] {
    const seen = new Set<string>();
    const collected: CollectedDownload[] = [];

    const add = (download: RecipeDownload, stepId?: string): void => {
        const key = `${download.url}|${download.filename}`;

        if (seen.has(key)) {
            return;
        }

        seen.add(key);
        collected.push({
            ...download,
            source: download.source ?? 'lan',
            stepId,
        });
    };

    for (const step of plan.steps ?? []) {
        for (const download of step.downloads ?? []) {
            add(download, step.id);
        }
    }

    for (const file of plan.files ?? []) {
        const url = file.download_url ?? file.fallback_url;

        if (!url) {
            continue;
        }

        add(
            {
                url,
                filename: file.name ?? file.requirement,
                sha256: file.sha256,
                size_bytes: file.size_bytes,
                source: file.source ?? (file.status === 'external' ? 'external' : 'lan'),
                fallback_url: file.fallback_url,
            },
            undefined,
        );
    }

    return collected;
}

export function missingRequiredFiles(plan: RecipePlan): string[] {
    const missing = new Set<string>(plan.missing ?? []);

    for (const file of plan.files ?? []) {
        if (file.status === 'missing' && file.required !== false) {
            missing.add(file.name ?? file.label ?? file.requirement);
        }
    }

    return [...missing];
}

export function formatRecipeList(recipes: RecipeSummary[]): string {
    if (recipes.length === 0) {
        return 'No published recipes on this LANoMAT server.';
    }

    const rows: string[][] = [['SLUG', 'TITLE', 'PLATFORMS', 'VERSION', 'STATUS']];

    for (const recipe of recipes) {
        rows.push([
            recipe.slug,
            recipe.title,
            (recipe.platforms ?? []).join(', ') || '—',
            recipe.version ?? '—',
            recipe.status ?? '—',
        ]);
    }

    const widths = rows[0]!.map((_, index) => Math.max(...rows.map((row) => row[index]!.length)));

    return rows
        .map((row) => row.map((cell, index) => cell.padEnd(widths[index]!)).join('  ').trimEnd())
        .join('\n');
}

function fileSource(file: PlanFile): 'lan' | 'external' | 'missing' {
    if (file.status === 'missing') {
        return 'missing';
    }

    if (file.source) {
        return file.source;
    }

    return file.status === 'external' ? 'external' : 'lan';
}

function sourceLabel(source: 'lan' | 'external' | 'missing'): string {
    switch (source) {
        case 'lan':
            return '[LAN]';
        case 'external':
            return '[EXTERNAL]';
        case 'missing':
            return '[MISSING]';
    }
}

export function formatPlan(plan: RecipePlan, options: { color?: boolean } = {}): string {
    const color = options.color ?? false;
    const paint = (code: string, text: string): string => (color ? `${code}${text}\u001b[0m` : text);
    const lines: string[] = [];
    const title = plan.recipe?.title ?? plan.recipe?.slug ?? 'recipe';

    lines.push(paint('\u001b[1m', `LANoMAT install plan: ${title} (${plan.recipe?.slug ?? '?'})`));
    lines.push(`Version: ${plan.recipe?.version ?? 'unknown'}   OS: ${plan.os}`);
    lines.push(`Install dir: ${plan.target_dir}`);
    lines.push('');

    lines.push('Files:');

    if ((plan.files ?? []).length === 0) {
        lines.push('  (none listed)');
    }

    for (const file of plan.files ?? []) {
        const label = sourceLabel(fileSource(file));
        const parts = [file.label ?? file.name ?? file.requirement];

        if (file.size_bytes) {
            parts.push(formatBytes(file.size_bytes));
        }

        if (file.sha256) {
            parts.push(`sha256 ${truncateMiddle(file.sha256, 12)}`);
        }

        lines.push(`  ${label} ${parts.join(' · ')}`);

        if (file.status === 'external' && !file.download_url && file.fallback_url) {
            lines.push('         external only — use --allow-external to download from the internet');
        }
    }

    lines.push('');

    lines.push('Steps:');

    if ((plan.steps ?? []).length === 0) {
        lines.push('  (none)');
    }

    (plan.steps ?? []).forEach((step, index) => {
        lines.push(`  ${index + 1}. ${step.title}${step.optional ? ' [optional]' : ''}`);

        if (step.detail) {
            lines.push(`     ${step.detail}`);
        }

        for (const download of step.downloads ?? []) {
            const source = download.source ?? 'lan';
            const size = download.size_bytes ? ` · ${formatBytes(download.size_bytes)}` : '';

            lines.push(`     ${sourceLabel(source)} ${download.filename}${size}`);
        }

        for (const command of step.commands ?? []) {
            lines.push(`     $ ${command.shell}: ${command.run}`);
        }

        for (const check of step.verify ?? []) {
            lines.push(
                `     verify: ${check.type}${
                    'path' in check ? ` ${check.path}` : 'command' in check ? ` ${check.command}` : ''
                }`,
            );
        }
    });

    lines.push('');

    if ((plan.avatars ?? []).length > 0) {
        lines.push('Avatars:');

        for (const avatar of plan.avatars ?? []) {
            const lan = Boolean(avatar.download_url);
            const label = lan ? '[LAN]' : '[not on LAN]';
            const author = avatar.author ? `, ${avatar.author}` : '';
            const license = avatar.license ? `, ${avatar.license}` : '';
            const size = avatar.size_bytes ? `, ${formatBytes(avatar.size_bytes)}` : '';

            lines.push(`  ${label} ${avatar.label} (${avatar.id}${author}${license}${size})`);
        }

        lines.push('');
    }

    const missing = missingRequiredFiles(plan);

    if (missing.length > 0) {
        lines.push(paint('\u001b[31m', 'Missing required files:'));
        missing.forEach((entry) => lines.push(`  - ${entry}`));
        lines.push('Ask the Orga to upload these files to LANoMAT.');
    } else {
        lines.push(paint('\u001b[32m', 'All required files are available.'));
    }

    return lines.join('\n');
}

export function stepAppliesToOs(step: PlanStep, os: string): boolean {
    if (!step.os) {
        return true;
    }

    return Array.isArray(step.os) ? step.os.includes(os as 'windows' | 'linux') : step.os === os;
}
