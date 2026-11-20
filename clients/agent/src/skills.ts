import { readdir, readFile, rm, stat } from 'node:fs/promises';
import { join } from 'node:path';
import type { ApiClient } from './http.js';
import type { RecipeSummary } from './types.js';
import { ensureDir, readJsonFile, sanitizeSlug, writeFileAtomic } from './util.js';

export interface SkillSource {
    url: string;
    fetched_at: string;
}

export interface SkillSyncOptions {
    api: ApiClient;
    dest: string;
    prune?: boolean;
    clock?: () => Date;
    recipes?: RecipeSummary[];
}

export interface SkillSyncResult {
    written: string[];
    unchanged: string[];
    pruned: string[];
    warnings: string[];
}

export function skillDirName(slug: string): string {
    return `lanomat-${sanitizeSlug(slug)}`;
}

export function recipeManifest(slug: string, recipe: RecipeSummary, sourceUrl: string): string {
    return `${JSON.stringify(
        {
            slug,
            title: recipe.title,
            version: recipe.version ?? null,
            source_url: sourceUrl,
        },
        null,
        2,
    )}\n`;
}

export function sourceMarker(url: string, fetchedAt: string): string {
    return `${JSON.stringify({ url, fetched_at: fetchedAt }, null, 2)}\n`;
}

export function hasSkillFrontmatter(markdown: string): boolean {
    if (!markdown.startsWith('---\n') && !markdown.startsWith('---\r\n')) {
        return false;
    }

    const end = markdown.indexOf('\n---', 4);

    if (end === -1) {
        return false;
    }

    const frontmatter = markdown.slice(4, end);

    return /^name:\s*\S+/m.test(frontmatter) && /^description:\s*\S+/m.test(frontmatter);
}

async function readText(path: string): Promise<string | null> {
    try {
        return await readFile(path, 'utf8');
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
            return null;
        }

        throw error;
    }
}

/**
 * One recipe = one pi sub-skill under `<dest>/lanomat-<slug>`. A second sync with unchanged
 * server content writes nothing (not even a new fetched_at), so it is safe to run after every login.
 * Pruning only removes `lanomat-*` directories that carry a `.lanomat-source` marker.
 */
export async function syncSkills(options: SkillSyncOptions): Promise<SkillSyncResult> {
    const clock = options.clock ?? (() => new Date());
    const result: SkillSyncResult = { written: [], unchanged: [], pruned: [], warnings: [] };
    const recipes = options.recipes ?? (await options.api.listRecipes());
    const seen = new Set<string>();

    await ensureDir(options.dest, 0o700);

    for (const recipe of recipes) {
        let slug: string;

        try {
            slug = sanitizeSlug(recipe.slug);
        } catch (error) {
            result.warnings.push((error as Error).message);
            continue;
        }

        if (seen.has(slug)) {
            continue;
        }

        seen.add(slug);

        const dir = join(options.dest, skillDirName(slug));
        const skillPath = join(dir, 'SKILL.md');
        const manifestPath = join(dir, 'recipe.json');
        const sourcePath = join(dir, '.lanomat-source');
        const sourceUrl = options.api.resolve(`/api/v1/recipes/${encodeURIComponent(slug)}`);

        const [markdown, existingMarkdown, existingManifest, existingSource] = await Promise.all([
            options.api.getSkill(slug),
            readText(skillPath),
            readText(manifestPath),
            readJsonFile<SkillSource>(sourcePath),
        ]);

        if (!hasSkillFrontmatter(markdown)) {
            result.warnings.push(`${slug}: SKILL.md has no name/description frontmatter`);
        }

        const nextManifest = recipeManifest(slug, recipe, sourceUrl);
        const contentChanged =
            existingMarkdown !== markdown ||
            existingManifest !== nextManifest ||
            existingSource?.url !== options.api.baseUrl;

        if (!contentChanged) {
            result.unchanged.push(slug);
            continue;
        }

        await ensureDir(dir, 0o700);
        await writeFileAtomic(skillPath, markdown.endsWith('\n') ? markdown : `${markdown}\n`, 0o644);
        await writeFileAtomic(manifestPath, nextManifest, 0o644);
        await writeFileAtomic(sourcePath, sourceMarker(options.api.baseUrl, clock().toISOString()), 0o644);
        result.written.push(slug);
    }

    if (options.prune) {
        const entries = await readdir(options.dest, { withFileTypes: true }).catch(() => []);

        for (const entry of entries) {
            if (!entry.isDirectory() || !entry.name.startsWith('lanomat-')) {
                continue;
            }

            const dir = join(options.dest, entry.name);

            try {
                await stat(join(dir, '.lanomat-source'));
            } catch {
                continue;
            }

            const slug = entry.name.slice('lanomat-'.length);

            if (!seen.has(slug)) {
                await rm(dir, { recursive: true, force: true });
                result.pruned.push(entry.name);
            }
        }
    }

    return result;
}
