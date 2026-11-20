import { describe, expect, it, vi } from 'vitest';
import type { ApiClient } from '../src/http.js';
import type { RecipeDetail, RecipePlan } from '../src/types.js';
import { buildLaunchCommand, launchRecipe } from '../src/launch.js';
import { openBrowser } from '../src/auth.js';
import type { CommandExecutor } from '../src/runner.js';

function plan(overrides: Partial<RecipePlan> = {}): RecipePlan {
    return {
        recipe: { slug: 'ut2004', title: 'UT2004' },
        os: 'windows',
        target_dir: '/tmp/ut2004',
        steps: [],
        files: [],
        ...overrides,
    };
}

function apiFor(recipe: RecipeDetail, planValue: RecipePlan | null = null): ApiClient {
    return {
        baseUrl: 'http://lan.local',
        resolve: (path: string) => (path.startsWith('http') ? path : `http://lan.local${path}`),
        getRecipe: async () => recipe,
        createPlan: async () => {
            if (!planValue) {
                throw new Error('plan not expected');
            }

            return planValue;
        },
    } as unknown as ApiClient;
}

function captureExecutor(calls: Array<{ command: string; args: string[]; cwd: string }>): CommandExecutor {
    return async (command, args, options) => {
        calls.push({ command, args, cwd: options.cwd });

        return { code: 0, stdout: '', stderr: '' };
    };
}

describe('launch', () => {
    it('opens play_url in the browser and returns 0', async () => {
        const opened: string[] = [];
        const calls: Array<{ command: string }> = [];
        const result = await launchRecipe({
            api: apiFor({ slug: 'browser-game', title: 'Browser Game', play_url: 'https://play.example/game' }),
            slug: 'browser-game',
            os: 'linux',
            openUrl: (url) => opened.push(url),
            executor: captureExecutor(calls) as unknown as CommandExecutor,
        });

        expect(result).toEqual({
            code: 0,
            kind: 'play_url',
            url: 'https://play.example/game',
        });
        expect(opened).toEqual(['https://play.example/game']);
        expect(calls).toEqual([]);
    });

    it('opens the browser through spawn conventions', () => {
        const spawnImpl = vi.fn(() => ({ on: () => undefined, unref: () => undefined }));

        openBrowser('https://play.example/game', 'linux', spawnImpl as never);

        expect(spawnImpl).toHaveBeenCalledWith(
            'xdg-open',
            ['https://play.example/game'],
            expect.objectContaining({ detached: true, stdio: 'ignore' }),
        );
    });

    it('starts the UT2004 launch_target with cmd /c start on Windows', async () => {
        const calls: Array<{ command: string; args: string[]; cwd: string }> = [];
        const target = 'C:\\Games\\Unreal Tournament 2004\\System\\UT2004.exe';
        const result = await launchRecipe({
            api: apiFor({ slug: 'ut2004', title: 'UT2004', launch_target: target }),
            slug: 'ut2004',
            os: 'windows',
            executor: captureExecutor(calls),
        });

        expect(result.code).toBe(0);
        expect(calls[0]).toMatchObject({
            command: 'cmd',
            args: ['/d', '/s', '/c', `start "" "${target}"`],
        });
    });

    it('substitutes {{target}} and {{avatars}} from the plan', async () => {
        const calls: Array<{ command: string; args: string[]; cwd: string }> = [];
        const result = await launchRecipe({
            api: apiFor(
                {
                    slug: 'ut2004',
                    title: 'UT2004',
                    launch_target: 'wine "{{target}}/System/UT2004.exe" --avatar "{{avatars}}/Marcus.vrm"',
                },
                plan(),
            ),
            slug: 'ut2004',
            os: 'linux',
            avatarsDir: '/home/r/.local/share/lanomat/avatars',
            executor: captureExecutor(calls),
        });

        expect(result.command).toContain(
            '/home/r/.local/share/lanomat/avatars/Marcus.vrm',
        );
        expect(calls[0]?.args.at(-1)).toContain('/tmp/ut2004/System/UT2004.exe');
        expect(calls[0]?.cwd).toBe('/tmp/ut2004');
    });

    it('prints the command in dry-run without executing it', async () => {
        const lines: string[] = [];
        let executed = false;
        const result = await launchRecipe({
            api: apiFor({ slug: 'ut2004', title: 'UT2004', launch_target: 'C:\\Games\\UT2004.exe' }),
            slug: 'ut2004',
            os: 'windows',
            dryRun: true,
            executor: async () => {
                executed = true;

                return { code: 0, stdout: '', stderr: '' };
            },
            log: (line) => lines.push(line),
        });

        expect(result.code).toBe(0);
        expect(executed).toBe(false);
        expect(lines.join('\n')).toContain('[dry-run] cmd: start "" "C:\\Games\\UT2004.exe"');
    });

    it('exits 2 and suggests install when there is no target', async () => {
        const warnings: string[] = [];
        const result = await launchRecipe({
            api: apiFor({ slug: 'missing', title: 'Missing' }, plan({ target_dir: '/tmp/missing' })),
            slug: 'missing',
            os: 'linux',
            warn: (line) => warnings.push(line),
        });

        expect(result.code).toBe(2);
        expect(result.kind).toBe('missing');
        expect(warnings.join('\n')).toContain('lanomat-agent install missing');
    });

    it('builds a quoted Linux path and a raw command', () => {
        expect(buildLaunchCommand('linux', '/opt/UT2004/ut2004')).toEqual({
            shell: 'bash',
            run: "'/opt/UT2004/ut2004'",
        });
        expect(buildLaunchCommand('linux', 'wine UT2004.exe')).toEqual({
            shell: 'bash',
            run: 'wine UT2004.exe',
        });
    });
});
