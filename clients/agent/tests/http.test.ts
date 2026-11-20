import { describe, expect, it } from 'vitest';
import { ApiClient, redactSecret } from '../src/http.js';
import { jsonResponse } from './helpers.js';

describe('http client', () => {
    it('sends bearer auth only to the LANoMAT origin', () => {
        const api = new ApiClient({ baseUrl: 'http://lan.local', token: 't' });

        expect(api.authHeaders()).toEqual({ Authorization: 'Bearer t' });
        expect(api.headersFor('http://lan.local/api/v1/files/1/download')).toEqual({
            Authorization: 'Bearer t',
        });
        expect(api.headersFor('https://signed.example/file?token=x')).toEqual({});
    });

    it('parses a device token error into a code', async () => {
        const api = new ApiClient({
            baseUrl: 'http://lan.local',
            fetchImpl: async () => jsonResponse({ error: 'authorization_pending' }, 400),
        });

        await expect(api.pollDeviceToken('code')).resolves.toMatchObject({
            status: 'error',
            error: 'authorization_pending',
        });
    });

    it('turns a 401 into a login hint', async () => {
        const api = new ApiClient({
            baseUrl: 'http://lan.local',
            token: 't',
            fetchImpl: async () => jsonResponse({ error: 'unauthorized' }, 401),
        });

        await expect(api.me()).rejects.toThrow('lanomat-agent login');
    });

    it('wraps network failures with the server URL', async () => {
        const api = new ApiClient({
            baseUrl: 'http://lan.local',
            fetchImpl: async () => {
                throw new Error('ECONNREFUSED');
            },
        });

        await expect(api.me()).rejects.toThrow('Cannot reach http://lan.local');
    });

    it('redacts long secrets but leaves short tokens alone', () => {
        expect(redactSecret('x abcdef y', 'abcdef')).toBe('x «redacted» y');
        expect(redactSecret('x abc y', 'abc')).toBe('x abc y');
    });
});
