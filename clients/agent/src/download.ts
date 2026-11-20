import { createHash } from 'node:crypto';
import { createReadStream, createWriteStream } from 'node:fs';
import { rename, rm, stat } from 'node:fs/promises';
import { once } from 'node:events';
import { join } from 'node:path';
import type { DownloadProgress, DownloadResult, DownloadTask } from './types.js';
import type { FetchLike } from './http.js';
import { ensureDir, sanitizeName, sha256File } from './util.js';

export class ChecksumError extends Error {
    readonly path: string;
    readonly expected: string;
    readonly actual: string;

    constructor(path: string, expected: string, actual: string) {
        super(`SHA-256 mismatch for ${path}: expected ${expected}, got ${actual}`);
        this.name = 'ChecksumError';
        this.path = path;
        this.expected = expected;
        this.actual = actual;
    }
}

export class DownloadTimeoutError extends Error {
    readonly task: DownloadTask;
    readonly timeoutMs: number;

    constructor(task: DownloadTask, timeoutMs: number) {
        super(`Download stalled for ${Math.round(timeoutMs / 1000)}s: ${task.filename}`);
        this.name = 'DownloadTimeoutError';
        this.task = task;
        this.timeoutMs = timeoutMs;
    }
}

export class DownloadError extends Error {
    readonly task: DownloadTask;

    constructor(task: DownloadTask, cause: Error) {
        super(`Download failed for ${task.filename}: ${cause.message}`);
        this.name = 'DownloadError';
        this.task = task;
        this.cause = cause;
    }
}

export interface DownloadEngineOptions {
    fetchImpl?: FetchLike;
    onProgress?: (progress: DownloadProgress) => void;
    clock?: () => number;
    /** Delay before the single no-Range retry after a 416. */
    sleep?: (ms: number) => Promise<void>;
    hashFile?: (path: string) => Promise<string>;
    /** Abort when no bytes arrive for this long. Defaults to 60 s; 0 disables the watchdog. */
    timeoutMs?: number;
}

const DEFAULT_DOWNLOAD_TIMEOUT_MS = 60_000;

async function existingSize(path: string): Promise<number> {
    try {
        return (await stat(path)).size;
    } catch {
        return 0;
    }
}

async function updateHashFromFile(hash: ReturnType<typeof createHash>, path: string): Promise<void> {
    for await (const chunk of createReadStream(path)) {
        hash.update(chunk as Buffer);
    }
}

/**
 * Resume-safe single download. The final file only appears after the checksum passes; a failed
 * or stalled download leaves `<file>.part` behind for the next attempt.
 */
