import { describe, expect, it } from 'vitest';
import type { RecipePlan } from '../src/types.js';
import {
    collectPlanDownloads,
    formatPlan,
    formatRecipeList,
    missingRequiredFiles,
    stepAppliesToOs,
} from '../src/recipes.js';

const plan: RecipePlan = {
    recipe: { slug: 'ut2004', title: 'Unreal Tournament 2004', version: '1.2' },
    os: 'windows',
    target_dir: 'C:\\LAN\\UT2004',
    files: [
        {
            requirement: 'ut2004.iso',
            status: 'matched',
            name: 'ut2004.iso',
            size_bytes: 4_500_000_000,
            sha256: 'a'.repeat(64),
            download_url: 'http://lan.local/api/v1/files/1/download',
        },
        {
            requirement: 'bonus.zip',
            status: 'external',
            name: 'bonus.zip',
            fallback_url: 'https://example.com/bonus.zip',
        },
        { requirement: 'patch.exe', status: 'missing' },
    ],
    steps: [
        {
            id: 'copy',
            title: 'Copy the game files',
            commands: [{ shell: 'powershell', run: 'Copy-Item ut2004.iso {{target_dir}}' }],
            downloads: [
                {
                    url: 'http://lan.local/api/v1/files/1/download',
                    filename: 'ut2004.iso',
                    sha256: 'a'.repeat(64),
                    source: 'lan',
                },
            ],
            verify: [{ type: 'file_exists', path: '{{target_dir}}\\ut2004.exe' }],
        },
        {
            id: 'bonus',
            title: 'Install bonus content',
            optional: true,
            commands: [{ shell: 'bash', run: 'unzip bonus.zip', optional: true }],
        },
    ],
    avatars: [
        {
            id: 'marcus',
            label: 'Marcus',
            author: 'Epic',
            license: 'CC-BY',
            size_bytes: 10_000_000,
            download_url: 'http://lan.local/api/v1/avatars/marcus/download',
            match_name: 'Marcus.vrm',
        },
        { id: 'missing-avatar', label: 'Missing', author: 'Someone' },
    ],
};

describe('recipes and plan rendering', () => {
    it('renders files, source labels, steps and avatars', () => {
        const text = formatPlan(plan);

        expect(text).toContain('LANoMAT install plan: Unreal Tournament 2004 (ut2004)');
        expect(text).toContain('[LAN] ut2004.iso');
        expect(text).toContain('[EXTERNAL] bonus.zip');
        expect(text).toContain('[MISSING] patch.exe');
        expect(text).toContain('$ powershell: Copy-Item ut2004.iso');
        expect(text).toContain('verify: file_exists');
        expect(text).toContain('2. Install bonus content [optional]');
        expect(text).toContain('[LAN] Marcus');
        expect(text).toContain('[not on LAN] Missing');
    });

    it('lists every missing required file and tells the user to ask the Orga', () => {
        const text = formatPlan(plan);

        expect(text).toContain('Missing required files:');
        expect(text).toContain('- patch.exe');
        expect(text).toContain('Ask the Orga');
        expect(missingRequiredFiles(plan)).toEqual(['patch.exe']);
    });

    it('deduplicates downloads from steps and files', () => {
        const downloads = collectPlanDownloads(plan);

        expect(downloads.filter((download) => download.filename === 'ut2004.iso')).toHaveLength(1);
        expect(downloads.find((download) => download.filename === 'bonus.zip')?.source).toBe('external');
    });

    it('formats the recipe list and handles an empty list', () => {
        const table = formatRecipeList([
            { slug: 'ut2004', title: 'UT2004', platforms: ['windows'], version: '1.2', status: 'published' },
            { slug: 'cs16', title: 'Counter-Strike 1.6', platforms: ['windows', 'linux'], status: 'published' },
        ]);

        expect(table).toContain('SLUG');
        expect(table).toContain('ut2004');
        expect(table).toContain('windows, linux');
        expect(formatRecipeList([])).toContain('No published recipes');
    });

    it('filters steps by OS', () => {
        expect(stepAppliesToOs({ id: 'a', title: 'A' }, 'linux')).toBe(true);
        expect(stepAppliesToOs({ id: 'b', title: 'B', os: 'windows' }, 'linux')).toBe(false);
        expect(stepAppliesToOs({ id: 'c', title: 'C', os: ['windows', 'linux'] }, 'linux')).toBe(true);
    });
});
