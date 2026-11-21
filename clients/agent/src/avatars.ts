import { createInterface } from 'node:readline';
import { emitKeypressEvents } from 'node:readline';
import { readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { ApiClient } from './http.js';
import type { Avatar, AvatarManifest, DownloadTask } from './types.js';
import { downloadMany, type DownloadEngineOptions } from './download.js';
import { ensureDir, readJsonFile, sanitizeName, writeFileAtomic } from './util.js';

export function isAvatarOnLan(avatar: Avatar): boolean {
    return typeof avatar.download_url === 'string' && avatar.download_url.length > 0;
}

export function avatarFilename(avatar: Avatar): string {
    const candidate = avatar.match_name || basenameOf(avatar.download_url) || `${avatar.id}.vrm`;
    const safe = sanitizeName(candidate);

    return safe.toLowerCase().endsWith('.vrm') ? safe : `${safe}.vrm`;
}

function basenameOf(url: string | undefined): string | undefined {
    if (!url) {
        return undefined;
    }

    try {
        const path = new URL(url).pathname;
        const base = path.split('/').filter(Boolean).pop();

        return base ? decodeURIComponent(base) : undefined;
    } catch {
        return undefined;
    }
}

export function formatAvatarLine(avatar: Avatar, selected: boolean): string {
    const box = selected ? '[x]' : '[ ]';
    const license = avatar.license ? `, ${avatar.license}` : '';
    const size = avatar.size_bytes ? `, ${formatSize(avatar.size_bytes)}` : '';
    const availability = isAvatarOnLan(avatar) ? '' : ' (not on the LAN yet)';

    return `${box} ${avatar.label} (${avatar.author ?? 'unknown'}${license}${size})${availability}`;
}

function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Non-TTY selection grammar: `all`, 1-based indexes ("1,3"), avatar ids, labels or match names.
 * Avatars without a LAN copy are ignored (they cannot be downloaded by design).
 */
export function parseAvatarSelection(
    input: string | string[] | undefined,
    avatars: Avatar[],
): { ids: string[]; unknown: string[] } {
    const tokens = (Array.isArray(input) ? input : [input ?? ''])
        .flatMap((value) => value.split(/[\s,]+/))
        .map((value) => value.trim())
        .filter(Boolean);
    const ids: string[] = [];
    const unknown: string[] = [];

    if (tokens.length === 0) {
        return { ids, unknown };
    }

    if (tokens.length === 1 && tokens[0]?.toLowerCase() === 'all') {
        return { ids: avatars.filter(isAvatarOnLan).map((avatar) => avatar.id), unknown };
    }

    for (const token of tokens) {
        const numeric = /^\d+$/.test(token) ? Number(token) : undefined;
        const matches = avatars.filter(
            (avatar) =>
                avatar.id === token ||
                avatar.label.toLowerCase() === token.toLowerCase() ||
                avatar.match_name?.toLowerCase() === token.toLowerCase() ||
                (numeric !== undefined && avatars[numeric - 1]?.id === avatar.id),
        );

        if (matches.length === 0) {
            unknown.push(token);
            continue;
        }

        for (const avatar of matches) {
            if (!isAvatarOnLan(avatar)) {
                unknown.push(`${token} (not on the LAN yet)`);
                continue;
            }

            if (!ids.includes(avatar.id)) {
                ids.push(avatar.id);
            }
        }
    }

    return { ids, unknown };
}

export interface AvatarPickerOptions {
    input?: NodeJS.ReadStream;
    output?: NodeJS.WriteStream;
    selectAll?: boolean;
    preselected?: string[];
}

/**
 * Minimal ANSI checkbox list. Space toggles, arrows/j/k move, a toggles all, enter confirms,
 * q/escape cancels (no selection). Falls back to a line prompt when stdin is not a TTY.
 */
export async function pickAvatars(avatars: Avatar[], options: AvatarPickerOptions = {}): Promise<string[]> {
    if (options.selectAll) {
        return avatars.filter(isAvatarOnLan).map((avatar) => avatar.id);
    }

    if (options.preselected && options.preselected.length > 0) {
        return avatars
            .filter((avatar) => options.preselected?.includes(avatar.id) && isAvatarOnLan(avatar))
            .map((avatar) => avatar.id);
    }

    const input = options.input ?? process.stdin;
    const output = options.output ?? process.stdout;

    if (!input.isTTY) {
        output.write('Avatar ids (comma separated, "all" for everything, empty for none): ');

        return new Promise<string[]>((resolve) => {
            const rl = createInterface({ input });

            rl.once('line', (line) => {
                const ids = parseAvatarSelection(line, avatars).ids;

                resolve(ids);
                rl.close();
            });
            rl.once('close', () => resolve([]));
        });
    }

    const selected = new Set<string>();
    let cursor = 0;
    let renderedLines = 0;

    const render = (): void => {
        if (renderedLines > 0) {
            output.write(`\u001b[${renderedLines}A`);
        }

        renderedLines = avatars.length + 2;
        output.write('\u001b[2KLANoMAT avatars  (space toggle · a all · enter confirm · q cancel)\n');

        avatars.forEach((avatar, index) => {
            const pointer = index === cursor ? '>' : ' ';
            const line = `${pointer} ${formatAvatarLine(avatar, selected.has(avatar.id))}`;

            output.write(`\u001b[2K${line}\n`);
        });
        output.write(`\u001b[2K${selected.size} selected\n`);
    };

    return new Promise<string[]>((resolve) => {
        const previousRaw = input.isRaw;

        emitKeypressEvents(input);
        input.setRawMode(true);
        input.resume();
        render();

        const onKey = (_str: string, key: { name?: string; ctrl?: boolean; sequence?: string }): void => {
            if (key.ctrl && key.name === 'c') {
                cleanup();
                resolve([]);

                return;
            }

            if (key.name === 'return' || key.name === 'enter') {
                cleanup();
                resolve(avatars.filter((avatar) => selected.has(avatar.id)).map((avatar) => avatar.id));

                return;
            }

            if (key.name === 'q' || key.name === 'escape') {
                cleanup();
                resolve([]);

                return;
            }

            if (key.name === 'up' || key.name === 'k') {
                cursor = (cursor - 1 + avatars.length) % Math.max(1, avatars.length);
            } else if (key.name === 'down' || key.name === 'j') {
                cursor = (cursor + 1) % Math.max(1, avatars.length);
            } else if (key.name === 'space') {
                const avatar = avatars[cursor];

                if (avatar && isAvatarOnLan(avatar)) {
                    if (selected.has(avatar.id)) {
                        selected.delete(avatar.id);
                    } else {
                        selected.add(avatar.id);
                    }
                }
            } else if (key.name === 'a') {
                if (selected.size === avatars.filter(isAvatarOnLan).length) {
                    selected.clear();
                } else {
                    avatars.filter(isAvatarOnLan).forEach((avatar) => selected.add(avatar.id));
                }
            }

            render();
        };

        const cleanup = (): void => {
            input.off('keypress', onKey);
            input.setRawMode(Boolean(previousRaw));
            input.pause();
            output.write('\n');
        };

        input.on('keypress', onKey);
    });
}

export function avatarTasks(
    api: ApiClient,
    avatars: Avatar[],
    selectedIds: string[],
    destDir: string,
): DownloadTask[] {
    return avatars
        .filter((avatar) => selectedIds.includes(avatar.id) && isAvatarOnLan(avatar))
        .map((avatar) => {
            const url = api.resolve(avatar.download_url ?? api.avatarDownloadUrl(avatar.id));

            return {
                url,
                filename: avatarFilename(avatar),
                destDir,
                sha256: avatar.sha256,
                size_bytes: avatar.size_bytes,
                source: 'lan',
                headers: api.headersFor(url),
                label: avatar.label,
            };
        });
}

export interface AvatarDownloadResult {
    manifestPath: string;
    downloaded: string[];
    skipped: string[];
    manifest: AvatarManifest;
}

/**
 * Download selected avatars only from the LANoMAT server (the `download_url` it returned), verify
 * sha256, and record a manifest. Verified existing files are never overwritten.
 */
export async function downloadAvatars(options: {
    api: ApiClient;
    avatars: Avatar[];
    selectedIds: string[];
    destDir: string;
    jobs?: number;
    fetchImpl?: DownloadEngineOptions['fetchImpl'];
    onProgress?: DownloadEngineOptions['onProgress'];
    clock?: () => Date;
}): Promise<AvatarDownloadResult> {
    const clock = options.clock ?? (() => new Date());
    const tasks = avatarTasks(options.api, options.avatars, options.selectedIds, options.destDir);

    await ensureDir(options.destDir, 0o755);

    const results = await downloadMany(tasks, {
        jobs: options.jobs ?? 4,
        fetchImpl: options.fetchImpl,
        onProgress: options.onProgress,
        clock: options.clock ? () => options.clock!().getTime() : undefined,
    });

    const manifestPath = join(options.destDir, 'manifest.json');
    const existing = await readJsonFile<AvatarManifest>(manifestPath).catch(() => null);
    const byId = new Map((existing?.avatars ?? []).map((entry) => [entry.id, entry]));
    const downloaded: string[] = [];
    const skipped: string[] = [];

    for (const result of results) {
        const source = options.avatars.find(
            (entry) => avatarFilename(entry) === result.task.filename || entry.id === result.task.label,
        );
        const id = source?.id ?? result.task.filename;

        byId.set(id, {
            id,
            label: source?.label ?? result.task.label ?? result.task.filename,
            filename: result.task.filename,
            sha256: result.task.sha256,
            size_bytes: result.task.size_bytes,
            author: source?.author,
            license: source?.license,
            download_url: result.task.url,
        });

        if (result.skipped) {
            skipped.push(result.task.filename);
        } else {
            downloaded.push(result.task.filename);
        }
    }

    const manifest: AvatarManifest = {
        source: { url: options.api.baseUrl },
        fetched_at: clock().toISOString(),
        avatars: [...byId.values()].sort((a, b) => a.id.localeCompare(b.id)),
    };

    await writeFileAtomic(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`, 0o644);

    return { manifestPath, downloaded, skipped, manifest };
}

export async function readAvatarManifest(dir: string): Promise<AvatarManifest | null> {
    try {
        return JSON.parse(await readFile(join(dir, 'manifest.json'), 'utf8')) as AvatarManifest;
    } catch {
        return null;
    }
}

export async function writeAvatarManifest(dir: string, manifest: AvatarManifest): Promise<void> {
    await ensureDir(dir, 0o755);
    await writeFile(join(dir, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`, { mode: 0o644 });
}
