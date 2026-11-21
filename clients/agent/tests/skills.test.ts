import { mkdir, readFile, stat, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ApiClient } from '../src/http.js';
import { hasSkillFrontmatter, skillDirName, syncSkills } from '../src/skills.js';
import { removeDir, readJson, tempDir } from './helpers.js';

function api(recipes: Array<{ slug: string; title: string; version?: string }>, skillText: () => string): ApiClient {
    return {
        baseUrl: 'http://lan.local',
        resolve: (path: string) => `http://lan.local${path}`,
        listRecipes: async () => recipes,
        getSkill: async () => skillText(),
    } as unknown as ApiClient;
}

const skill = `---
name: ut2004
description: Install Unreal Tournament 2004 on the LAN
---
# UT2004
`;

describe('skills sync', () => {
    let dir: string;
    const clock = () => new Date('2026-11-20T10:00:00.000Z');

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('writes SKILL.md, recipe.json and the source marker', async () => {
        const dest = join(dir, 'skills');
        const result = await syncSkills({
            api: api([{ slug: 'ut2004', title: 'UT2004', version: '1.2' }], () => skill),
            dest,
            clock,
        });
        const skillDir = join(dest, 'lanomat-ut2004');

        expect(result.written).toEqual(['ut2004']);
        expect(await readFile(join(skillDir, 'SKILL.md'), 'utf8')).toBe(skill);
        expect(await readJson(join(skillDir, 'recipe.json'))).toEqual({
            slug: 'ut2004',
            title: 'UT2004',
            version: '1.2',
            source_url: 'http://lan.local/api/v1/recipes/ut2004',
        });
        expect(await readJson(join(skillDir, '.lanomat-source'))).toEqual({
            url: 'http://lan.local',
            fetched_at: '2026-11-20T10:00:00.000Z',
        });
    });

    it('is idempotent: a second sync writes nothing', async () => {
        const dest = join(dir, 'skills');
        const options = {
            api: api([{ slug: 'ut2004', title: 'UT2004' }], () => skill),
            dest,
            clock,
        };

        await syncSkills(options);
        const before = (await stat(join(dest, 'lanomat-ut2004', 'SKILL.md'))).mtimeMs;
        const second = await syncSkills(options);
        const after = (await stat(join(dest, 'lanomat-ut2004', 'SKILL.md'))).mtimeMs;

        expect(second.written).toEqual([]);
        expect(second.unchanged).toEqual(['ut2004']);
        expect(after).toBe(before);
    });

    it('rewrites when the server skill changes', async () => {
        const dest = join(dir, 'skills');
        let text = skill;
        const options = {
            api: api([{ slug: 'ut2004', title: 'UT2004' }], () => text),
            dest,
            clock,
        };

        await syncSkills(options);
        text = `${skill}\nNew section.\n`;
        const second = await syncSkills(options);

        expect(second.written).toEqual(['ut2004']);
        expect(await readFile(join(dest, 'lanomat-ut2004', 'SKILL.md'), 'utf8')).toContain('New section.');
    });

    it('prunes only lanomat-* dirs with a source marker', async () => {
        const dest = join(dir, 'skills');
        const options = {
            api: api([{ slug: 'ut2004', title: 'UT2004' }], () => skill),
            dest,
            clock,
        };

        await syncSkills(options);
        await mkdir(join(dest, 'lanomat-old'), { recursive: true });
        await writeFile(join(dest, 'lanomat-old', '.lanomat-source'), '{"url":"http://old"}');
        await mkdir(join(dest, 'my-own-skill'), { recursive: true });
        await mkdir(join(dest, 'lanomat-unmanaged'), { recursive: true });
        const result = await syncSkills({ ...options, prune: true });

        expect(result.pruned).toEqual(['lanomat-old']);
        expect(await stat(join(dest, 'my-own-skill'))).toBeTruthy();
        expect(await stat(join(dest, 'lanomat-unmanaged'))).toBeTruthy();
        expect(await stat(join(dest, 'lanomat-ut2004'))).toBeTruthy();
    });

    it('warns when the skill has no frontmatter and still writes it', async () => {
        const dest = join(dir, 'skills');
        const result = await syncSkills({
            api: api([{ slug: 'plain', title: 'Plain' }], () => '# no frontmatter'),
            dest,
            clock,
        });

        expect(result.warnings[0]).toContain('frontmatter');
        expect(result.written).toEqual(['plain']);
    });

    it('detects the required frontmatter keys', () => {
        expect(hasSkillFrontmatter(skill)).toBe(true);
        expect(hasSkillFrontmatter('---\nname: x\n---\nbody')).toBe(false);
        expect(skillDirName('UT 2004')).toBe('lanomat-ut-2004');
    });
});
