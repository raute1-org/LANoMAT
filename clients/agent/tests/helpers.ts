import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export async function tempDir(prefix = 'lanomat-agent-'): Promise<string> {
    return mkdtemp(join(tmpdir(), prefix));
}

export async function removeDir(path: string): Promise<void> {
    await rm(path, { recursive: true, force: true });
}

export function jsonResponse(data: unknown, status = 200): Response {
    return new Response(JSON.stringify(data), {
        status,
        headers: { 'content-type': 'application/json' },
    });
}

export function textResponse(text: string, status = 200, contentType = 'text/markdown'): Response {
    return new Response(text, { status, headers: { 'content-type': contentType } });
}

export async function readJson(path: string): Promise<Record<string, unknown>> {
    const { readFile } = await import('node:fs/promises');

    return JSON.parse(await readFile(path, 'utf8')) as Record<string, unknown>;
}
