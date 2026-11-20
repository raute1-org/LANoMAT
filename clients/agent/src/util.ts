import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';
import { access, chmod, mkdir, readFile, rename, rm, stat, writeFile } from 'node:fs/promises';
import { dirname, isAbsolute, join, resolve } from 'node:path';

export function normalizeBaseUrl(input: string): string {
    const trimmed = input.trim().replace(/\/+$/, '');

    let parsed: URL;

    try {
        parsed = new URL(trimmed);
    } catch {
        throw new Error(`Invalid server URL: ${input}`);
    }

    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
        throw new Error(`Server URL must use http or https: ${input}`);
    }

    return trimmed;
}

export function joinUrl(base: string, path: string): string {
    if (/^https?:\/\//i.test(path)) {
        return path;
    }

    return `${base.replace(/\/+$/, '')}/${path.replace(/^\/+/, '')}`;
}

export function isAbsoluteUrl(value: string): boolean {
    return /^https?:\/\//i.test(value);
}

export function sanitizeName(value: string): string {
    const cleaned = value
        .replace(/[\\/:*?"<>|]/g, '-')
        .replace(/\.\./g, '-')
        .replace(/^\.+/, '')
        .trim();

    return cleaned.length > 0 ? cleaned : 'file';
}

export function sanitizeSlug(value: string): string {
    const cleaned = value
        .toLowerCase()
        .replace(/[^a-z0-9._-]+/g, '-')
        .replace(/^[-.]+|[-.]+$/g, '');

    if (cleaned.length === 0 || cleaned === '.' || cleaned === '..') {
        throw new Error(`Invalid recipe slug: ${value}`);
    }

    return cleaned;
}

export function formatBytes(bytes: number | undefined): string {
    if (bytes === undefined || Number.isNaN(bytes)) {
        return 'unknown size';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value >= 10 ? value.toFixed(0) : value.toFixed(1)} ${units[unit]}`;
}

export function formatSpeed(bytesPerSecond: number | undefined): string {
    return bytesPerSecond === undefined || bytesPerSecond <= 0 ? '—' : `${formatBytes(bytesPerSecond)}/s`;
}

export function isoNow(clock: () => Date = () => new Date()): string {
    return clock().toISOString();
}

export function sleep(ms: number): Promise<void> {
    return new Promise((resolvePromise) => setTimeout(resolvePromise, ms));
}

export async function sha256File(path: string): Promise<string> {
    const hash = createHash('sha256');

    for await (const chunk of createReadStream(path)) {
        hash.update(chunk as Buffer);
    }

    return hash.digest('hex');
}

export async function fileExists(path: string): Promise<boolean> {
    try {
        await access(path);

        return true;
    } catch {
        return false;
    }
}

export async function isDirectory(path: string): Promise<boolean> {
    try {
        return (await stat(path)).isDirectory();
    } catch {
        return false;
    }
}

export async function ensureDir(path: string, mode = 0o700): Promise<void> {
    await mkdir(path, { recursive: true, mode });
    await chmod(path, mode).catch(() => undefined);
}

/** Write through a temp file in the same directory, then rename. No half-written config. */
export async function writeFileAtomic(path: string, data: string, mode = 0o600): Promise<void> {
    await mkdir(dirname(path), { recursive: true, mode: 0o700 });
    const tmp = join(dirname(path), `.${sanitizeName(path.split(/[\\/]/).pop() ?? 'file')}.${process.pid}.tmp`);

    try {
        await writeFile(tmp, data, { mode });
        await chmod(tmp, mode);
        await rename(tmp, path);
        await chmod(path, mode);
    } catch (error) {
        await rm(tmp, { force: true });
        throw error;
    }
}

export async function readJsonFile<T>(path: string): Promise<T | null> {
    try {
        return JSON.parse(await readFile(path, 'utf8')) as T;
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
            return null;
        }

        throw error;
    }
}

export function expandHome(input: string, home: string): string {
    if (input === '~') {
        return home;
    }

    if (input.startsWith('~/') || input.startsWith('~\\')) {
        return join(home, input.slice(2));
    }

    return input;
}

export function resolveUserPath(input: string, cwd = process.cwd(), home?: string): string {
    const expanded = home ? expandHome(input, home) : input;

    return isAbsolute(expanded) ? expanded : resolve(cwd, expanded);
}

export function escapeShellSingleQuotes(value: string): string {
    return value.replace(/'/g, `'\\''`);
}

export function truncateMiddle(value: string, length = 12): string {
    if (value.length <= length) {
        return value;
    }

    const head = Math.ceil(length / 2);
    const tail = Math.floor(length / 2);

    return `${value.slice(0, head)}…${value.slice(-tail)}`;
}

export function platformIsWindows(platform: NodeJS.Platform = process.platform): boolean {
    return platform === 'win32';
}
