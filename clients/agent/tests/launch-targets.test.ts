import { describe, expect, it } from 'vitest';
import type { ApiClient } from '../src/http.js';
import type { RecipeDetail } from '../src/types.js';
import { launchRecipe, pickLaunchTarget } from '../src/launch.js';

const targets = [
    { id: 'flat', label: 'Flat', os: 'windows', command: '{{target}}/UT2004.exe' },
    { id: 'vr', label: 'VR', os: 'windows', command: '{{target}}/Play-VR.bat' },
    { id: 'flat-linux', label: 'Flat (Linux)', os: 'linux', command: '{{target}}/ut2004-bin-amd64' },
];

function apiFor(recipe: RecipeDetail): ApiClient {
    return {
        baseUrl: 'http://lan.local',
        resolve: (path: string) => (path.startsWith('http') ? path : `http://lan.local${path}`),
        getRecipe: async () => recipe,
        // A {{target}} command makes the agent ask for the install dir.
        createPlan: async () => ({
            recipe: { slug: recipe.slug, title: recipe.title },
            os: 'windows',
            target_dir: '/games/ut2004',
            steps: [],
            files: [],
        }),
    } as unknown as ApiClient;
}

describe('pickLaunchTarget', () => {
    it('returns null when the recipe has no targets', () => {
        expect(pickLaunchTarget(null, 'windows')).toBeNull();
        expect(pickLaunchTarget([], 'windows')).toBeNull();
    });

    it('picks the first target that runs on this OS', () => {
        expect(pickLaunchTarget(targets, 'linux')?.id).toBe('flat-linux');
        expect(pickLaunchTarget(targets, 'windows')?.id).toBe('flat');
    });

    it('honours an explicit variant id, including one for another OS', () => {
        expect(pickLaunchTarget(targets, 'windows', 'vr')?.id).toBe('vr');
        expect(pickLaunchTarget(targets, 'windows', 'flat-linux')?.id).toBe('flat-linux');
    });

    it('returns null for an unknown variant instead of silently using the default', () => {
        expect(pickLaunchTarget(targets, 'windows', 'nope')).toBeNull();
    });

    it('falls back to the first target when no entry matches the OS', () => {
        expect(pickLaunchTarget([{ id: 'only', os: 'macos', command: 'x' }], 'windows')?.id).toBe('only');
    });

    it('picks the macOS target on macOS', () => {
        const macTargets = [
            { id: 'flat', label: 'Flat', os: 'windows', command: '{{target}}/UT2004.exe' },
            { id: 'flat-macos', label: 'Flat (macOS)', os: 'macos', command: '{{target}}/UT2004.app' },
        ];

        expect(pickLaunchTarget(macTargets, 'macos')?.id).toBe('flat-macos');
        expect(pickLaunchTarget(macTargets, 'linux')?.id).toBe('flat');
    });
});

describe('launch with the canonical target contract', () => {
    it('runs the chosen variant command', async () => {
        const calls: string[] = [];
        const recipe: RecipeDetail = {
            slug: 'ut2004',
            title: 'UT2004',
            launch_target: '{{target}}/UT2004.exe',
            launch_targets: targets,
        };

        const result = await launchRecipe({
            api: apiFor(recipe),
            slug: 'ut2004',
            os: 'windows',
            variant: 'vr',
            targetDir: '/games/ut2004',
            dryRun: true,
            log: (line) => calls.push(line),
            executor: async () => ({ code: 0, stdout: '', stderr: '' }),
        });

        expect(result.code).toBe(0);
        expect(result.kind).toBe('launch_target');
        expect(result.command).toContain('Play-VR.bat');
        expect(calls.join('\n')).not.toContain('UT2004.exe');
    });

    it('fails with code 2 and names the available ids for an unknown variant', async () => {
        const warnings: string[] = [];
        const recipe: RecipeDetail = {
            slug: 'ut2004',
            title: 'UT2004',
            launch_targets: targets,
        };

        const result = await launchRecipe({
            api: apiFor(recipe),
            slug: 'ut2004',
            os: 'windows',
            variant: 'nope',
            dryRun: true,
            log: () => undefined,
            warn: (line) => warnings.push(line),
            executor: async () => ({ code: 0, stdout: '', stderr: '' }),
        });

        expect(result.code).toBe(2);
        expect(warnings.join('\n')).toContain('flat, vr, flat-linux');
    });
});
