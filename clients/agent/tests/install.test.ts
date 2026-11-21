import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ApiClient } from '../src/http.js';
import type { RecipePlan } from '../src/types.js';
import { runInstall, selectInstallAvatars } from '../src/install.js';
import { removeDir, tempDir } from './helpers.js';

function plan(overrides: Partial<RecipePlan> = {}): RecipePlan {
    return {
        recipe: { slug: 'ut2004', title: 'UT2004' },
        os: 'linux',
        target_dir: overrides.target_dir ?? '/tmp/ut2004',
        steps: [],
        files: [],
        avatars: [],
        missing: [],
        ...overrides,
    };
}

function apiFor(value: RecipePlan): ApiClient {
    return {
        baseUrl: 'http://lan.local',
        resolve: (path: string) => `http://lan.local${path}`,
        createPlan: async () => value,
        listRecipeAvatars: async () => [],
        headersFor: () => ({}),
    } as unknown as ApiClient;
}

describe('install', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('exits 2 and names the files to upload when required files are missing', async () => {
        const warnings: string[] = [];
        const result = await runInstall({
            api: apiFor(
                plan({
                    files: [{ requirement: 'ut2004.iso', status: 'missing' }],
                    missing: ['ut2004.iso'],
                }),
            ),
            slug: 'ut2004',
            os: 'linux',
            interactive: false,
            log: () => undefined,
            warn: (line) => warnings.push(line),
        });

        expect(result.code).toBe(2);
        expect(result.missing).toEqual(['ut2004.iso']);
        expect(warnings.join('\n')).toContain('Ask the Orga');
    });

    it('prints a dry-run plan without downloading or executing', async () => {
        const lines: string[] = [];
        let fetched = false;
        const target = join(dir, 'game');
        const result = await runInstall({
            api: apiFor(
                plan({
                    target_dir: target,
                    files: [
                        {
                            requirement: 'game.iso',
                            status: 'matched',
                            name: 'game.iso',
                            download_url: 'http://lan.local/api/v1/files/1/download',
                        },
                    ],
                    steps: [{ id: 's', title: 'Copy', commands: [{ shell: 'bash', run: 'cp game.iso .' }] }],
                }),
            ),
            slug: 'ut2004',
            os: 'linux',
            dryRun: true,
            yes: true,
            interactive: false,
            fetchImpl: async () => {
                fetched = true;

                return new Response('x');
            },
            log: (line) => lines.push(line),
            warn: () => undefined,
        });

        expect(result.code).toBe(0);
        expect(result.downloadedFiles).toEqual([]);
        expect(fetched).toBe(false);
        expect(lines.join('\n')).toContain('[dry-run] bash: cp game.iso .');
    });

    it('skips external downloads unless --allow-external', async () => {
        const warnings: string[] = [];
        const result = await runInstall({
            api: apiFor(
                plan({
                    target_dir: join(dir, 'game'),
                    steps: [
                        {
                            id: 's',
                            title: 'Bonus',
                            downloads: [
                                {
                                    url: 'https://example.com/bonus.zip',
                                    filename: 'bonus.zip',
                                    source: 'external',
                                },
                            ],
                        },
                    ],
                }),
            ),
            slug: 'ut2004',
            os: 'linux',
            yes: true,
            interactive: false,
            log: () => undefined,
            warn: (line) => warnings.push(line),
        });

        expect(result.code).toBe(0);
        expect(result.skippedExternal).toEqual(['bonus.zip']);
        expect(warnings.join('\n')).toContain('EXTERNAL download(s) skipped');
    });

    it('downloads LAN files and runs the steps', async () => {
        const target = join(dir, 'game');
        const result = await runInstall({
            api: apiFor(
                plan({
                    target_dir: target,
                    files: [
                        {
                            requirement: 'game.iso',
                            status: 'matched',
                            name: 'game.iso',
                            download_url: 'http://lan.local/api/v1/files/1/download',
                        },
                    ],
                    steps: [{ id: 's', title: 'Done', verify: [{ type: 'file_exists', path: 'game.iso' }] }],
                }),
            ),
            slug: 'ut2004',
            os: 'linux',
            yes: true,
            interactive: false,
            fetchImpl: async () => new Response('ISO', { headers: { 'content-length': '3' } }),
            log: () => undefined,
            warn: () => undefined,
        });

        expect(result.code).toBe(0);
        expect(result.downloadedFiles).toEqual([join(target, 'game.iso')]);
        expect(await readFile(join(target, 'game.iso'), 'utf8')).toBe('ISO');
    });

    it('installs the macOS steps and downloads, skipping other platforms', async () => {
        const target = join(dir, 'game');
        const executed: string[] = [];
        const result = await runInstall({
            api: apiFor(
                plan({
                    target_dir: target,
                    steps: [
                        {
                            id: 'win',
                            title: 'Windows only',
                            os: 'windows',
                            commands: [{ shell: 'powershell', run: 'Write-Host win' }],
                            downloads: [
                                { url: 'http://lan.local/api/v1/files/win/download', filename: 'win.zip', source: 'lan' },
                            ],
                        },
                        {
                            id: 'mac',
                            title: 'macOS only',
                            os: 'macos',
                            commands: [
                                { shell: 'powershell', run: 'Write-Host wrong' },
                                { shell: 'bash', run: 'echo mac' },
                            ],
                            downloads: [
                                { url: 'http://lan.local/api/v1/files/mac/download', filename: 'mac.dmg', source: 'lan' },
                            ],
                        },
                    ],
                }),
            ),
            slug: 'ut2004',
            os: 'macos',
            yes: true,
            interactive: false,
            executor: async (command, args) => {
                executed.push(args.at(-1) ?? command);

                return { code: 0, stdout: '', stderr: '' };
            },
            fetchImpl: async () => new Response('MAC', { headers: { 'content-length': '3' } }),
            log: () => undefined,
            warn: () => undefined,
        });

        expect(result.code).toBe(0);
        expect(result.downloadedFiles).toEqual([join(target, 'mac.dmg')]);
        expect(executed).toEqual(['echo mac']);
    });

    it('does not prompt for avatars in a dry run, even on a TTY', async () => {
        const lines: string[] = [];
        const result = await runInstall({
            api: apiFor(
                plan({
                    avatars: [
                        {
                            id: 'a',
                            label: 'A',
                            download_url: 'http://lan.local/api/v1/avatars/a/download',
                        },
                    ],
                }),
            ),
            slug: 'ut2004',
            os: 'linux',
            dryRun: true,
            interactive: true,
            log: (line) => lines.push(line),
            warn: () => undefined,
        });

        expect(result.code).toBe(0);
        expect(result.downloadedAvatars).toEqual([]);
        expect(lines.join('\n')).toContain('[dry-run] Target dir');
    });

    it('rejects a path-traversal slug before calling the API', async () => {
        let planned = false;
        const api = {
            baseUrl: 'http://lan.local',
            resolve: (path: string) => `http://lan.local${path}`,
            createPlan: async () => {
                planned = true;

                return plan();
            },
            listRecipeAvatars: async () => [],
            headersFor: () => ({}),
        } as unknown as ApiClient;

        await expect(runInstall({ api, slug: '../../etc/passwd', os: 'linux' })).rejects.toThrow(
            'Invalid recipe slug "../../etc/passwd": path traversal is not allowed',
        );
        expect(planned).toBe(false);
    });

    it('selects no avatars with --yes unless --avatar is given', async () => {
        expect(await selectInstallAvatars({ avatars: [], yes: true })).toEqual({ ids: [], unknown: [] });
        expect(
            await selectInstallAvatars({
                avatars: [
                    {
                        id: 'a',
                        label: 'A',
                        download_url: 'http://lan.local/api/v1/avatars/a/download',
                    },
                ],
                avatarIds: ['a'],
                yes: true,
            }),
        ).toEqual({ ids: ['a'], unknown: [] });
    });
});
