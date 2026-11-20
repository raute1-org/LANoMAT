import { createHash } from 'node:crypto';
import { access, mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { ChecksumError, downloadFile, downloadMany, DownloadSizeError, DownloadTimeoutError } from '../src/download.js';
import { removeDir, tempDir } from './helpers.js';

const payload = Buffer.from('hello world');
const sha = (data: Buffer): string => createHash('sha256').update(data).digest('hex');

function responseFor(data: Buffer, status = 200, range?: string): Response {
    return new Response(data, {
        status,
        headers: {
            'content-length': String(data.length),
            ...(range ? { 'content-range': range } : {}),
        },
    });
}

describe('download engine', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('downloads and verifies sha256', async () => {
        const result = await downloadFile(
            {
                url: 'http://lan.local/file',
                filename: 'game.zip',
                destDir: dir,
                sha256: sha(payload),
            },
            { fetchImpl: async () => responseFor(payload) },
        );

        expect(result.skipped).toBe(false);
        expect(result.bytes).toBe(payload.length);
        expect(await readFile(join(dir, 'game.zip'))).toEqual(payload);
    });

    it('deletes the .part file when the checksum fails', async () => {
        await expect(
            downloadFile(
                {
                    url: 'http://lan.local/file',
                    filename: 'bad.zip',
                    destDir: dir,
                    sha256: 'deadbeef',
                },
                { fetchImpl: async () => responseFor(payload) },
            ),
        ).rejects.toBeInstanceOf(ChecksumError);

        expect(await readdir(dir)).toEqual([]);
        await expect(access(join(dir, 'bad.zip'))).rejects.toThrow();
    });

    it('resumes a .part file with HTTP Range', async () => {
        await writeFile(join(dir, 'game.zip.part'), payload.subarray(0, 6));
        let rangeHeader: string | undefined;
        const result = await downloadFile(
            {
                url: 'http://lan.local/file',
                filename: 'game.zip',
                destDir: dir,
                sha256: sha(payload),
            },
            {
                fetchImpl: async (_url, init) => {
                    rangeHeader = (init?.headers as Record<string, string>).Range;

                    return responseFor(payload.subarray(6), 206, `bytes 6-10/${payload.length}`);
                },
            },
        );

        expect(rangeHeader).toBe('bytes=6-');
        expect(result.resumed).toBe(true);
        expect(await readFile(join(dir, 'game.zip'))).toEqual(payload);
    });

    it('restarts when the server ignores Range and returns 200', async () => {
        await writeFile(join(dir, 'game.zip.part'), Buffer.from('OLD'));
        const result = await downloadFile(
            { url: 'http://lan.local/file', filename: 'game.zip', destDir: dir },
            { fetchImpl: async () => responseFor(payload) },
        );

        expect(result.resumed).toBe(false);
        expect(await readFile(join(dir, 'game.zip'))).toEqual(payload);
    });

    it('never overwrites a verified existing file', async () => {
        await writeFile(join(dir, 'game.zip'), payload);
        let calls = 0;
        const result = await downloadFile(
            {
                url: 'http://lan.local/file',
                filename: 'game.zip',
                destDir: dir,
                sha256: sha(payload),
            },
            {
                fetchImpl: async () => {
                    calls += 1;

                    return responseFor(Buffer.from('other'));
                },
            },
        );

        expect(result.skipped).toBe(true);
        expect(calls).toBe(0);
        expect(await readFile(join(dir, 'game.zip'))).toEqual(payload);
    });

    it('caps parallel downloads with jobs', async () => {
        let active = 0;
        let maxActive = 0;
        const tasks = Array.from({ length: 6 }, (_, index) => ({
            url: `http://lan.local/${index}`,
            filename: `f${index}.bin`,
            destDir: dir,
        }));

        await downloadMany(tasks, {
            jobs: 2,
            fetchImpl: async () => {
                active += 1;
                maxActive = Math.max(maxActive, active);
                await new Promise((resolve) => setTimeout(resolve, 5));
                active -= 1;

                return responseFor(Buffer.from('x'));
            },
        });

        expect(maxActive).toBe(2);
        expect((await readdir(dir)).sort()).toEqual(
            Array.from({ length: 6 }, (_, index) => `f${index}.bin`).sort(),
        );
    });

    it('creates the destination directory', async () => {
        const nested = join(dir, 'a', 'b');

        await mkdir(join(dir, 'a'), { recursive: true });
        await downloadFile(
            { url: 'http://lan.local/file', filename: 'x.bin', destDir: nested },
            { fetchImpl: async () => responseFor(Buffer.from('x')) },
        );

        expect(await readFile(join(nested, 'x.bin'), 'utf8')).toBe('x');
    });

    it('aborts a stalled body after the idle timeout and keeps the .part file', async () => {
        await expect(
            downloadFile(
                { url: 'http://lan.local/stalled.zip', filename: 'stalled.zip', destDir: dir },
                {
                    timeoutMs: 30,
                    fetchImpl: async (_url, init) => {
                        const stream = new ReadableStream<Uint8Array>({
                            start(controller) {
                                init?.signal?.addEventListener('abort', () =>
                                    controller.error(new Error('aborted')),
                                );
                            },
                        });

                        return new Response(stream, { headers: { 'content-length': '100' } });
                    },
                },
            ),
        ).rejects.toBeInstanceOf(DownloadTimeoutError);

        expect(await readdir(dir)).toEqual(['stalled.zip.part']);
        await expect(access(join(dir, 'stalled.zip'))).rejects.toThrow();
    });

    it('aborts when the server never sends response headers', async () => {
        const started = Date.now();

        await expect(
            downloadFile(
                { url: 'http://lan.local/slow.zip', filename: 'slow.zip', destDir: dir },
                {
                    timeoutMs: 30,
                    fetchImpl: async (_url, init) =>
                        new Promise<Response>((_resolve, reject) => {
                            init?.signal?.addEventListener('abort', () => reject(new Error('aborted')));
                        }),
                },
            ),
        ).rejects.toBeInstanceOf(DownloadTimeoutError);

        expect(Date.now() - started).toBeLessThan(2_000);
    });

    it('passes an abort signal to every request', async () => {
        const signals: Array<AbortSignal | undefined> = [];

        await downloadFile(
            { url: 'http://lan.local/file', filename: 'signal.bin', destDir: dir },
            {
                timeoutMs: 5_000,
                fetchImpl: async (_url, init) => {
                    signals.push(init?.signal ?? undefined);

                    return responseFor(Buffer.from('x'));
                },
            },
        );

        expect(signals).toHaveLength(1);
        expect(signals[0]).toBeInstanceOf(AbortSignal);
        expect(signals[0]?.aborted).toBe(false);
    });

    it('enforces task.size_bytes mid-stream and keeps the .part file', async () => {
        const big = Buffer.alloc(1024 * 1024, 1);

        await expect(
            downloadFile(
                { url: 'http://lan.local/capped.bin', filename: 'capped.bin', destDir: dir, size_bytes: 10 },
                { fetchImpl: async () => new Response(big) },
            ),
        ).rejects.toBeInstanceOf(DownloadSizeError);

        await expect(access(join(dir, 'capped.bin'))).rejects.toThrow();
        expect(await readdir(dir)).toEqual(['capped.bin.part']);
    });

    it('enforces an absolute maxBytes cap', async () => {
        await expect(
            downloadFile(
                { url: 'http://lan.local/capped.bin', filename: 'capped.bin', destDir: dir },
                {
                    maxBytes: 10,
                    fetchImpl: async () =>
                        new Response(Buffer.alloc(4096, 2), {
                            headers: { 'content-length': '4096' },
                        }),
                },
            ),
        ).rejects.toBeInstanceOf(DownloadSizeError);

        await expect(access(join(dir, 'capped.bin'))).rejects.toThrow();
    });

    it('rejects an announced content-length above the limit before writing', async () => {
        await expect(
            downloadFile(
                { url: 'http://lan.local/capped.bin', filename: 'capped.bin', destDir: dir, size_bytes: 10 },
                {
                    fetchImpl: async () =>
                        new Response(Buffer.from('12345'), {
                            headers: { 'content-length': '100' },
                        }),
                },
            ),
        ).rejects.toBeInstanceOf(DownloadSizeError);

        await expect(access(join(dir, 'capped.bin'))).rejects.toThrow();
    });

    it('discards a .part that is already larger than the limit', async () => {
        await writeFile(join(dir, 'capped.bin.part'), Buffer.alloc(20));

        const result = await downloadFile(
            { url: 'http://lan.local/capped.bin', filename: 'capped.bin', destDir: dir, size_bytes: 10 },
            { fetchImpl: async () => new Response(Buffer.from('12345')) },
        );

        expect(result.resumed).toBe(false);
        expect(result.bytes).toBe(5);
        expect(await readFile(join(dir, 'capped.bin'), 'utf8')).toBe('12345');
    });

    it('arms the watchdog only after a resumed .part is hashed', async () => {
        await writeFile(join(dir, 'resume.bin.part'), Buffer.from('abcd'));
        let abortedAtRequest: boolean | null = null;

        const result = await downloadFile(
            { url: 'http://lan.local/resume.bin', filename: 'resume.bin', destDir: dir, size_bytes: 8 },
            {
                timeoutMs: 40,
                hashPart: async () => {
                    await new Promise((resolve) => setTimeout(resolve, 120));
                },
                fetchImpl: async (_url, init) => {
                    abortedAtRequest = init?.signal?.aborted ?? null;

                    return new Response(Buffer.from('xyzw'), {
                        status: 206,
                        headers: { 'content-length': '4' },
                    });
                },
            },
        );

        expect(abortedAtRequest).toBe(false);
        expect(result.resumed).toBe(true);
        expect(await readFile(join(dir, 'resume.bin'), 'utf8')).toBe('abcdxyzw');
    });
});