export async function downloadFile(
    task: DownloadTask,
    options: DownloadEngineOptions = {},
): Promise<DownloadResult> {
    const fetchImpl = options.fetchImpl ?? fetch;
    const clock = options.clock ?? (() => Date.now());
    const hashFile = options.hashFile ?? sha256File;
    const destDir = task.destDir;
    const finalPath = join(destDir, sanitizeName(task.filename));
    const partPath = `${finalPath}.part`;
    const timeoutMs = options.timeoutMs ?? DEFAULT_DOWNLOAD_TIMEOUT_MS;
    const controller = new AbortController();
    let watchdog: NodeJS.Timeout | undefined;

    // Idle watchdog: every received chunk re-arms it, so only a stalled transfer aborts.
    const arm = (): void => {
        if (timeoutMs <= 0) {
            return;
        }

        if (watchdog) {
            clearTimeout(watchdog);
        }

        watchdog = setTimeout(() => controller.abort(), timeoutMs);
        watchdog.unref?.();
    };
    const disarm = (): void => {
        if (watchdog) {
            clearTimeout(watchdog);
            watchdog = undefined;
        }
    };

    arm();

    try {
        await ensureDir(destDir, 0o755);

        const finalSize = await existingSize(finalPath);

        if (finalSize > 0) {
            if (task.sha256) {
                const actual = await hashFile(finalPath);

                if (actual.toLowerCase() === task.sha256.toLowerCase()) {
                    options.onProgress?.({
                        filename: task.filename,
                        received: finalSize,
                        total: finalSize,
                        percent: 100,
                        bytesPerSecond: 0,
                    });

                    return { task, path: finalPath, bytes: finalSize, skipped: true, resumed: false };
                }
            } else if (task.size_bytes === undefined || finalSize === task.size_bytes) {
                return { task, path: finalPath, bytes: finalSize, skipped: true, resumed: false };
            }
        }

        let partSize = await existingSize(partPath);
        let resumed = partSize > 0;
        let response = await request(task, fetchImpl, partSize, controller.signal);
        let append = resumed;

        if (response.status === 416 && partSize > 0) {
            await rm(partPath, { force: true });
            partSize = 0;
            resumed = false;
            append = false;
            arm();
            response = await request(task, fetchImpl, 0, controller.signal);

            if (response.status === 416) {
                throw new Error('Server rejected the download range');
            }
        }

        if (!response.ok) {
            throw new Error(`HTTP ${response.status} ${response.statusText}`.trim());
        }

        if (response.status === 200 && partSize > 0) {
            partSize = 0;
            resumed = false;
            append = false;
        }

        if (response.status !== 206 && response.status !== 200) {
            throw new Error(`Unexpected HTTP status ${response.status}`);
        }

        const body = response.body;

        if (!body) {
            throw new Error('Empty response body');
        }

        const contentLength = Number(response.headers.get('content-length') ?? '0');
        const total = contentLength > 0 ? partSize + contentLength : task.size_bytes;
        const hash = createHash('sha256');

        if (partSize > 0) {
            await updateHashFromFile(hash, partPath);
        }

        const stream = createWriteStream(partPath, { flags: append ? 'a' : 'w' });
        const started = clock();
        const startedBytes = partSize;
        let received = partSize;
        let lastReport = 0;

        try {
            for await (const chunk of body as unknown as AsyncIterable<Uint8Array>) {
                const buffer = Buffer.from(chunk);

                arm();
                hash.update(buffer);
                received += buffer.length;

                if (!stream.write(buffer)) {
                    await once(stream, 'drain');
                }

                const now = clock();

                if (options.onProgress && (now - lastReport > 150 || (total !== undefined && received >= total))) {
                    lastReport = now;
                    const elapsed = Math.max(1, now - started) / 1000;

                    options.onProgress({
                        filename: task.filename,
                        received,
                        total,
                        percent: total ? Math.min(100, Math.floor((received / total) * 100)) : undefined,
                        bytesPerSecond: (received - startedBytes) / elapsed,
                    });
                }
            }

            stream.end();
            await once(stream, 'finish');
        } catch (error) {
            stream.destroy();
            throw error;
        }

        const actual = hash.digest('hex');

        if (task.sha256 && actual.toLowerCase() !== task.sha256.toLowerCase()) {
            await rm(partPath, { force: true });
            throw new ChecksumError(finalPath, task.sha256, actual);
        }

        await rename(partPath, finalPath);

        options.onProgress?.({
            filename: task.filename,
            received,
            total: total ?? received,
            percent: 100,
            bytesPerSecond: 0,
        });

        return { task, path: finalPath, bytes: received, skipped: false, resumed };
    } catch (error) {
        if (controller.signal.aborted) {
            throw new DownloadTimeoutError(task, timeoutMs);
        }

        throw error;
    } finally {
        disarm();
    }
}

async function request(
    task: DownloadTask,
    fetchImpl: FetchLike,
    offset: number,
    signal?: AbortSignal,
): Promise<Response> {
    const headers: Record<string, string> = { ...(task.headers ?? {}) };

    if (offset > 0) {
        headers.Range = `bytes=${offset}-`;
    }

    return fetchImpl(task.url, { headers, redirect: 'follow', ...(signal ? { signal } : {}) });
}

export interface DownloadManyResult {
    results: DownloadResult[];
    failures: Array<{ task: DownloadTask; error: Error }>;
}

export class DownloadManyError extends Error {
    readonly result: DownloadManyResult;

    constructor(result: DownloadManyResult) {
        super(
            `${result.failures.length} download(s) failed: ${result.failures
                .map((failure) => failure.task.filename)
                .join(', ')}`,
        );
        this.name = 'DownloadManyError';
        this.result = result;
    }
}

/**
 * Small worker pool. `--jobs` caps how many files are fetched at once; every worker stops picking
 * new work after the first failure, but in-flight downloads finish so their result is reported.
 */
export async function downloadMany(
    tasks: DownloadTask[],
    options: DownloadEngineOptions & { jobs?: number; failFast?: boolean } = {},
): Promise<DownloadResult[]> {
    const jobs = Math.max(1, Math.min(options.jobs ?? 4, tasks.length || 1));
    const queue = [...tasks];
    const results: DownloadResult[] = [];
    const failures: Array<{ task: DownloadTask; error: Error }> = [];
    let stopped = false;

    const worker = async (): Promise<void> => {
        for (;;) {
            if (stopped && options.failFast !== false) {
                return;
            }

            const task = queue.shift();

            if (!task) {
                return;
            }

            try {
                results.push(await downloadFile(task, options));
            } catch (error) {
                failures.push({ task, error: error as Error });
                stopped = true;
            }
        }
    };

    await Promise.all(Array.from({ length: jobs }, () => worker()));

    if (failures.length > 0) {
        throw new DownloadManyError({ results, failures });
    }

    return results;
}
