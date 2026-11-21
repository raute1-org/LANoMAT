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
            // cmd /s /c strips exactly the outer pair, so the runner wraps the whole script.
            args: ['/d', '/s', '/c', `"start "" "${target}""`],
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

    it('rejects a path-traversal slug before fetching the recipe', async () => {
        let fetched = false;
        const api = {
            baseUrl: 'http://lan.local',
            resolve: (path: string) => `http://lan.local${path}`,
            getRecipe: async () => {
                fetched = true;

                return { slug: 'x', title: 'X' };
            },
        } as unknown as ApiClient;

        await expect(launchRecipe({ api, slug: '..', os: 'linux' })).rejects.toThrow(
            'path traversal is not allowed',
        );
        expect(fetched).toBe(false);
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

    it('opens URI launch targets with the platform opener', () => {
        expect(buildLaunchCommand('windows', 'steam://rungameid/10')).toEqual({
            shell: 'cmd',
            run: 'start "" "steam://rungameid/10"',
        });
        expect(buildLaunchCommand('linux', 'steam://rungameid/10')).toEqual({
            shell: 'bash',
            run: "xdg-open 'steam://rungameid/10'",
        });
    });

    it('opens macOS .app bundles and URI targets through LaunchServices', () => {
        expect(buildLaunchCommand('macos', '/Applications/UT2004.app')).toEqual({
            shell: 'bash',
            run: "open '/Applications/UT2004.app'",
        });
        expect(buildLaunchCommand('macos', '/games/UT 2004.app/')).toEqual({
            shell: 'bash',
            run: "open '/games/UT 2004.app/'",
        });
        expect(buildLaunchCommand('macos', 'steam://rungameid/10')).toEqual({
            shell: 'bash',
            run: "open 'steam://rungameid/10'",
        });
    });

    it('runs a plain macOS binary outside a bundle directly', () => {
        expect(buildLaunchCommand('macos', '/opt/ut2004/System/ut2004-bin')).toEqual({
            shell: 'bash',
            run: "'/opt/ut2004/System/ut2004-bin'",
        });
        expect(buildLaunchCommand('macos', 'wine UT2004.exe')).toEqual({
            shell: 'bash',
            run: 'wine UT2004.exe',
        });
    });

    it('launches the macOS launch target selected by os: macos', async () => {
        const calls: Array<{ command: string; args: string[]; cwd: string }> = [];
        const result = await launchRecipe({
            api: apiFor(
                {
                    slug: 'ut2004',
                    title: 'UT2004',
                    launch_targets: [
                        { id: 'flat-windows', os: 'windows', shell: 'powershell', command: 'Start-Process "{{target}}/System/UT2004.exe"' },
                        { id: 'flat-macos', os: 'macos', command: '{{target}}/UT2004.app' },
                    ],
                },
                plan({ os: 'macos', target_dir: '/tmp/ut2004' }),
            ),
            slug: 'ut2004',
            os: 'macos',
            targetDir: '/tmp/ut2004',
            executor: captureExecutor(calls) as unknown as CommandExecutor,
        });

        expect(result.code).toBe(0);
        expect(calls).toHaveLength(1);
        expect(calls[0].command).toBe('bash');
        expect(calls[0].args).toEqual(['-c', "open '/tmp/ut2004/UT2004.app'"]);
    });

    it('runs the launch target for the requested OS with its shell', async () => {
        const calls: Array<{ command: string; args: string[]; cwd: string }> = [];
        const result = await launchRecipe({
            api: apiFor(
                {
                    slug: 'ut2004',
                    title: 'UT2004',
                    launch_targets: [
                        { id: 'flat-windows', os: 'windows', shell: 'powershell', command: 'Start-Process "{{target}}/System/UT2004.exe"' },
                        { id: 'flat-linux', os: 'linux', shell: 'bash', command: '"{{target}}/System/ut2004-bin-amd64"' },
                    ],
                },
                plan({ target_dir: '/tmp/ut2004' }),
            ),
            slug: 'ut2004',
            os: 'linux',
            targetDir: '/tmp/ut2004',
            executor: captureExecutor(calls) as unknown as CommandExecutor,
        });

        expect(result.code).toBe(0);
        expect(calls).toHaveLength(1);
        expect(calls[0].command).toBe('bash');
        expect(calls[0].args).toEqual(['-c', '"/tmp/ut2004/System/ut2004-bin-amd64"']);
    });

    it('prefers the default launch_target over the alternatives', async () => {
        const calls: Array<{ command: string; args: string[]; cwd: string }> = [];
        await launchRecipe({
            api: apiFor(
                {
                    slug: 'flat-first',
                    title: 'Flat First',
                    launch_target: '/opt/game/game',
                    launch_targets: [{ id: 'vr', os: 'linux', shell: 'bash', command: '/opt/game/game-vr' }],
                },
                plan({ target_dir: '/opt/game' }),
            ),
            slug: 'flat-first',
            os: 'linux',
            targetDir: '/opt/game',
            executor: captureExecutor(calls) as unknown as CommandExecutor,
        });

        expect(calls[0].args).toEqual(['-c', "'/opt/game/game'"]);
    });
});
