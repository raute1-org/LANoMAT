import { describe, expect, it } from 'vitest';
import { ApiClient } from '../src/http.js';
import { runDeviceLogin, DeviceFlowError, loginInstructions, deviceName } from '../src/auth.js';
import { jsonResponse } from './helpers.js';

function client(handler: (url: string, init?: RequestInit) => Response): ApiClient {
    return new ApiClient({
        baseUrl: 'http://lan.local',
        fetchImpl: async (input, init) => handler(String(input), init),
    });
}

const device = {
    device_code: 'dev-123',
    user_code: 'ABCD-EFGH',
    verification_uri: 'http://lan.local/device',
    expires_in: 600,
    interval: 1,
};

describe('device flow', () => {
    it('polls through authorization_pending and stores the token expiry', async () => {
        let now = 0;
        const tokenCalls: string[] = [];
        const api = client((url, init) => {
            if (url.endsWith('/api/mcp/device')) {
                expect(JSON.parse(String(init?.body))).toEqual({ device_name: 'Rene-PC' });

                return jsonResponse(device);
            }

            tokenCalls.push(String(init?.body));

            if (tokenCalls.length === 1) {
                return jsonResponse({ error: 'authorization_pending' }, 400);
            }

            return jsonResponse({ access_token: 'access', token_type: 'Bearer', expires_in: 3600 });
        });
        const delays: number[] = [];
        const result = await runDeviceLogin({
            api,
            deviceName: 'Rene-PC',
            onDevice: () => undefined,
            sleep: async (ms) => {
                delays.push(ms);
                now += ms;
            },
            clock: () => new Date(now),
        });

        expect(result.token.access_token).toBe('access');
        expect(result.expiresAt).toBe(new Date(3600_000 + 2000).toISOString());
        expect(delays).toEqual([1000, 1000]);
        expect(tokenCalls).toEqual([
            JSON.stringify({ device_code: 'dev-123' }),
            JSON.stringify({ device_code: 'dev-123' }),
        ]);
    });

    it('honours slow_down by adding five seconds', async () => {
        let now = 0;
        let calls = 0;
        const api = client((url) => {
            if (url.endsWith('/api/mcp/device')) {
                return jsonResponse(device);
            }

            calls += 1;

            return calls === 1
                ? jsonResponse({ error: 'slow_down' }, 400)
                : jsonResponse({ access_token: 'a', token_type: 'Bearer', expires_in: 60 });
        });
        const delays: number[] = [];

        await runDeviceLogin({
            api,
            deviceName: 'PC',
            onDevice: () => undefined,
            sleep: async (ms) => {
                delays.push(ms);
                now += ms;
            },
            clock: () => new Date(now),
        });

        expect(delays).toEqual([1000, 6000]);
    });

    it('treats a 429 as slow_down', async () => {
        let now = 0;
        let calls = 0;
        const api = client((url) => {
            if (url.endsWith('/api/mcp/device')) {
                return jsonResponse(device);
            }

            calls += 1;

            return calls === 1
                ? jsonResponse({ error: 'rate_limited' }, 429)
                : jsonResponse({ access_token: 'a', token_type: 'Bearer', expires_in: 60 });
        });
        const delays: number[] = [];

        await runDeviceLogin({
            api,
            deviceName: 'PC',
            onDevice: () => undefined,
            sleep: async (ms) => {
                delays.push(ms);
                now += ms;
            },
            clock: () => new Date(now),
        });

        expect(delays[1]).toBe(6000);
    });

    it('aborts on expired_token', async () => {
        let now = 0;
        const api = client((url) => {
            if (url.endsWith('/api/mcp/device')) {
                return jsonResponse({ ...device, expires_in: 1 });
            }

            return jsonResponse({ error: 'expired_token' }, 400);
        });

        await expect(
            runDeviceLogin({
                api,
                deviceName: 'PC',
                onDevice: () => undefined,
                sleep: async (ms) => {
                    now += ms;
                },
                clock: () => new Date(now),
            }),
        ).rejects.toMatchObject({ code: 'expired_token' });
    });

    it('aborts on access_denied with a readable message', async () => {
        let now = 0;
        const api = client((url) => {
            if (url.endsWith('/api/mcp/device')) {
                return jsonResponse(device);
            }

            return jsonResponse({ error: 'access_denied' }, 400);
        });

        await expect(
            runDeviceLogin({
                api,
                deviceName: 'PC',
                onDevice: () => undefined,
                sleep: async (ms) => {
                    now += ms;
                },
                clock: () => new Date(now),
            }),
        ).rejects.toBeInstanceOf(DeviceFlowError);
    });

    it('prints the verification URL and code without the token', () => {
        expect(loginInstructions(device)).toContain('http://lan.local/device');
        expect(loginInstructions(device)).toContain('ABCD-EFGH');
        expect(deviceName('pc1')).toContain('pc1');
    });

    it('redacts a token in log output', async () => {
        const { redactSecret } = await import('../src/http.js');

        expect(redactSecret('token abcdef lies here', 'abcdef')).toBe('token «redacted» lies here');
    });
});
