import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { Readable } from 'node:stream';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ApiClient } from '../src/http.js';
import type { Avatar } from '../src/types.js';
import {
    avatarFilename,
    downloadAvatars,
    formatAvatarLine,
    isAvatarOnLan,
    parseAvatarSelection,
    pickAvatars,
    readAvatarManifest,
} from '../src/avatars.js';
import { removeDir, tempDir } from './helpers.js';

const payload = Buffer.from('VRM-DATA');
const sha = createHash('sha256').update(payload).digest('hex');

const avatars: Avatar[] = [
    {
        id: 'marcus',
        label: 'Marcus',
        author: 'Epic',
        license: 'CC-BY',
        size_bytes: payload.length,
        sha256: sha,
        download_url: 'http://lan.local/api/v1/avatars/marcus/download',
        match_name: 'Marcus.vrm',
    },
    {
        id: 'missing',
        label: 'Missing',
        author: 'Unknown',
        size_bytes: 100,
    },
    {
        id: 'kane',
        label: 'Kane',
        author: 'Epic',
        size_bytes: 20,
        download_url: 'http://lan.local/api/v1/avatars/kane/download',
    },
];

function fakeApi(): ApiClient {
    return {
        baseUrl: 'http://lan.local',
        resolve: (path: string) => `http://lan.local${path}`,
        avatarDownloadUrl: (id: string) => `http://lan.local/api/v1/avatars/${id}/download`,
        headersFor: () => ({ Authorization: 'Bearer token' }),
    } as unknown as ApiClient;
}

describe('avatars', () => {
    let dir: string;

    beforeEach(async () => {
        dir = await tempDir();
    });

    afterEach(async () => {
        await removeDir(dir);
    });

    it('parses 1-based indexes, ids and labels', () => {
        const parsed = parseAvatarSelection('1, kane', avatars);

        expect(parsed.ids).toEqual(['marcus', 'kane']);
        expect(parsed.unknown).toEqual([]);
    });

    it('marks avatars without a LAN copy as unavailable', () => {
        const parsed = parseAvatarSelection('missing', avatars);

        expect(parsed.ids).toEqual([]);
        expect(parsed.unknown[0]).toContain('not on the LAN yet');
        expect(isAvatarOnLan(avatars[1]!)).toBe(false);
    });

    it('selects every LAN avatar with --all', () => {
        expect(parseAvatarSelection('all', avatars).ids).toEqual(['marcus', 'kane']);
    });

    it('formats a checkbox line with author, license, size and LAN state', () => {
        expect(formatAvatarLine(avatars[0]!, true)).toBe('[x] Marcus (Epic, CC-BY, 8 B)');
        expect(formatAvatarLine(avatars[1]!, false)).toContain('(not on the LAN yet)');
    });

    it('derives a safe .vrm filename', () => {
        expect(avatarFilename(avatars[0]!)).toBe('Marcus.vrm');
        expect(avatarFilename({ id: 'x', label: 'X/Y' })).toBe('x.vrm');
    });

    it('reads a non-TTY selection from a line', async () => {
        const input = Object.assign(Readable.from(['1, 3\n']), { isTTY: false });

        await expect(
            pickAvatars(avatars, {
                input: input as unknown as NodeJS.ReadStream,
                output: { write: () => true } as unknown as NodeJS.WriteStream,
            }),
        ).resolves.toEqual([
            'marcus',
            'kane',
        ]);
    });

    it('downloads avatars, verifies them and writes the manifest', async () => {
        let calls = 0;
        const result = await downloadAvatars({
            api: fakeApi(),
            avatars,
            selectedIds: ['marcus'],
            destDir: dir,
            clock: () => new Date('2026-11-20T10:00:00.000Z'),
            fetchImpl: async () => {
                calls += 1;

                return new Response(payload, {
                    status: 200,
                    headers: { 'content-length': String(payload.length) },
                });
            },
        });

        expect(result.downloaded).toEqual(['Marcus.vrm']);
        expect(calls).toBe(1);
        expect(await readFile(join(dir, 'Marcus.vrm'))).toEqual(payload);
        expect(await readAvatarManifest(dir)).toMatchObject({
            source: { url: 'http://lan.local' },
            fetched_at: '2026-11-20T10:00:00.000Z',
            avatars: [{ id: 'marcus', label: 'Marcus', filename: 'Marcus.vrm' }],
        });
    });

    it('keeps a verified existing avatar without another fetch', async () => {
        await downloadAvatars({
            api: fakeApi(),
            avatars,
            selectedIds: ['marcus'],
            destDir: dir,
            fetchImpl: async () => new Response(payload, { headers: { 'content-length': String(payload.length) } }),
        });
        let calls = 0;
        const second = await downloadAvatars({
            api: fakeApi(),
            avatars,
            selectedIds: ['marcus'],
            destDir: dir,
            fetchImpl: async () => {
                calls += 1;

                return new Response(payload);
            },
        });

        expect(calls).toBe(0);
        expect(second.skipped).toEqual(['Marcus.vrm']);
        expect(second.downloaded).toEqual([]);
    });
});
